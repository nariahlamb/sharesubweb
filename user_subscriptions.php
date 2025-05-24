<?php
// user_subscriptions.php

require_once 'utils.php';
checkAuth();
$pdo = getDbConnection();

// 获取当前用户的ID
$userId = $_SESSION['user_id'];

// 获取用户分享的订阅，并获取每个订阅的唯一IP数量
$stmt = $pdo->prepare("
    SELECT s.*, COUNT(DISTINCT ui.ip_address) AS unique_ips
    FROM subscriptions s
    LEFT JOIN user_ips ui ON s.id = ui.subscription_id
    WHERE s.created_by = ?
    GROUP BY s.id
    ORDER BY s.created_at DESC
");
$stmt->execute([$userId]);
$subscriptions = $stmt->fetchAll();

// 获取用户使用过的IP列表
$ipStmt = $pdo->prepare("SELECT DISTINCT ip_address FROM user_ips WHERE user_id = ?");
$ipStmt->execute([$userId]);
$ips = $ipStmt->fetchAll(PDO::FETCH_COLUMN);

// 统计独立IP数量
$uniqueIpCount = count($ips);

require 'templates/header.php';
?>

<h2>我的订阅</h2>

<!-- 显示用户使用过的IP数量和列表 -->
<p>您使用过的独立IP数量：<strong><?php echo $uniqueIpCount; ?></strong></p>
<p>使用过的IP列表：</p>
<ul>
    <?php foreach ($ips as $ip): ?>
        <li><?php echo htmlspecialchars($ip); ?></li>
    <?php endforeach; ?>
</ul>

<!-- 表格样式 -->
<style>
    td {
        word-break: break-all;
        max-width: 200px; /* 根据需要调整最大宽度 */
    }
</style>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>订阅名称</th>
            <th>订阅链接</th>
            <th>可用流量 (G)</th>
            <th>备注</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($subscriptions as $sub): ?>
            <tr>
                <td><?php echo htmlspecialchars($sub['name']); ?></td>
                <td><?php echo htmlspecialchars($sub['link']); ?></td>
                <td><?php echo floatval($sub['available_traffic']); ?></td>
                <td><?php echo htmlspecialchars($sub['remark']); ?></td>
                <td>
                    <a href="edit_subscription.php?id=<?php echo $sub['id']; ?>" class="btn btn-info btn-sm">修改</a>
                    <button class="btn btn-danger btn-sm delete-subscription" data-id="<?php echo $sub['id']; ?>">删除</button>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- 删除订阅的JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.delete-subscription').forEach(function(button) {
        button.addEventListener('click', function() {
            if (!confirm('确定要删除该订阅吗？')) return;
            var subscriptionId = this.getAttribute('data-id');
            var row = this.closest('tr');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'delete_subscription.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var response = JSON.parse(xhr.responseText);
                    alert(response.message);
                    if (response.success) {
                        row.remove();
                    }
                } else {
                    alert('删除失败');
                }
            };
            xhr.send('id=' + encodeURIComponent(subscriptionId));
        });
    });
});
</script>

<?php
require 'templates/footer.php';
?>