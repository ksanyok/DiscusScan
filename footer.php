<?php
// Load current application version and check for updates
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$latestVersion = $localVersion;
$updateAvailable = false;
try {
    // Fetch remote version file from GitHub
    $remoteContent = @file_get_contents('https://raw.githubusercontent.com/ksanyok/DiscusScan/main/version.php');
    if ($remoteContent && preg_match("/APP_VERSION\s*=\s*['\"]([\d\.]+)['\"]/i", $remoteContent, $m)) {
        $latestVersion = $m[1];
        $updateAvailable = version_compare($latestVersion, $localVersion, '>');
    }
} catch (Exception $e) {
    // Fail silently if unable to fetch remote version
}
?>
<footer class="card glass" style="margin-top: 24px; padding: 24px; background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03)), rgba(26,36,71,.8); backdrop-filter: saturate(140%) blur(10px);">
  <div style="display: grid; grid-template-columns: auto 1fr auto; gap: 24px; align-items: center;">
    <!-- Brand -->
    <div style="display: flex; align-items: center; gap: 12px;">
      <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #2ecc71, #27ae60); display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; box-shadow: 0 4px 12px rgba(46,204,113,.4);">DS</div>
      <div style="line-height: 1.2;">
        <div style="font-family: monospace; font-size: 20px; letter-spacing: 0.06em; background: linear-gradient(90deg, #baf7d0, #7fffd4); -webkit-background-clip: text; background-clip: text; color: transparent;">DiscusScan</div>
      </div>
    </div>

    <!-- Center: version + credits -->
    <div style="text-align: center;">
      <p style="font-size: 14px; opacity: 0.8;">
        v<?= htmlspecialchars($localVersion) ?> • Developed by
        <a href="https://github.com/ksanyok/DiscusScan" style="text-decoration: underline; color: #7fffd4;" target="_blank" rel="noopener">GitHub</a>
        <?php if ($updateAvailable): ?>
          <span style="margin-left: 8px; font-size: 12px; color: #7fffd4;">
            <a href="update.php" style="text-decoration: underline;" title="Новая версия доступна">Обновить до v<?= htmlspecialchars($latestVersion) ?></a>
          </span>
        <?php endif; ?>
      </p>
      <p style="font-size: 12px; opacity: 0.7;">Updated 10 сентября 2025</p>
    </div>

    <!-- Links -->
    <div style="justify-self: end;">
      <div style="display: flex; flex-wrap: wrap; gap: 8px; font-size: 13px;">
        <a href="https://github.com/ksanyok/DiscusScan" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); text-decoration: none; color: white; transition: background 0.2s;">
          <span style="width: 8px; height: 8px; border-radius: 50%; background: #2ecc71;"></span>
          <span>GitHub Repo</span>
        </a>
        <a href="settings.php" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); text-decoration: none; color: white; transition: background 0.2s;">
          <span>⚙️</span>
          <span>Настройки</span>
        </a>
        <a href="auth.php?logout=1" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 8px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); text-decoration: none; color: white; transition: background 0.2s;">
          <span>🚪</span>
          <span>Выход</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Bottom line -->
  <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.1); font-size: 12px; opacity: 0.6; display: flex; justify-content: space-between; align-items: center;">
    <div>© <?= date('Y') ?> DiscusScan — All rights reserved.</div>
    <div>Made with ❤ for web monitoring</div>
  </div>
</footer>