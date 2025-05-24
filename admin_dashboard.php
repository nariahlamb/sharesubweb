<?php
// admin_dashboard.php

require_once 'utils.php';
checkAdminAuth();

require 'templates/header.php';
?>

<h2>管理员仪表盘</h2>

<ul>
    <li><a href="admin_subscriptions.php">订阅管理</a></li>
    <li><a href="admin_users.php">用户管理</a></li>
    <li><a href="admin_logout.php">退出登录</a></li>
</ul>

<?php
require 'templates/footer.php';
?>