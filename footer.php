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
<footer id="app-footer" class="glass" style="width:100%;margin-top:24px;">
  <div style="max-width:1200px;margin:0 auto;padding:18px 22px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:space-between">

    <div style="display:flex;align-items:center;gap:12px;min-width:220px">
      <div style="width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#5b8cff,#7ea2ff);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:18px;box-shadow:0 8px 24px rgba(91,140,255,0.12)">BRS</div>
      <div>
        <div style="font-weight:700;letter-spacing:0.01em;color:#eaf0ff">BuyReadySite</div>
        <div style="font-size:13px;color:rgba(234,240,255,0.78);display:flex;gap:8px;align-items:center">
          <div style="font-size:12px;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.04);">DiscusScan</div>
        </div>
      </div>
    </div>

    <div style="flex:1 1 480px;min-width:260px;text-align:center;color:rgba(234,240,255,0.9)">
      <div style="font-size:13px;opacity:0.95">Мониторинг упоминаний — автоматические сканы форумов и сайтов с AI‑фильтрацией и классификацией.</div>
      <div style="margin-top:6px;font-size:13px;opacity:0.8">v<?= htmlspecialchars($localVersion) ?>
        <?php if ($updateAvailable): ?>
          <span style="margin-left:10px;background:linear-gradient(90deg,#7fffd4,#baf7d0);padding:6px 10px;border-radius:10px;font-weight:700;color:#042;box-shadow:0 6px 18px rgba(122,235,197,0.08)">Новее: v<?= htmlspecialchars($latestVersion) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;min-width:260px">
      <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener" class="btn small">AI SEO AutoOptimize Pro</a>
      <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener" class="btn small">AI Content Wizard</a>
      <a href="/support" class="btn small" style="background:transparent;color:var(--text);border:1px solid var(--border);box-shadow:none">Поддержка</a>

      <?php if ($updateAvailable): ?>
        <a href="/update.php" class="btn small" style="background:linear-gradient(90deg,#5b8cff,#7ea2ff);color:#022;font-weight:800;margin-left:6px">Обновить до v<?= htmlspecialchars($latestVersion) ?></a>
      <?php endif; ?>
    </div>

  </div>
  <div style="border-top:1px solid rgba(255,255,255,0.03);padding:10px 22px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;max-width:1200px;margin:0 auto">
    <div style="font-size:12px;opacity:0.8">© <?= date('Y') ?> BuyReadySite — All rights reserved.</div>
    <div style="font-size:12px;opacity:0.75">Made with ❤ for web monitoring</div>
  </div>
</footer>
</body>
</html>