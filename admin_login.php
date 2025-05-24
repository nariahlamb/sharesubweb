<?php
// admin_login.php

require_once 'utils.php';

session_start();

if (isset($_POST['username'], $_POST['password'])) {
    $config = require 'config.php';
    $adminUsername = $config['admin']['username'];
    $adminPassword = $config['admin']['password'];

    // 使用明文匹配
    if ($_POST['username'] === $adminUsername && $_POST['password'] === $adminPassword) {
        $_SESSION['is_admin'] = true;
        header('Location: admin_dashboard.php');
        exit();
    } else {
        $error = '用户名或密码错误';
    }
}

require 'templates/header.php';
?>

<h2>管理员登录</h2>

<?php if (isset($error)): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<form method="post" action="admin_login.php">
    <div class="form-group">
        <label for="username">用户名</label>
        <input type="text" name="username" class="form-control" id="username" required>
    </div>
    <div class="form-group">
        <label for="password">密码</label>
        <input type="password" name="password" class="form-control" id="password" required>
    </div>
    <button type="submit" class="btn btn-primary">登录</button>
</form>

<?php
require 'templates/footer.php';
?>