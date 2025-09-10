<?php
include_once __DIR__ . '/version.php';
$version = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$current = basename($_SERVER['PHP_SELF']);
function nav_active($file){ return basename($_SERVER['PHP_SELF']) === $file ? 'active' : ''; }
?>
<header class="topbar glass">
  <div class="app-shell">
    <div class="brand">
      <a href="index.php" class="brand-link" aria-label="DiscusScan dashboard">
        <img src="logo.svg" alt="DiscusScan" class="logo-icon logo-icon--header" loading="lazy">
        <span class="ver-badge">v<?=$version?></span>
        <span class="sr-only">DiscusScan</span>
      </a>
    </div>
    <nav>
      <a href="index.php" class="<?=nav_active('index.php')?>">Дашборд</a>
      <a href="sources.php" class="<?=nav_active('sources.php')?>">Домены</a>
      <a href="settings.php" class="<?=nav_active('settings.php')?>">Настройки</a>
      <a href="auth.php?logout=1">Выход</a>
    </nav>
  </div>
</header>