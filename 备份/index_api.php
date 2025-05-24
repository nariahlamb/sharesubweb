<?php
// index_api.php

require_once 'utils.php';
$config = require 'config.php';

header('Content-Type: application/json');

session_start();

$response = [];

if (!empty($_SESSION['user_id'])) {
    $response['redirect'] = 'display.html';
    echo json_encode($response);
    exit();
}

// 如果用户请求登录操作，返回OAuth2授权链接
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
    $response['authUrl'] = $authUrl;
    echo json_encode($response);
    exit();
}

// 默认返回主页信息
$response['message'] = '欢迎来到linux do订阅分享平台，通过OAuth2登录并开始分享您的订阅链接。';
$response['loginUrl'] = '?action=login';

echo json_encode($response);
?>
