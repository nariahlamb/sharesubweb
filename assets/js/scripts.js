// assets/js/scripts.js

$(document).ready(function() {
    // 复制按钮功能
    $('.copy-btn').click(function() {
        var text = $(this).data('clipboard-text');
        if (navigator.clipboard && window.isSecureContext) {
            // 使用 Clipboard API
            navigator.clipboard.writeText(text).then(function() {
                showToast('复制成功!');
            }, function(err) {
                console.error('无法复制文本: ', err);
                showToast('复制失败!');
            });
        } else {
            // 回退到传统方法
            var tempInput = $("<input>");
            $("body").append(tempInput);
            tempInput.val(text).select();
            try {
                document.execCommand("copy");
                showToast('复制成功!');
            } catch (err) {
                console.error('无法复制文本: ', err);
                showToast('复制失败!');
            }
            tempInput.remove();
        }
    });

    function showToast(message) {
        var toast = $('<div class="toast" role="alert" aria-live="assertive" aria-atomic="true">' +
                      '<div class="toast-body">' + message + '</div></div>');
        toast.appendTo('body').toast({ delay: 2000 }).toast('show').on('hidden.bs.toast', function () {
            $(this).remove();
        });
    }

    // 单独检测订阅可用性
    $('.check-sub').click(function() {
        var subId = $(this).data('id');
        var button = $(this);
        button.prop('disabled', true).text('检测中...');
        $.ajax({
            url: 'admin_check_subscription.php',
            method: 'POST',
            data: { id: subId },
            dataType: 'json',
            success: function(response) {
                alert(response.message);
                button.prop('disabled', false).text('检测');
            },
            error: function() {
                alert('检测失败');
                button.prop('disabled', false).text('检测');
            }
        });
    });

    // 删除订阅
    $('.delete-sub').click(function() {
        if (!confirm('确定要删除该订阅吗？')) return;
        var subId = $(this).data('id');
        var row = $(this).closest('tr');
        $.ajax({
            url: 'admin_delete_subscription.php',
            method: 'POST',
            data: { id: subId },
            dataType: 'json',
            success: function(response) {
                alert(response.message);
                row.remove();
            },
            error: function() {
                alert('删除失败');
            }
        });
    });

    // 批量检测订阅可用性
    $('#batch-check').click(function() {
        $(this).prop('disabled', true).text('检测中...');
        $.ajax({
            url: 'admin_batch_check.php',
            method: 'POST',
            dataType: 'json',
            success: function(response) {
                $('#batch-result-content').html(response.html);
                $('#batchResultModal').modal('show');
                $('#batch-check').prop('disabled', false).text('批量检测订阅可用性');
            },
            error: function() {
                alert('批量检测失败');
                $('#batch-check').prop('disabled', false).text('批量检测订阅可用性');
            }
        });
    });

    // 删除不活跃订阅
    $('#delete-inactive').click(function() {
        if (!confirm('确定要删除所有不活跃的订阅吗？')) return;
        $.ajax({
            url: 'admin_delete_inactive.php',
            method: 'POST',
            dataType: 'json',
            success: function(response) {
                alert(response.message);
                location.reload();
            },
            error: function() {
                alert('删除失败');
            }
        });
    });

    // 拉黑用户
    $('.block-user').click(function() {
        if (!confirm('确定要拉黑该用户吗？')) return;
        var userId = $(this).data('id');
        var button = $(this);
        $.ajax({
            url: 'admin_block_user.php',
            method: 'POST',
            data: { id: userId },
            dataType: 'json',
            success: function(response) {
                alert(response.message);
                button.removeClass('btn-danger block-user')
                      .addClass('btn-success unblock-user')
                      .text('解除拉黑');
                button.closest('tr').find('td:nth-child(4)').text('已拉黑');
            },
            error: function() {
                alert('操作失败');
            }
        });
    });

    // 解除拉黑用户
    $('.unblock-user').click(function() {
        if (!confirm('确定要解除拉黑该用户吗？')) return;
        var userId = $(this).data('id');
        var button = $(this);
        $.ajax({
            url: 'admin_unblock_user.php',
            method: 'POST',
            data: { id: userId },
            dataType: 'json',
            success: function(response) {
                alert(response.message);
                button.removeClass('btn-success unblock-user')
                      .addClass('btn-danger block-user')
                      .text('拉黑');
                button.closest('tr').find('td:nth-child(4)').text('正常');
            },
            error: function() {
                alert('操作失败');
            }
        });
    });
});