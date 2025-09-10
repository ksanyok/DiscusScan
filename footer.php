<?php
// Load current application version and check for updates
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$latestVersion = $localVersion;
$updateAvailable = false;
try {
    // Try fetching remote version file from GitHub (raw). Use file_get_contents, fall back to curl.
    $remoteUrl = 'https://raw.githubusercontent.com/ksanyok/DiscusScan/main/version.php';
    $remoteContent = @file_get_contents($remoteUrl);
    if ($remoteContent === false && function_exists('curl_version')) {
        $ch = curl_init($remoteUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        $remoteContent = curl_exec($ch);
        curl_close($ch);
    }
    if ($remoteContent && preg_match("/APP_VERSION\s*=\s*['\"]([\d\.]+)['\"]/i", $remoteContent, $m)) {
        $latestVersion = $m[1];
        $updateAvailable = version_compare($latestVersion, $localVersion, '>');
    }
} catch (Exception $e) {
    // ignore network issues silently
}
?>
<!-- Footer: regular document flow footer (not fixed) -->
<footer id="app-footer" class="glass site-footer" role="contentinfo" aria-label="Site footer" data-version="<?= htmlspecialchars($localVersion) ?>">
  <div class="container footer-inner">

    <div class="footer-top">
      <div class="brand-block">
        <div class="logo-pill" aria-hidden="true">BRS</div>
        <div class="brand-text">
          <div class="brand-name">BuyReadySite</div>
          <div class="brand-sub"><span class="pill">DiscusScan</span></div>
        </div>
      </div>

      <div class="footer-center">
        <div class="footer-desc">Мониторинг упоминаний — автоматические сканы форумов и сайтов с AI‑фильтрацией и классификацией.</div>
        <div class="footer-version" aria-live="polite"> 
          <span class="version">v<?= htmlspecialchars($localVersion) ?></span>
          <?php if ($updateAvailable): ?>
            <span class="version-badge">Новее: v<?= htmlspecialchars($latestVersion) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="footer-actions">
        <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener" class="btn small" aria-label="AI SEO AutoOptimize Pro">AI SEO AutoOptimize Pro</a>
        <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener" class="btn small" aria-label="AI Content Wizard">AI Content Wizard</a>
        <a href="/support" class="btn small btn-ghost" aria-label="Support">Поддержка</a>

        <?php if ($updateAvailable): ?>
          <a href="/update.php" class="btn small update-btn pulse" title="Обновить до v<?= htmlspecialchars($latestVersion) ?>" aria-label="Обновить до версии <?= htmlspecialchars($latestVersion) ?>">Обновить до v<?= htmlspecialchars($latestVersion) ?></a>
        <?php endif; ?>
      </div>
    </div>

  </div>
  <div class="footer-credits container">
    <div class="credits-left">© <?= date('Y') ?> BuyReadySite — All rights reserved.</div>
    <div class="credits-right">Made with ❤ for web monitoring</div>
  </div>
</footer>
</body>
</html>