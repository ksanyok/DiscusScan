<?php
include_once __DIR__ . '/version.php';
$version = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$current = basename($_SERVER['PHP_SELF']);
function nav_active($file){ return basename($_SERVER['PHP_SELF']) === $file ? 'active' : ''; }
?>
<header class="topbar glass">
  <div class="brand">
    <div style="display:flex; align-items:center; gap:8px;">
      <div style="width:32px; height:32px; border-radius:8px; background:linear-gradient(135deg,#5b8cff,#7ea2ff); display:flex; align-items:center; justify-content:center; font-weight:700; color:#fff;">DS</div>
      <div>
        <span>DiscusScan</span>
        <div style="font-size:12px; color:#7884a6; margin-top:2px;">v<?=$version?></div>
      </div>
    </div>
  </div>
  <nav>
    <a href="index.php" class="<?=nav_active('index.php')?>">Дашборд</a>
    <a href="sources.php" class="<?=nav_active('sources.php')?>">Домены</a>
    <a href="settings.php" class="<?=nav_active('settings.php')?>">Настройки</a>
    <a href="auth.php?logout=1">Выход</a>
  </nav>
</header>