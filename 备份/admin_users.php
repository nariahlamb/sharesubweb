<?php
// admin_users.php

require_once 'utils.php';
checkAdminAuth();
$pdo = getDbConnection();

// 搜索功能
$search = $_GET['search'] ?? '';
$searchQuery = '';
$params = [];

if ($search !== '') {
    $searchQuery = 'WHERE username LIKE ? OR oauth_id LIKE ?';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// 获取用户列表
$stmt = $pdo->prepare("
    SELECT *
    FROM users
    $searchQuery
    ORDER BY created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

require 'templates/header.php';
?>

<h2>用户管理</h2>

<form method="get" action="admin_users.php" class="form-inline mb-3">
    <input type="text" name="search" class="form-control mr-2"
           placeholder="搜索用户名或OAuth ID"
           value="<?php echo htmlspecialchars($search); ?>">
    <button type="submit" class="btn btn-primary">搜索</button>
    <a href="admin_users.php" class="btn btn-secondary ml-2">重置</a>
</form>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>用户名</th>
            <th>UUID</th>
            <th>注册时间</th>
            <th>状态</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td><?php echo htmlspecialchars($user['username']); ?></td>
                <td><?php echo htmlspecialchars($user['uuid']); ?></td>
                <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                <td>
                    <?php echo $user['is_blocked'] ? '已拉黑' : '正常'; ?>
                </td>
                <td>
                    <?php if (!$user['is_blocked']): ?>
                        <button class="btn btn-danger btn-sm block-user"
                                data-id="<?php echo $user['id']; ?>">
                            拉黑
                        </button>
                    <?php else: ?>
                        <button class="btn btn-success btn-sm unblock-user"
                                data-id="<?php echo $user['id']; ?>">
                            解除拉黑
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 在这里嵌入修正后的 JavaScript 代码 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var table = document.querySelector('table');
    table.addEventListener('click', function(event) {
        var button = event.target;
        if (button.classList.contains('block-user')) {
            handleBlockUser(button);
        } else if (button.classList.contains('unblock-user')) {
            handleUnblockUser(button);
        }
    });

    function handleBlockUser(button) {
        if (!confirm('确定要拉黑该用户吗？')) return;
        var userId = button.getAttribute('data-id');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin_block_user.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                var response = JSON.parse(xhr.responseText);
                alert(response.message);
                // 更新按钮和状态
                button.classList.remove('btn-danger', 'block-user');
                button.classList.add('btn-success', 'unblock-user');
                button.textContent = '解除拉黑';
                var statusCell = button.closest('tr').children[3];
                statusCell.textContent = '已拉黑';
            } else {
                alert('操作失败');
            }
        };
        xhr.send('id=' + encodeURIComponent(userId));
    }

    function handleUnblockUser(button) {
        if (!confirm('确定要解除拉黑该用户吗？')) return;
        var userId = button.getAttribute('data-id');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin_unblock_user.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                var response = JSON.parse(xhr.responseText);
                alert(response.message);
                // 更新按钮和状态
                button.classList.remove('btn-success', 'unblock-user');
                button.classList.add('btn-danger', 'block-user');
                button.textContent = '拉黑';
                var statusCell = button.closest('tr').children[3];
                statusCell.textContent = '正常';
            } else {
                alert('操作失败');
            }
        };
        xhr.send('id=' + encodeURIComponent(userId));
    }
});
</script>

<?php
require 'templates/footer.php';
?>
