<?php
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$latestVersion = $localVersion; $updateAvailable = false; $lastUpdateDate = '—';
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir,0755,true);
$lastUpdateFile = $dataDir.'/last_update.txt';
$cacheFile = $dataDir.'/latest_remote_version.json';
$remoteRawUrl = 'https://raw.githubusercontent.com/ksanyok/DiscusScan/main/version.php';
// Last update date display
if (is_file($lastUpdateFile)) { $raw = trim(@file_get_contents($lastUpdateFile)); if ($raw) $lastUpdateDate = htmlspecialchars($raw,ENT_QUOTES,'UTF-8'); }
// --- Remote version with 5min cache ---
$now = time(); $cached = null; $TTL = 300; // 5 минут
if (is_file($cacheFile)) {
  $json = @json_decode(@file_get_contents($cacheFile), true);
  if (!empty($json['version']) && !empty($json['checked_at'])) {
    if (($now - (int)$json['checked_at']) < $TTL) { $cached = $json; }
  }
}
if ($cached) {
  $latestVersion = $cached['version'];
} else {
  $remoteContent = false;
  if (function_exists('curl_init')) {
    $ch = curl_init($remoteRawUrl);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>6]);
    $data = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($data!==false && $code>=200 && $code<400) $remoteContent = $data;
  }
  if ($remoteContent === false) $remoteContent = @file_get_contents($remoteRawUrl);
  if ($remoteContent && !$latestVersion && preg_match('/define\s*\(\s*[\'\"]APP_VERSION[\'\"]\s*,\s*[\'\"]([\d\.]+)[\'\"]\s*\)/i', $remoteContent, $m)) {
    $latestVersion = $m[1];
    @file_put_contents($cacheFile, json_encode(['version'=>$latestVersion,'checked_at'=>$now]));
  }
}
$updateAvailable = version_compare($latestVersion, $localVersion, '>');
?>
<footer id="app-footer">
  <div class="footer-inner">
    <div class="footer-service">
      <img src="logo.svg" alt="DiscusScan" class="logo-icon--footer" loading="lazy" width="34" height="34">
      <div>
        <div class="service-name">DiscusScan <span class="service-version">v<?=htmlspecialchars($localVersion)?></span></div>
        <div class="footer-company" style="margin-top:4px;">Последнее обновление: <?=$lastUpdateDate?></div>
        <?php if ($updateAvailable): ?>
          <div style="margin-top:6px"><a class="update-pill" href="/update.php" title="Обновить до v<?=htmlspecialchars($latestVersion)?>">Обновить → v<?=htmlspecialchars($latestVersion)?></a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Center: Animated company brand -->
    <div style="text-align:center;">
      <div id="brand-type" aria-label="BuyReadySite"></div>
      <div class="footer-company">Разработано компанией <strong>BuyReadySite.com</strong></div>
    </div>

    <!-- Right: Links with icons -->
    <div class="footer-links-right">
      <a href="https://buyreadysite.com/" target="_blank" rel="noopener" title="BuyReadySite" aria-label="BuyReadySite">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l9 6-9 6-9-6 9-6z"/><path d="M3 15l9 6 9-6"/></svg>
        <span>BuyReadySite</span>
      </a>
      <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener" title="AI Content Wizard" aria-label="AI Content Wizard">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v4"/><path d="M12 17v4"/><path d="M3 12h4"/><path d="M17 12h4"/><circle cx="12" cy="12" r="3"/><path d="M5.6 5.6l2.8 2.8"/><path d="M15.6 15.6l2.8 2.8"/><path d="M18.4 5.6l-2.8 2.8"/><path d="M8.4 15.6l-2.8 2.8"/></svg>
        <span>AI Content Wizard</span>
      </a>
      <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener" title="AI SEO Pro" aria-label="AI SEO Pro">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
        <span>AI SEO Pro</span>
      </a>
    </div>

    <div class="footer-bottom" style="grid-column:1/-1;">
      <div>© <?=date('Y')?> DiscusScan • All rights reserved.</div>
      <div>BuyReadySite</div>
    </div>
  </div>

  <script>(function(){const el=document.getElementById('brand-type');if(!el)return;const t=el.getAttribute('aria-label')||'BuyReadySite';const g='▮░▒▓█BRSDUYAEIOT1234567890';let i=-1,T=null;const STEP=110,PAUSE=5200;function frame(){i++;if(i>=t.length){el.textContent=t;clearInterval(T);setTimeout(start,PAUSE);return;}let out='';for(let k=0;k<t.length;k++){out+=(k<=i)?t[k]:g[(Math.random()*g.length)|0];}el.textContent=out;}function start(){try{if(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches){el.textContent=t;return;}}catch(e){}i=-1;if(T)clearInterval(T);T=setInterval(frame,STEP);}start();})();</script>
</footer>