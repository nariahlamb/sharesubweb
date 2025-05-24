<?php
// admin_subscriptions.php

require_once 'utils.php';
checkAdminAuth();
$pdo = getDbConnection();

// 每页显示的订阅数量
$perPage = 10; // 您可以根据需要调整此值

// 当前页码
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) {
    $page = 1;
}

// 搜索功能
$search = $_GET['search'] ?? '';
$searchQuery = '';
$params = [];

if ($search !== '') {
    // 添加 ID 搜索条件
    $searchQuery = 'WHERE s.id LIKE ? OR s.name LIKE ? OR u.username LIKE ?';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// 获取总订阅数量
$countStmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM subscriptions s
    JOIN users u ON s.created_by = u.id
    $searchQuery
");
$countStmt->execute($params);
$totalSubscriptions = $countStmt->fetchColumn();

// 计算总页数
$totalPages = ceil($totalSubscriptions / $perPage);

// 计算偏移量
$offset = ($page - 1) * $perPage;

// 获取当前页的订阅列表
$stmt = $pdo->prepare("
    SELECT s.*, u.username
    FROM subscriptions s
    JOIN users u ON s.created_by = u.id
    $searchQuery
    ORDER BY s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$subscriptions = $stmt->fetchAll();

require 'templates/header.php';
?>

<h2>订阅管理 (共 <?php echo $totalSubscriptions; ?> 条订阅)
    <!-- 添加返回管理员主页的按钮 -->
    <a href="admin_dashboard.php" class="btn btn-secondary float-right">返回主页</a>
</h2>

<form method="get" action="admin_subscriptions.php" class="form-inline mb-3">
    <input type="text" name="search" class="form-control mr-2"
           placeholder="搜索ID、订阅名称或用户名" value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit" class="btn btn-primary">搜索</button>
    <a href="admin_subscriptions.php" class="btn btn-secondary ml-2">重置</a>
    <button type="button" id="batch-check" class="btn btn-info ml-2">
        批量检测订阅可用性
    </button>
</form>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>ID</th>
            <th>订阅名称</th>
            <th>提供用户</th>
            <th>可用流量 (G)</th>
            <th>总流量使用 (G)</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($subscriptions as $sub): ?>
            <tr>
                <td><?php echo htmlspecialchars($sub['id']); ?></td>
                <td><?php echo htmlspecialchars($sub['name']); ?></td>
                <td><?php echo htmlspecialchars($sub['username']); ?></td>
                <td><?php echo floatval($sub['available_traffic']); ?></td>
                <td><?php echo floatval($sub['total_traffic']); ?></td>
                <td>
                    <button class="btn btn-info btn-sm check-sub"
                            data-id="<?php echo $sub['id']; ?>">
                        检测
                    </button>
                    <button class="btn btn-danger btn-sm delete-sub"
                            data-id="<?php echo $sub['id']; ?>">
                        删除
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 分页导航 -->
<nav>
    <ul class="pagination">
        <?php if ($page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">上一页</a>
            </li>
        <?php endif; ?>

        <?php
        // 控制页码显示数量
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        for ($i = $startPage; $i <= $endPage; $i++):
            if ($i == $page):
        ?>
            <li class="page-item active">
                <a class="page-link" href="#"><?php echo $i; ?></a>
            </li>
        <?php else: ?>
            <li class="page-item">
                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
            </li>
        <?php
            endif;
        endfor;
        ?>

        <?php if ($page < $totalPages): ?>
            <li class="page-item">
                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">下一页</a>
            </li>
        <?php endif; ?>
    </ul>
</nav>

<!-- 批量检测结果的模态框 -->
<div class="modal fade" id="batchResultModal" tabindex="-1" role="dialog"
     aria-labelledby="batchResultModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
            批量检测结果
        </h5>
        <button type="button" class="close" data-dismiss="modal"
                aria-label="关闭">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div id="batch-result-content"></div>
      </div>
      <div class="modal-footer">
        <button type="button" id="clean-subscriptions" class="btn btn-danger">
            清理订阅
        </button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">
            关闭
        </button>
      </div>
    </div>
  </div>
</div>

<!-- 使用原生JavaScript替代jQuery -->
<script>
// 使用原生JavaScript的事件处理代码
document.addEventListener('DOMContentLoaded', function() {
    console.log('管理订阅JS加载成功 (原生JS版本)');
    
    // 批量检测按钮点击事件
    document.getElementById('batch-check').addEventListener('click', function() {
        console.log('批量检测按钮被点击');
        var modal = document.getElementById('batchResultModal');
        var resultContent = document.getElementById('batch-result-content');
        
        // 显示加载中
        resultContent.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="sr-only">检测中...</span></div><p class="mt-2">正在检测订阅，请稍候...</p></div>';
        
        // 显示模态框 (需要Bootstrap JS)
        if (typeof bootstrap !== 'undefined') {
            var modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
        } else if (typeof $ !== 'undefined') {
            $(modal).modal('show');
        } else {
            // 手动显示模态框
            modal.style.display = 'block';
            modal.classList.add('show');
            document.body.classList.add('modal-open');
            
            // 创建背景遮罩
            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            document.body.appendChild(backdrop);
        }
        
        // 发送AJAX请求
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin_batch_check.php', true);
        xhr.responseType = 'json';
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                var response = xhr.response;
                console.log('批量检测结果:', response);
                
                if (response.error) {
                    resultContent.innerHTML = '<div class="alert alert-danger">' + response.error + '</div>';
                } else {
                    resultContent.innerHTML = response.html;
                    
                    // 显示详细信息
                    if (response.details) {
                        resultContent.insertAdjacentHTML('afterbegin', '<div class="alert alert-info">' + response.details + '</div>');
                    }
                    
                    // 显示主要消息
                    if (response.message) {
                        resultContent.insertAdjacentHTML('afterbegin', '<div class="alert alert-success">' + response.message + '</div>');
                    }
                    
                    // 显示统计信息
                    var statsHtml = '<div class="alert alert-primary mt-3">';
                    statsHtml += '<p class="mb-1"><strong>检测统计：</strong></p>';
                    statsHtml += '<p class="mb-1">总订阅数: ' + response.stats.total + '</p>';
                    statsHtml += '<p class="mb-1">问题订阅数: ' + response.stats.failed + ' (包含 ' + response.stats.zero_traffic + ' 个流量耗尽)</p>';
                    statsHtml += '<p class="mb-1">成功率: ' + response.stats.success_rate + '%</p>';
                    statsHtml += '<p class="mb-0">检测耗时: ' + response.stats.total_time_ms + 'ms (平均 ' + response.stats.avg_time_ms + 'ms/个)</p>';
                    statsHtml += '</div>';
                    
                    resultContent.insertAdjacentHTML('beforeend', statsHtml);
                    
                    // 重新绑定清理订阅按钮事件
                    setupCleanSubscriptionsButton();
                }
            } else {
                console.error('批量检测错误:', xhr.statusText);
                resultContent.innerHTML = '<div class="alert alert-danger">检测过程中发生错误，请重试。</div>';
            }
        };
        
        xhr.onerror = function() {
            console.error('批量检测网络错误');
            resultContent.innerHTML = '<div class="alert alert-danger">网络错误，请检查连接后重试。</div>';
        };
        
        xhr.send();
    });
    
    // 关闭模态框的处理
    document.querySelectorAll('[data-dismiss="modal"]').forEach(function(element) {
        element.addEventListener('click', function() {
            closeModal();
        });
    });
    
    // 关闭模态框函数
    function closeModal() {
        var modal = document.getElementById('batchResultModal');
        if (modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');
            
            // 移除背景遮罩
            var backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
        }
    }
    
    // 设置单个订阅检测按钮
    document.querySelectorAll('.check-sub').forEach(function(button) {
        button.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
            
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'admin_check_sub.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.responseType = 'json';
            
            xhr.onload = function() {
                btn.disabled = false;
                btn.textContent = '检测';
                
                if (xhr.status === 200) {
                    var response = xhr.response;
                    if (response.error) {
                        alert('检测失败: ' + response.error);
                    } else {
                        var message = '检测结果: ' + (response.active ? '可用' : '不可用') + 
                                     '\n响应时间: ' + response.time + 'ms';
                        
                        if (response.traffic_info) {
                            var trafficInfo = '';
                            if (response.traffic_info['剩余流量']) {
                                trafficInfo += '\n剩余流量: ' + response.traffic_info['剩余流量'];
                            }
                            if (response.traffic_info['总流量']) {
                                trafficInfo += '\n总流量: ' + response.traffic_info['总流量'];
                            }
                            if (response.traffic_info['到期时间']) {
                                trafficInfo += '\n到期: ' + response.traffic_info['到期时间'];
                                if (response.traffic_info['剩余']) {
                                    trafficInfo += ' (' + response.traffic_info['剩余'] + ')';
                                }
                            }
                            
                            if (trafficInfo) {
                                message += '\n流量信息:' + trafficInfo;
                            }
                        }
                        
                        alert(message);
                    }
                } else {
                    alert('检测过程中发生错误，请重试。');
                }
            };
            
            xhr.onerror = function() {
                btn.disabled = false;
                btn.textContent = '检测';
                alert('网络错误，请检查连接后重试。');
            };
            
            xhr.send('id=' + encodeURIComponent(id));
        });
    });
    
    // 设置删除订阅按钮
    document.querySelectorAll('.delete-sub').forEach(function(button) {
        button.addEventListener('click', function() {
            if (confirm('确定要删除此订阅吗？')) {
                var id = this.getAttribute('data-id');
                var row = this.closest('tr');
                
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'admin_delete_subscription.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.responseType = 'json';
                
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        var response = xhr.response;
                        if (response.success) {
                            // 淡出效果
                            row.style.opacity = '0';
                            row.style.transition = 'opacity 400ms';
                            setTimeout(function() {
                                row.remove();
                            }, 400);
                        } else {
                            alert('删除失败: ' + (response.error || '未知错误'));
                        }
                    } else {
                        alert('删除过程中发生错误，请重试。');
                    }
                };
                
                xhr.onerror = function() {
                    alert('网络错误，请检查连接后重试。');
                };
                
                xhr.send('id=' + encodeURIComponent(id));
            }
        });
    });
    
    // 设置清理订阅按钮事件
    function setupCleanSubscriptionsButton() {
        var cleanBtn = document.getElementById('clean-subscriptions');
        if (cleanBtn) {
            // 移除旧的事件监听器（防止重复绑定）
            var newCleanBtn = cleanBtn.cloneNode(true);
            cleanBtn.parentNode.replaceChild(newCleanBtn, cleanBtn);
            
            newCleanBtn.addEventListener('click', function(e) {
                console.log('清理订阅按钮被点击');
                e.preventDefault(); // 防止默认行为
                
                if (!confirm('确定要清理问题订阅吗？已选择保留的订阅将不会被删除。')) {
                    return false;
                }
                
                var form = document.getElementById('subscription-form');
                if (!form) {
                    console.error('表单不存在');
                    alert('无法获取表单，请刷新页面重试');
                    return;
                }
                
                // 将表单数据序列化为URL编码的字符串
                var formData = new FormData(form);
                var urlEncodedData = '';
                var urlEncodedDataPairs = [];
                
                // 输出表单数据到控制台
                console.log('表单元素:', form.elements);
                
                var resultContent = document.getElementById('batch-result-content');
                
                // 手动创建表单数据
                var formDataObj = new FormData();
                
                // 收集所有checkbox的值
                var keepSubCheckboxes = document.querySelectorAll('input[name="keep_sub[]"]:checked');
                keepSubCheckboxes.forEach(function(checkbox) {
                    formDataObj.append('keep_sub[]', checkbox.value);
                });
                
                // 确保表单数据中包含必要的参数
                formDataObj.append('action', 'process_deletions');
                formDataObj.append('check_completed', '1');
                
                console.log('准备提交的表单数据:', Array.from(formDataObj.entries()));
                
                // 显示处理中状态
                var processingAlert = document.createElement('div');
                processingAlert.className = 'alert alert-info';
                processingAlert.id = 'processing-alert';
                processingAlert.textContent = '处理中，请稍候...';
                resultContent.insertBefore(processingAlert, resultContent.firstChild);
                
                // 禁用按钮
                newCleanBtn.disabled = true;
                newCleanBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 处理中...';
                
                // 发送AJAX请求
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'admin_batch_check.php', true);
                xhr.responseType = 'json';
                
                xhr.onload = function() {
                    console.log('清理请求状态:', xhr.status);
                    
                    // 移除处理中提示
                    var processingAlert = document.getElementById('processing-alert');
                    if (processingAlert) {
                        processingAlert.remove();
                    }
                    
                    if (xhr.status === 200) {
                        var response = xhr.response;
                        console.log('清理结果:', response);
                        
                        if (response.error) {
                            var errorAlert = document.createElement('div');
                            errorAlert.className = 'alert alert-danger';
                            errorAlert.textContent = response.error;
                            resultContent.insertBefore(errorAlert, resultContent.firstChild);
                        } else {
                            // 更新内容
                            resultContent.innerHTML = response.html;
                            
                            // 显示成功消息
                            if (response.message) {
                                var successAlert = document.createElement('div');
                                successAlert.className = 'alert alert-success';
                                successAlert.textContent = response.message;
                                resultContent.insertBefore(successAlert, resultContent.firstChild);
                            }
                            
                            // 显示详情
                            if (response.details) {
                                var infoAlert = document.createElement('div');
                                infoAlert.className = 'alert alert-info';
                                infoAlert.textContent = response.details;
                                resultContent.insertBefore(infoAlert, resultContent.firstChild);
                            }
                            
                            // 如果有删除的订阅，3秒后刷新页面
                            if (response.deleted_count > 0) {
                                var warningAlert = document.createElement('div');
                                warningAlert.className = 'alert alert-warning';
                                warningAlert.textContent = '页面将在3秒后刷新...';
                                resultContent.insertBefore(warningAlert, resultContent.firstChild);
                                
                                setTimeout(function() {
                                    location.reload();
                                }, 3000);
                            }
                            
                            // 重新绑定清理订阅按钮事件
                            setupCleanSubscriptionsButton();
                        }
                    } else {
                        console.error('清理错误状态:', xhr.status);
                        var errorAlert = document.createElement('div');
                        errorAlert.className = 'alert alert-danger';
                        errorAlert.textContent = '处理过程中发生错误，请重试。详细信息: ' + xhr.statusText;
                        resultContent.insertBefore(errorAlert, resultContent.firstChild);
                    }
                    
                    // 恢复按钮
                    newCleanBtn.disabled = false;
                    newCleanBtn.textContent = '清理订阅';
                };
                
                xhr.onerror = function(e) {
                    console.error('清理网络错误:', e);
                    
                    // 移除处理中提示
                    var processingAlert = document.getElementById('processing-alert');
                    if (processingAlert) {
                        processingAlert.remove();
                    }
                    
                    var errorAlert = document.createElement('div');
                    errorAlert.className = 'alert alert-danger';
                    errorAlert.textContent = '网络错误，请检查连接后重试。';
                    resultContent.insertBefore(errorAlert, resultContent.firstChild);
                    
                    // 恢复按钮
                    newCleanBtn.disabled = false;
                    newCleanBtn.textContent = '清理订阅';
                };
                
                xhr.send(formDataObj);
            });
        }
    }
    
    // 初始化清理订阅按钮
    setupCleanSubscriptionsButton();
});
</script>

<?php
require 'templates/footer.php';
?>