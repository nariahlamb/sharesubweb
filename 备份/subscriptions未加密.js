document.addEventListener('DOMContentLoaded', function () {
    if (typeof WOW !== 'undefined') {
        new WOW().init();
    } else {
        console.error('WOW.js 未正确加载');
    }

    let itemsPerPage = 5;
    let currentPage = 1;
    let subscriptionsData = [];
    let filteredSubscriptions = [];
    let sortDirection = 1;
    let currentSortField = null;
    let userUUID = '';

    const POW_DIFFICULTY = 4;

    let selectedSubscriptions = [];

    function showLoading() {
        const loadingIndicator = document.getElementById('loading-indicator');
        if (loadingIndicator) {
            loadingIndicator.style.display = 'block';
        }
    }

    function hideLoading() {
        const loadingIndicator = document.getElementById('loading-indicator');
        if (loadingIndicator) {
            loadingIndicator.style.display = 'none';
        }
    }

    async function sha256(message) {
        const msgBuffer = new TextEncoder().encode(message);
        const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        return hashHex;
    }

    async function computeNonce(timestamp, difficulty) {
        let nonce = 0;
        const prefix = '0'.repeat(difficulty);
        while (true) {
            const hash = await sha256(timestamp.toString() + nonce.toString());
            if (hash.startsWith(prefix)) {
                return nonce;
            }
            nonce++;
            if (nonce % 10000 === 0) {
                await new Promise(resolve => setTimeout(resolve, 0));
            }
        }
    }

    function getCurrentTimestamp() {
        return Math.floor(Date.now() / 1000);
    }

    async function fetchSubscriptionsWithPoW() {
        showLoading();
        try {
            const timestamp = getCurrentTimestamp();
            const nonce = await computeNonce(timestamp, POW_DIFFICULTY);
            const url = `/subscriptions.php?timestamp=${timestamp}&nonce=${nonce}`;
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'include'
            });
            if (!response.ok) {
                throw new Error(`网络响应失败: ${response.status} ${response.statusText}`);
            }

            const data = await response.json();
            if (!data || !data.username || !Array.isArray(data.subscriptions)) {
                throw new Error('API 返回的数据结构无效');
            }

            const welcomeUser = document.getElementById('welcomeUser');
            if (welcomeUser) {
                welcomeUser.textContent = `欢迎, ${data.username}`;
            }

            subscriptionsData = data.subscriptions;

            if (subscriptionsData.length > 0 && subscriptionsData[0].proxy_link) {
                try {
                    const urlObj = new URL(subscriptionsData[0].proxy_link);
                    userUUID = urlObj.searchParams.get('uuid') || '';
                } catch (e) {
                    console.error('解析 proxy_link 时出错:', e);
                }
            }

            const nodeCount = document.getElementById('node-count');
            if (nodeCount) {
                nodeCount.innerText = `目前有 ${subscriptionsData.length} 个节点`;
            }

            filteredSubscriptions = subscriptionsData;

            renderTable(currentPage);
            renderPagination();
        } catch (error) {
            console.error('获取订阅数据时出错:', error);
            alert('获取订阅数据时发生错误，请稍后再试');
        } finally {
            hideLoading();
        }
    }

    function calculateRemainingDays(expirationDate) {
        if (!expirationDate || expirationDate.toLowerCase() === 'null') {
            return '永久有效';
        }
        const today = new Date();
        const expiration = new Date(expirationDate);
        const timeDiff = expiration - today;
        return timeDiff > 0 ? Math.ceil(timeDiff / (1000 * 60 * 60 * 24)) : '已过期';
    }

    window.sortTable = function (field) {
        if (currentSortField === field) {
            sortDirection = -sortDirection;
        } else {
            currentSortField = field;
            sortDirection = 1;
        }

        document.querySelectorAll('thead th i').forEach(icon => {
            icon.classList.remove('fa-sort-up', 'fa-sort-down');
            icon.classList.add('fa-sort');
        });

        const icon = document.getElementById(`sort-icon-${field}`);
        if (icon) {
            icon.classList.remove('fa-sort');
            icon.classList.add(sortDirection === 1 ? 'fa-sort-up' : 'fa-sort-down');
        }

        filteredSubscriptions.sort((a, b) => {
            if (field === 'remaining_days') {
                const aDays = calculateRemainingDays(a.expiration_date);
                const bDays = calculateRemainingDays(b.expiration_date);
                if (aDays === '永久有效' && bDays === '永久有效') return 0;
                if (aDays === '永久有效') return 1 * sortDirection;
                if (bDays === '永久有效') return -1 * sortDirection;
                return sortDirection * (aDays - bDays);
            }
            if (typeof a[field] === "string") {
                return sortDirection * a[field].localeCompare(b[field], 'zh-CN');
            } else {
                return sortDirection * (a[field] - b[field]);
            }
        });
        currentPage = 1;
        renderTable(currentPage);
        renderPagination();
    }

    function renderTable(page) {
        const dataToRender = filteredSubscriptions.length ? filteredSubscriptions : subscriptionsData;
        const startIndex = (page - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        const paginatedData = dataToRender.slice(startIndex, endIndex);

        const tbody = document.getElementById('subscriptions-body');
        if (!tbody) return;
        tbody.innerHTML = '';

        paginatedData.forEach(sub => {
            const remainingDays = calculateRemainingDays(sub.expiration_date);
            const tr = document.createElement('tr');
            tr.className = 'animate__animated animate__fadeInUp';
            tr.innerHTML = `
                <td><input type="checkbox" class="select-subscription" data-id="${sub.id}" ${selectedSubscriptions.includes(sub.id.toString()) ? 'checked' : ''}></td>
                <td>${escapeHtml(sub.name)}</td>
                <td>${escapeHtml(sub.username)}</td>
                <td>${sub.weekly_subs}</td>
                <td>${sub.available_traffic}</td>
                <td>${remainingDays}</td>
                <td><button class="btn btn-info btn-sm" data-bs-toggle="collapse" data-bs-target="#details-${sub.id}">详情 <i class="fa-solid fa-chevron-down"></i></button></td>
            `;
            tbody.appendChild(tr);

            const detailsRow = document.createElement('tr');
            detailsRow.id = `details-${sub.id}`;
            detailsRow.className = 'collapse details-row animate__animated animate__fadeIn';
            detailsRow.innerHTML = `
                <td colspan="7">
                    <strong>来源:</strong> ${escapeHtml(sub.source)}<br>
                    <strong>备注:</strong> ${escapeHtml(sub.remark)}<br>
                    <strong>处理后链接:</strong>
                    <input type="text" class="form-control d-inline-block w-75" value="${escapeHtml(sub.proxy_link)}" readonly>
                    <button class="btn btn-secondary btn-sm copy-btn" data-clipboard-text="${escapeHtml(sub.proxy_link)}">复制</button>
                    <br><strong>过期时间:</strong> ${sub.expiration_date || '永久有效'}
                </td>
            `;
            tbody.appendChild(detailsRow);
        });

        const clipboard = new ClipboardJS('.copy-btn');
        clipboard.on('success', function() {
            showCopySuccessMessage();
        });

        const selectAllCheckbox = document.getElementById('select-all');
        if (selectAllCheckbox) {
            const allCheckboxes = document.querySelectorAll('.select-subscription');
            const allChecked = Array.from(allCheckboxes).length > 0 && Array.from(allCheckboxes).every(checkbox => checkbox.checked);
            selectAllCheckbox.checked = allChecked;
        }
    }

    function showCopySuccessMessage() {
        const copySuccess = document.getElementById('copy-success');
        if (copySuccess) {
            copySuccess.style.display = 'block';
            setTimeout(() => {
                copySuccess.style.display = 'none';
            }, 2000);
        }
    }

    function renderPagination() {
        const dataToPaginate = filteredSubscriptions.length ? filteredSubscriptions : subscriptionsData;
        const totalPages = Math.ceil(dataToPaginate.length / itemsPerPage);
        const pagination = document.getElementById('pagination');
        if (!pagination) return;
        pagination.innerHTML = '';

        if (totalPages === 0) return;

        const maxVisiblePages = 5;
        let startPage = Math.max(currentPage - Math.floor(maxVisiblePages / 2), 1);
        let endPage = startPage + maxVisiblePages - 1;

        if (endPage > totalPages) {
            endPage = totalPages;
            startPage = Math.max(endPage - maxVisiblePages + 1, 1);
        }

        const prevLi = document.createElement('li');
        prevLi.className = 'page-item' + (currentPage === 1 ? ' disabled' : '');
        prevLi.innerHTML = `<a class="page-link" href="#">«</a>`;
        prevLi.addEventListener('click', function (e) {
            e.preventDefault();
            if (currentPage > 1) {
                currentPage--;
                renderTable(currentPage);
                renderPagination();
            }
        });
        pagination.appendChild(prevLi);

        if (startPage > 1) {
            pagination.appendChild(createPageItem(1));
            if (startPage > 2) {
                const dotsLi = document.createElement('li');
                dotsLi.className = 'page-item disabled';
                dotsLi.innerHTML = `<span class="page-link">...</span>`;
                pagination.appendChild(dotsLi);
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            pagination.appendChild(createPageItem(i));
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const dotsLi = document.createElement('li');
                dotsLi.className = 'page-item disabled';
                dotsLi.innerHTML = `<span class="page-link">...</span>`;
                pagination.appendChild(dotsLi);
            }
            pagination.appendChild(createPageItem(totalPages));
        }

        const nextLi = document.createElement('li');
        nextLi.className = 'page-item' + (currentPage === totalPages ? ' disabled' : '');
        nextLi.innerHTML = `<a class="page-link" href="#">»</a>`;
        nextLi.addEventListener('click', function (e) {
            e.preventDefault();
            if (currentPage < totalPages) {
                currentPage++;
                renderTable(currentPage);
                renderPagination();
            }
        });
        pagination.appendChild(nextLi);

        function createPageItem(page) {
            const li = document.createElement('li');
            li.className = 'page-item' + (page === currentPage ? ' active' : '');
            li.innerHTML = `<a class="page-link" href="#">${page}</a>`;
            li.addEventListener('click', function (e) {
                e.preventDefault();
                currentPage = page;
                renderTable(currentPage);
                renderPagination();
            });
            return li;
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, "&amp;")
                  .replace(/</g, "&lt;")
                  .replace(/>/g, "&gt;")
                  .replace(/"/g, "&quot;")
                  .replace(/'/g, "&#039;");
    }

    const itemsPerPageSelect = document.getElementById('itemsPerPageSelect');
    if (itemsPerPageSelect) {
        itemsPerPageSelect.addEventListener('change', function () {
            itemsPerPage = parseInt(this.value);
            currentPage = 1;
            renderTable(currentPage);
            renderPagination();
        });
    }

    function updateSelectedSubscriptionsDisplay() {
        const list = document.getElementById('selected-subscriptions-list');
        if (!list) return;
        list.innerHTML = '';
        selectedSubscriptions.forEach(subId => {
            const sub = subscriptionsData.find(s => s.id == subId);
            if (sub) {
                const li = document.createElement('li');
                li.innerHTML = `${sub.name} <span class="remove-subscription" data-id="${sub.id}">×</span>`;
                list.appendChild(li);
            }
        });
    }

    const selectedSubscriptionsList = document.getElementById('selected-subscriptions-list');
    if (selectedSubscriptionsList) {
        selectedSubscriptionsList.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-subscription')) {
                const subId = e.target.getAttribute('data-id');
                selectedSubscriptions = selectedSubscriptions.filter(id => id !== subId);
                const checkbox = document.querySelector(`.select-subscription[data-id="${subId}"]`);
                if (checkbox) {
                    checkbox.checked = false;
                }
                updateSelectedSubscriptionsDisplay();
            }
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('select-subscription')) {
            const subId = e.target.getAttribute('data-id');
            if (e.target.checked) {
                if (!selectedSubscriptions.includes(subId)) {
                    selectedSubscriptions.push(subId);
                }
            } else {
                selectedSubscriptions = selectedSubscriptions.filter(id => id !== subId);
            }
            updateSelectedSubscriptionsDisplay();
        } else if (e.target.id === 'select-all') {
            const isChecked = e.target.checked;
            const allCheckboxes = document.querySelectorAll('.select-subscription');
            if (isChecked) {
                allCheckboxes.forEach(checkbox => {
                    const subId = checkbox.getAttribute('data-id');
                    checkbox.checked = true;
                    if (!selectedSubscriptions.includes(subId)) {
                        selectedSubscriptions.push(subId);
                    }
                });
            } else {
                allCheckboxes.forEach(checkbox => {
                    const subId = checkbox.getAttribute('data-id');
                    checkbox.checked = false;
                    selectedSubscriptions = selectedSubscriptions.filter(id => id !== subId);
                });
            }
            updateSelectedSubscriptionsDisplay();
        }
    });

    function generateLink(type) {
        if (!userUUID) {
            alert('用户 UUID 未获取到，无法生成链接。');
            return;
        }

        const targetRadios = document.querySelectorAll('.target-radio:checked');
        if (targetRadios.length === 0) {
            alert('请根据您的软件选择一个模式。');
            return;
        }

        const selectedTarget = targetRadios[0].value;

        let sid;
        if (type === 'selected') {
            sid = selectedSubscriptions.length > 0 ? selectedSubscriptions.join(',') : 'all';
        } else if (type === 'all') {
            sid = 'all';
        }

        const proxyURL = `https://share.lzf.email/proxy.php?uuid=${encodeURIComponent(userUUID)}&sid=${encodeURIComponent(sid)}&target=${selectedTarget}`;

        navigator.clipboard.writeText(proxyURL).then(function() {
            showCopySuccessMessage();
        }, function(err) {
            console.error('复制失败:', err);
            alert('复制失败，请手动复制链接。');
        });

        const selectedCount = type === 'selected' ? selectedSubscriptions.length : subscriptionsData.length;
        alert(`聚合链接已生成并复制到剪贴板！您已选择 ${selectedCount} 个订阅。`);

        const generatedLink = document.getElementById('generated-link');
        const generatedLinkContainer = document.getElementById('generated-link-container');
        if (generatedLink && generatedLinkContainer) {
            generatedLink.value = proxyURL;
            generatedLinkContainer.style.display = 'block';
        }

        const namesList = document.getElementById('selected-subscriptions-names');
        if (namesList) {
            namesList.innerHTML = '';
            if (type === 'selected' && selectedSubscriptions.length > 0) {
                selectedSubscriptions.forEach(subId => {
                    const sub = subscriptionsData.find(s => s.id == subId);
                    if (sub) {
                        const li = document.createElement('li');
                        li.className = 'list-group-item';
                        li.textContent = sub.name;
                        namesList.appendChild(li);
                    }
                });
            } else {
                const li = document.createElement('li');
                li.className = 'list-group-item';
                li.textContent = '全部订阅';
                namesList.appendChild(li);
            }
        }
    }

    const generateSelectedLinkBtn = document.getElementById('generate-selected-link-btn');
    const generateAllLinkBtn = document.getElementById('generate-all-link-btn');
    if (generateSelectedLinkBtn) {
        generateSelectedLinkBtn.addEventListener('click', function () {
            generateLink('selected');
        });
    }
    if (generateAllLinkBtn) {
        generateAllLinkBtn.addEventListener('click', function () {
            generateLink('all');
        });
    }

    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('input', function (e) {
            const keyword = e.target.value.toLowerCase();
            filteredSubscriptions = subscriptionsData.filter(sub => {
                return (
                    sub.name.toLowerCase().includes(keyword) ||
                    sub.username.toLowerCase().includes(keyword) ||
                    (sub.source && sub.source.toLowerCase().includes(keyword)) ||
                    (sub.remark && sub.remark.toLowerCase().includes(keyword))
                );
            });
            currentPage = 1;
            renderTable(currentPage);
            renderPagination();
        });
    }

    fetchSubscriptionsWithPoW();
});
