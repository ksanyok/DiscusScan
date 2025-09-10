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
<!-- Full-width fixed footer with animated brand and product promos -->
<footer id="app-footer" aria-label="site-footer" style="position:fixed;left:0;right:0;bottom:0;z-index:9999;">
  <!-- Background: full-bleed vibrant gradient + subtle textures to match app theme -->
  <div style="position:absolute;inset:0;background:linear-gradient(180deg, rgba(6,12,30,0.96), rgba(7,16,40,0.9));box-shadow:0 -8px 40px rgba(2,6,23,0.65);pointer-events:none;z-index:-2"></div>
  <div style="position:absolute;inset:0;z-index:-1;opacity:0.06;background-image:radial-gradient(1px 1px at 10px 10px, #fff 1px, transparent 1px);background-size:22px 22px"></div>

  <div style="max-width:1200px;margin:0 auto;padding:18px 22px;display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:space-between">

    <!-- Brand / animated logo -->
    <div style="display:flex;align-items:center;gap:12px;min-width:220px">
      <div style="position:relative;width:64px;height:64px;border-radius:14px;background:linear-gradient(135deg,#5b8cff,#7ea2ff);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:20px;box-shadow:0 10px 30px rgba(91,140,255,0.14)">
        <div style="font-family:monospace">BRS</div>
        <div style="position:absolute;inset:0;border-radius:14px;border:1px solid rgba(255,255,255,0.04)"></div>
      </div>
      <div>
        <div style="font-weight:700;letter-spacing:0.01em;color:#eaf0ff">BuyReadySite</div>
        <div style="font-size:13px;color:rgba(234,240,255,0.78);display:flex;gap:8px;align-items:center">
          <div id="brsType" aria-label="BuyReadySite" style="min-width:10ch"></div>
          <div style="font-size:12px;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.04);">DiscusScan</div>
        </div>
      </div>
    </div>

    <!-- Center: short description and update notice -->
    <div style="flex:1 1 480px;min-width:260px;text-align:center;color:rgba(234,240,255,0.9)">
      <div style="font-size:13px;opacity:0.95">Мониторинг упоминаний — автоматические сканы форумов и сайтов с AI‑фильтрацией и классификацией.</div>
      <div style="margin-top:6px;font-size:13px;opacity:0.8">
        v<?= htmlspecialchars($localVersion) ?>
        <?php if ($updateAvailable): ?>
          <span style="margin-left:10px;background:linear-gradient(90deg,#7fffd4,#baf7d0);padding:6px 10px;border-radius:10px;font-weight:700;color:#042;box-shadow:0 6px 18px rgba(122,235,197,0.08)">Новее: v<?= htmlspecialchars($latestVersion) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Right: promo buttons + conditional update action -->
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end;min-width:260px">
      <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:10px;padding:8px 14px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.03),rgba(255,255,255,0.02));border:1px solid rgba(255,255,255,0.04);color:#eaf0ff;text-decoration:none;font-weight:600;font-size:13px">AI SEO AutoOptimize Pro</a>
      <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:10px;padding:8px 14px;border-radius:12px;background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));border:1px solid rgba(255,255,255,0.03);color:#eaf0ff;text-decoration:none;font-weight:600;font-size:13px">AI Content Wizard</a>
      <a href="/support" style="display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.03);color:#eaf0ff;text-decoration:none;font-size:13px">Поддержка</a>

      <?php if ($updateAvailable): ?>
        <a href="/update.php" style="padding:10px 14px;border-radius:12px;background:linear-gradient(90deg,#5b8cff,#7ea2ff);color:#022;font-weight:800;text-decoration:none;box-shadow:0 12px 34px rgba(91,140,255,0.12);margin-left:6px">Обновить до v<?= htmlspecialchars($latestVersion) ?></a>
      <?php endif; ?>
    </div>

  </div>

  <!-- bottom small line -->
  <div style="border-top:1px solid rgba(255,255,255,0.03);padding:10px 22px;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;max-width:1200px;margin:0 auto">
    <div style="font-size:12px;opacity:0.8">© <?= date('Y') ?> BuyReadySite — All rights reserved.</div>
    <div style="font-size:12px;opacity:0.75">Made with ❤ for web monitoring</div>
  </div>

  <!-- animated typing for brand (subtle, respects reduced-motion) -->
  <style>
    #brsType{min-height:1.2em; letter-spacing:.04em; background:linear-gradient(90deg,#baf7d0,#7fffd4); -webkit-background-clip:text; background-clip:text; color:transparent; text-shadow:0 0 8px rgba(126,162,255,0.06)}
    #brsType::after{content:"|"; margin-left:4px; opacity:.7; animation:brsBlink 1.2s steps(1) infinite}
    @keyframes brsBlink{50%{opacity:0}}
    @media (prefers-reduced-motion: reduce){#brsType::after{animation:none; opacity:.6}}
  </style>
  <script>
    (function(){
      const el = document.getElementById('brsType'); if(!el) return;
      const target = el.getAttribute('aria-label') || 'BuyReadySite';
      const glyphs = '01▮░▒▓█ABCDEFGHJKLMNPQRSTUVWXYZ';
      const STEP_MS = 110;
      const PAUSE_MS = 4800;
      let reveal = -1, timer = null;

      function frame(){
        reveal++;
        if (reveal >= target.length) {
          el.textContent = target;
          clearInterval(timer);
          setTimeout(start, PAUSE_MS);
          return;
        }
        let out = '';
        for (let i=0;i<target.length;i++){
          out += (i <= reveal) ? target[i] : glyphs[(Math.random()*glyphs.length)|0];
        }
        el.textContent = out;
      }

      function start(){
        try { if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = target; return; } } catch(e){}
        reveal = -1;
        if (timer) clearInterval(timer);
        timer = setInterval(frame, STEP_MS);
      }

      start();
    })();
  </script>
</footer>
</body>
</html>