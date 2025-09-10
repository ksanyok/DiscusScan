<header class="topbar glass">
  <div class="brand">
    <div style="display: flex; align-items: center; gap: 8px;">
      <div style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, #5b8cff, #7ea2ff); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;">DS</div>
      <div>
        <span>DiscusScan</span>
        <div style="font-size: 12px; color: #666; margin-top: 2px;">v<?php 
          include_once __DIR__ . '/version.php';
          echo defined('APP_VERSION') ? APP_VERSION : '0.0.0';
        ?></div>
      </div>
    </div>
  </div>
  <nav>
    <a href="index.php" class="active">Дашборд</a>
    <a href="sources.php">Домены</a>
    <a href="settings.php">Настройки</a>
    <a href="auth.php?logout=1">Выход</a>
  </nav>
</header>