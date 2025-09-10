<?php
// Load current application version and check for updates
// The version is defined in version.php. We also attempt to fetch
// the remote version from the GitHub repository to detect if a newer
// release is available. If a newer version exists, a link will appear
// in the footer inviting the administrator to run the update script.
// Correct path: version.php lives in the same directory as this footer file.
// Use __DIR__ to reference this directory instead of prepending another `inc`.
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
<footer id="app-footer" class="footer-compact">
  <div class="footer-inner">
    <div class="brand-wrap">
      <div class="logo-badge" aria-hidden="true">DS</div>
      <div class="brand-text">
        <div class="brand-name">DiscusScan</div>
        <div class="brand-sub">forum & telegram monitoring</div>
      </div>
      <div class="version-pill">v<?= htmlspecialchars($localVersion) ?></div>
      <?php if ($updateAvailable): ?>
        <a href="/update.php" class="update-btn" title="Доступна новая версия">
          Обновить до v<?= htmlspecialchars($latestVersion) ?>
        </a>
      <?php endif; ?>
    </div>

    <nav class="footer-links" aria-label="Наши сервисы">
      <a href="https://buyreadysite.com/" target="_blank" rel="noopener">BuyReadySite</a>
      <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener">AI Content Wizard</a>
      <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener">AI SEO AutoOptimize Pro</a>
      <a href="https://top-bit.biz/" target="_blank" rel="noopener">Top‑Bit</a>
      <a href="/support" class="muted-link">Поддержка</a>
    </nav>

    <div class="footer-bottom">
      <div class="copyright">© <?= date('Y') ?> DiscusScan — All rights reserved.</div>
      <div class="updated">Обновлено <?= date('d.m.Y') ?></div>
    </div>
  </div>
</footer>
</body>
</html>