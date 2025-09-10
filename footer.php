<?php
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$latestVersion = $localVersion; $updateAvailable = false; $lastUpdateDate = '—';
$remoteRepoRaw = 'https://raw.githubusercontent.com/ksanyok/DiscusScan/main/version.php';
$lastUpdateFile = __DIR__ . '/data/last_update.txt';
if (!is_dir(__DIR__ . '/data')) { @mkdir(__DIR__ . '/data', 0755, true); }
// Load stored last update date
if (is_file($lastUpdateFile)) {
  $raw = trim(@file_get_contents($lastUpdateFile));
  if ($raw) { $lastUpdateDate = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'); }
}
// Fetch remote version with curl fallback
$remoteContent = false;
if (function_exists('curl_init')) {
  $ch = curl_init($remoteRepoRaw);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>6]);
  $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($data !== false && $code >= 200 && $code < 400) { $remoteContent = $data; }
}
if ($remoteContent === false) { // fallback file_get_contents
  $remoteContent = @file_get_contents($remoteRepoRaw);
}
if ($remoteContent && preg_match('/APP_VERSION\s*=\s*["\']([\d\.]+)["\']/', $remoteContent, $m)) {
  $latestVersion = $m[1];
  $updateAvailable = version_compare($latestVersion, $localVersion, '>');
}
?>
<footer id="app-footer">
  <div class="footer-inner">
    <!-- Left: Service name + version -->
    <div class="footer-service">
      <div class="footer-logo" aria-hidden="true">DS</div>
      <div>
        <div class="service-name">DiscusScan <span class="service-version">v<?=htmlspecialchars($localVersion)?></span></div>
        <div class="footer-company">Последнее обновление: <?=$lastUpdateDate?></div>
        <?php if ($updateAvailable): ?>
          <div style="margin-top:4px"><a class="update-pill" href="/update.php" title="Обновить до v<?=htmlspecialchars($latestVersion)?>">Обновить → v<?=htmlspecialchars($latestVersion)?></a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Center: Animated company brand -->
    <div style="text-align:center;">
      <div id="brand-type" aria-label="BuyReadySite"></div>
      <div class="footer-company">Разработано компанией <strong>BuyReadySite.com</strong></div>
    </div>

    <!-- Right: Links -->
    <div class="footer-links-right">
      <a href="https://buyreadysite.com/" target="_blank" rel="noopener">BuyReadySite</a>
      <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener">AI Content Wizard</a>
      <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener">AI SEO Pro</a>
      <a href="https://top-bit.biz/" target="_blank" rel="noopener">Top‑Bit</a>
      <a href="/support" class="muted-link">Поддержка</a>
    </div>

    <div class="footer-bottom" style="grid-column:1/-1;">
      <div>© <?=date('Y')?> DiscusScan • All rights reserved.</div>
      <div>BuyReadySite</div>
    </div>
  </div>

  <script>(function(){const el=document.getElementById('brand-type');if(!el)return;const t=el.getAttribute('aria-label')||'BuyReadySite';const g='▮░▒▓█BRSDUYAEIOT1234567890';let i=-1,T=null;const STEP=110,PAUSE=5200;function frame(){i++;if(i>=t.length){el.textContent=t;clearInterval(T);setTimeout(start,PAUSE);return;}let out='';for(let k=0;k<t.length;k++){out+=(k<=i)?t[k]:g[(Math.random()*g.length)|0];}el.textContent=out;}function start(){try{if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches){el.textContent=t;return;}}catch(e){}i=-1;if(T)clearInterval(T);T=setInterval(frame,STEP);}start();})();</script>
</footer>