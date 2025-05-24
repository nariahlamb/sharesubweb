<?php
// upload.php

require_once 'utils.php';
checkAuth();
require 'csrf.php';
$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("CSRF验证失败");
    }

    // 获取并验证输入
    $name = trim($_POST['name'] ?? '');
    $source = trim($_POST['source'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $available_traffic = floatval($_POST['available_traffic'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');

    if (empty($name) || empty($link)) {
        $error = "名称和链接为必填项";
    } else {
        // 插入数据库
        $stmt = $pdo->prepare("INSERT INTO subscriptions (name, source, link, available_traffic, remark, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $source, $link, $available_traffic, $remark, $_SESSION['user_id']]);
        $success = "订阅链接上传成功";
    }
}

require 'templates/header.php';
?>

<h2>上传订阅链接</h2>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if (!empty($success)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<form method="POST" action="upload.php">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <div class="form-group">
        <label for="name">订阅名称</label>
        <input type="text" class="form-control" id="name" name="name" required>
    </div>
    <div class="form-group">
        <label for="source">来源</label>
        <input type="text" class="form-control" id="source" name="source">
    </div>
    <div class="form-group">
        <label for="link">订阅链接</label>
        <textarea class="form-control" id="link" name="link" rows="3" required></textarea>
    </div>
    <div class="form-group">
        <label for="available_traffic">可用流量 (G)</label>
        <input type="number" step="0.01" class="form-control" id="available_traffic" name="available_traffic" value="0">
    </div>
    <div class="form-group">
        <label for="remark">备注</label>
        <textarea class="form-control" id="remark" name="remark" rows="2"></textarea>
    </div>
    <button type="submit" class="btn btn-primary">上传</button>
</form>

<?php
require 'templates/footer.php';
?>