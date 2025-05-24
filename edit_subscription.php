<?php
// edit_subscription.php

require_once 'utils.php';
checkAuth();
$pdo = getDbConnection();

// 获取订阅ID
$subscriptionId = intval($_GET['id'] ?? 0);
if ($subscriptionId <= 0) {
    die("无效的请求");
}

// 获取当前用户ID
$userId = $_SESSION['user_id'];

// 获取订阅信息，确保是当前用户的订阅
$stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE id = ? AND created_by = ?");
$stmt->execute([$subscriptionId, $userId]);
$subscription = $stmt->fetch();

if (!$subscription) {
    die("订阅不存在或没有权限修改该订阅");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取并验证表单数据
    $name = trim($_POST['name'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $availableTraffic = floatval($_POST['available_traffic'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');  // 备注可以为空
    $expirationDate = trim($_POST['expiration_date'] ?? '');

    if (empty($name) || empty($link) || $availableTraffic <= 0) {
        $error = "订阅名称、链接和流量均为必填项，且流量必须为正数";
    } else {
        // 如果过期时间为空，将其设置为 NULL
        $expirationDate = $expirationDate === '' ? null : $expirationDate;

        // 更新订阅信息，包括可空的过期时间
        $stmt = $pdo->prepare("UPDATE subscriptions SET name = ?, link = ?, available_traffic = ?, remark = ?, expiration_date = ? WHERE id = ? AND created_by = ?");
        $stmt->execute([$name, $link, $availableTraffic, $remark, $expirationDate, $subscriptionId, $userId]);
        
        header("Location: user_subscriptions.php");
        exit();
    }
}

require 'templates/header.php';
?>

<h2>修改订阅</h2>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="post" action="edit_subscription.php?id=<?php echo $subscriptionId; ?>">
    <div class="form-group">
        <label for="name">订阅名称</label>
        <input type="text" name="name" class="form-control" id="name" value="<?php echo htmlspecialchars($subscription['name']); ?>" required>
    </div>
    <div class="form-group">
        <label for="link">订阅链接</label>
        <input type="text" name="link" class="form-control" id="link" value="<?php echo htmlspecialchars($subscription['link']); ?>" required>
    </div>
    <div class="form-group">
        <label for="available_traffic">可用流量 (G)</label>
        <input type="number" step="0.01" name="available_traffic" class="form-control" id="available_traffic" value="<?php echo floatval($subscription['available_traffic']); ?>" required>
    </div>
    <div class="form-group">
        <label for="remark">备注</label>
        <textarea name="remark" class="form-control" id="remark"><?php echo htmlspecialchars($subscription['remark']); ?></textarea>
    </div>
    <div class="form-group">
        <label for="expiration_date">过期时间</label>
        <input type="date" name="expiration_date" class="form-control" id="expiration_date" value="<?php echo htmlspecialchars($subscription['expiration_date'] ?? ''); ?>">
    </div>
    <button type="submit" class="btn btn-primary">保存修改</button>
    <a href="user_subscriptions.php" class="btn btn-secondary">取消</a>
</form>

<?php
require 'templates/footer.php';
?>