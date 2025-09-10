<?php
// Load current application version and check for updates
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$latestVersion = $localVersion;
$updateAvailable = false;
try {
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
    // silent
}
?>
<footer id="app-footer" data-version="<?= htmlspecialchars($localVersion) ?>">
  <div class="footer-shell">
    <div class="footer-grid">
      <!-- Brand -->
      <div class="footer-brand">
        <div class="footer-logo" aria-hidden="true">
          <span class="logo-core">BRS</span>
          <span class="logo-ring"></span>
        </div>
        <div class="brand-typing">
          <span id="brsType" aria-label="BuyReadySite"></span>
        </div>
      </div>

      <!-- Version / Credits -->
      <div class="footer-center">
        <p class="version-line">
          <span class="ver">v<?= htmlspecialchars($localVersion) ?></span>
          • Developed by <a href="https://BuyReadySite.com" target="_blank" rel="noopener">BuyReadySite.com</a>
          <?php if ($updateAvailable): ?>
            <span class="upd"><a href="/update.php" title="Новая версия доступна">Обновить до v<?= htmlspecialchars($latestVersion) ?></a></span>
          <?php endif; ?>
        </p>
        <p class="updated-at">Последнее обновление интерфейса: 28 июля 2025</p>
      </div>

      <!-- Promo / Actions -->
      <div class="footer-actions">
        <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener" class="chip glow">AI SEO AutoOptimize Pro</a>
        <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener" class="chip">AI Content Wizard</a>
        <a href="/support" class="chip ghost">Поддержка</a>
        <?php if ($updateAvailable): ?>
          <a href="/update.php" class="chip update pulse">Update v<?= htmlspecialchars($latestVersion) ?></a>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer-bottom">
      <div>© <?= date('Y') ?> BuyReadySite — All rights reserved.</div>
      <div class="love">Made with ❤ for web monitoring</div>
    </div>
  </div>

  <script>
  (function(){
    const el = document.getElementById('brsType'); if(!el) return;
    const target = (el.getAttribute('aria-label')||'BuyReadySite');
    const glyphs = '01▮░▒▓█ABCDEFGHJKLMNPQRSTUVWXYZ';
    const STEP_MS = 140; const PAUSE_MS = 5200; let reveal=-1, timer=null;
    function frame(){
      reveal++; if(reveal >= target.length){ el.textContent = target; clearInterval(timer); setTimeout(start, PAUSE_MS); return; }
      let out=''; for(let i=0;i<target.length;i++){ out += (i<=reveal)? target[i] : glyphs[(Math.random()*glyphs.length)|0]; }
      el.textContent = out;
    }
    function start(){
      try{ if(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches){ el.textContent=target; return; } }catch(e){}
      reveal=-1; if(timer) clearInterval(timer); timer=setInterval(frame, STEP_MS);
    }
    start();
  })();
  </script>
</footer>
</body>
</html>