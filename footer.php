<?php
// Load current application version and check for updates
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$latestVersion = $localVersion;
$updateAvailable = false;
try {
    // Fetch remote version file from the official repo for updates
    $remoteContent = @file_get_contents('https://raw.githubusercontent.com/ksanyok/DiscusScan/main/version.php');
    if ($remoteContent && preg_match("/APP_VERSION\s*=\s*['\"]([\d\.]+)['\"]/i", $remoteContent, $m)) {
        $latestVersion = $m[1];
        $updateAvailable = version_compare($latestVersion, $localVersion, '>');
    }
} catch (Exception $e) {
    // fail silently
}
?>
<footer id="app-footer" style="position:fixed;left:0;right:0;bottom:0;z-index:9999;background:linear-gradient(180deg,rgba(3,8,22,0.9),rgba(6,12,30,0.92));color:rgba(255,255,255,0.95);">
  <div style="max-width:1200px;margin:0 auto;padding:18px 20px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;justify-content:space-between">
    <!-- Brand block -->
    <div style="display:flex;align-items:center;gap:12px;min-width:220px">
      <div style="position:relative;width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,#10b981,#047857);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:18px;box-shadow:0 6px 20px rgba(4,120,87,0.24)">
        <span style="font-family:monospace">BRS</span>
        <span style="position:absolute;inset:0;border-radius:12px;box-shadow:inset 0 1px 0 rgba(255,255,255,0.04)"></span>
      </div>
      <div>
        <div style="font-weight:700">BuyReadySite — DiscusScan</div>
        <div style="font-size:12px;opacity:0.75">v<?= htmlspecialchars($localVersion) ?><?php if ($updateAvailable): ?> <span style="color:#7fffd4;font-weight:700;margin-left:8px">• Новая v<?= htmlspecialchars($latestVersion) ?> доступна</span><?php endif; ?></div>
      </div>
    </div>

    <!-- Center: promo / description -->
    <div style="flex:1 1 440px;min-width:260px;text-align:center;padding:4px 12px;color:rgba(255,255,255,0.88)">
      <div style="font-size:13px;opacity:0.9">Мониторинг упоминаний — автоматические сканы форумов и сайтов. Интеграция с AI для фильтрации и классификации результатов.</div>
    </div>

    <!-- Right: promos + update action -->
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-self:end">
      <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.04);color:#fff;text-decoration:none;font-size:13px">AI SEO AutoOptimize Pro</a>
      <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.04);color:#fff;text-decoration:none;font-size:13px">AI Content Wizard</a>
      <a href="/support" style="display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:10px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.03);color:#fff;text-decoration:none;font-size:13px">Поддержка</a>

      <?php if ($updateAvailable): ?>
        <a href="/update.php" style="margin-left:6px;padding:10px 14px;border-radius:10px;background:linear-gradient(90deg,#10b981,#34d399);color:#022;font-weight:700;text-decoration:none;box-shadow:0 8px 28px rgba(16,185,129,0.12);">Обновить до v<?= htmlspecialchars($latestVersion) ?></a>
      <?php endif; ?>
    </div>
  </div>

  <div style="border-top:1px solid rgba(255,255,255,0.04);padding:10px 20px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
    <div style="font-size:12px;opacity:0.8">© <?= date('Y') ?> BuyReadySite — All rights reserved.</div>
    <div style="font-size:12px;opacity:0.75">Made with ❤ for web monitoring</div>
  </div>

  <style>
    /* small responsive tweaks */
    @media (max-width:820px){
      #app-footer div[style*="flex:1 1 440px"]{order:3}
      #app-footer div[style*="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-self:end"]{order:2}
    }
  </style>
</footer>
</body>
</html>