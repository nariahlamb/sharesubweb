<?php
// templates/header.php
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>订阅分享平台</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-light bg-light">
  <a class="navbar-brand" href="index.html">订阅分享平台</a>
  <div class="collapse navbar-collapse">
    <ul class="navbar-nav mr-auto">
      <?php if (!empty($_SESSION['user_id'])): ?>
      <li class="nav-item"><a class="nav-link" href="uplode.html">上传订阅</a></li>
      <li class="nav-item"><a class="nav-link" href="display.html">查看订阅</a></li>
      <li class="nav-item"><a class="nav-link" href="leaderboard.html">排行榜</a></li>
      <?php endif; ?>
    </ul>
    <ul class="navbar-nav">
      <?php if (!empty($_SESSION['username'])): ?>
          <li class="nav-item"><span class="nav-link">欢迎, <?php echo htmlspecialchars($_SESSION['username']); ?></span></li>
          <li class="nav-item"><a class="nav-link" href="logout.php">登出</a></li>
      <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="index.php">登录</a></li>
      <?php endif; ?>
    </ul>
  </div>
</nav>
<div class="container mt-4">