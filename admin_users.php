<?php
// admin_users.php

require_once 'utils.php';
checkAdminAuth();
$pdo = getDbConnection();

// 搜索和筛选功能
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20; // 每页显示的用户数
$offset = ($page - 1) * $perPage;

$conditions = [];
$params = [];

// 搜索条件 - 添加 uuid 搜索
if ($search !== '') {
    $conditions[] = '(username LIKE ? OR oauth_id LIKE ? OR uuid LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

// 筛选条件
if ($status === 'blocked') {
    $conditions[] = 'is_blocked = 1';
} elseif ($status === 'normal') {
    $conditions[] = 'is_blocked = 0';
}

// 构建WHERE子句
$whereClause = '';
if (count($conditions) > 0) {
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
}

// 获取总记录数用于分页
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM users $whereClause
");
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// 确保当前页码有效
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;

// 获取当前页的用户列表
$stmt = $pdo->prepare("
    SELECT *
    FROM users
    $whereClause
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

require 'templates/header.php';
?>

<h2>用户管理</h2>

<!-- 添加返回管理员主页的按钮 -->
<div class="mb-3">
    <a href="admin_dashboard.php" class="btn btn-info">返回管理员主页</a>
</div>

<form method="get" action="admin_users.php" class="form-inline mb-3">
    <input type="text" name="search" class="form-control mr-2"
           placeholder="搜索用户名、OAuth ID或UUID"
           value="<?php echo htmlspecialchars($search); ?>">
    <select name="status" class="form-control mr-2">
        <option value="">所有状态</option>
        <option value="normal" <?php if ($status === 'normal') echo 'selected'; ?>>正常</option>
        <option value="blocked" <?php if ($status === 'blocked') echo 'selected'; ?>>已拉黑</option>
    </select>
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
                    <button class="btn btn-warning btn-sm reset-uuid"
                            data-id="<?php echo $user['id']; ?>">
                        重置UUID
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 分页导航 -->
<nav aria-label="Page navigation">
  <ul class="pagination">
    <!-- [分页代码保持不变] -->
    <?php
    // 计算要显示的页码范围
    $maxDisplay = 7;
    $half = floor($maxDisplay / 2);
    $start = max(1, $page - $half);
    $end = min($totalPages, $page + $half);
    if ($end - $start + 1 < $maxDisplay) {
        if ($start == 1) {
            $end = min($totalPages, $start + $maxDisplay - 1);
        } elseif ($end == $totalPages) {
            $start = max(1, $end - $maxDisplay + 1);
        }
    }
    $queryParams = $_GET;
    ?>

    <!-- [原有分页HTML代码保持不变] -->
  </ul>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var table = document.querySelector('table');
    table.addEventListener('click', function(event) {
        var button = event.target;
        if (button.classList.contains('block-user')) {
            handleBlockUser(button);
        } else if (button.classList.contains('unblock-user')) {
            handleUnblockUser(button);
        } else if (button.classList.contains('reset-uuid')) {
            handleResetUUID(button);
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

    function handleResetUUID(button) {
        if (!confirm('确定要重置该用户的UUID吗？')) return;
        var userId = button.getAttribute('data-id');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'admin_reset_uuid.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            if (xhr.status === 200) {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    alert('UUID重置成功！新UUID: ' + response.newUuid);
                    // 更新表格中显示的UUID
                    var uuidCell = button.closest('tr').children[1];
                    uuidCell.textContent = response.newUuid;
                } else {
                    alert('重置失败：' + response.message);
                }
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