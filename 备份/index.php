<?php
// index.php

require_once 'utils.php';
$config = require 'config.php';

if (!empty($_SESSION['user_id'])) {
    header("Location: display.html");
    exit();
}

// 如果用户点击登录，重定向到OAuth2授权页面
if (isset($_GET['action']) && $_GET['action'] === 'login') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $authUrl = $config['oauth2']['authorizationEndpoint'] . '?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $config['oauth2']['clientId'],
        'redirect_uri' => $config['oauth2']['redirectUri'],
        'scope' => 'read', // 根据实际需求调整
        'state' => $state,
    ]);
    header("Location: $authUrl");
    exit();
}

require 'templates/header.php';
?>

<div class="jumbotron">
    <h1 class="display-4">欢迎来到订阅分享平台</h1>
    <p class="lead">通过OAuth2登录并开始分享您的订阅链接。</p>
    <hr class="my-4">
    <a class="btn btn-primary btn-lg" href="?action=login" role="button">使用OAuth2登录</a>
</div>

<?php
require 'templates/footer.php';
?>
