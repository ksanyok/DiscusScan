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
    <!-- Brand left -->
    <div class="footer-brand">
      <div class="footer-logo" aria-hidden="true">DS</div>
      <div>
        <div id="brand-type" aria-label="DiscusScan"></div>
        <div class="footer-meta">Мониторинг ссылок</div>
      </div>
    </div>

    <!-- Center info -->
    <div class="footer-center">
      <div>v<?= htmlspecialchars($localVersion) ?><?php if($updateAvailable): ?> → доступна v<?= htmlspecialchars($latestVersion) ?><?php endif; ?></div>
      <div>Последнее обновление: <?= $lastUpdateDate ?></div>
    </div>

    <!-- Right links / actions -->
    <div class="footer-right">
      <?php if ($updateAvailable): ?>
        <a class="update-pill" href="/update.php" title="Обновить до v<?= htmlspecialchars($latestVersion) ?>">Обновить v<?= htmlspecialchars($latestVersion) ?></a>
      <?php endif; ?>
      <a href="https://buyreadysite.com/" target="_blank" rel="noopener">BuyReadySite</a>
      <a href="https://aiwizard.buyreadysite.com/" target="_blank" rel="noopener">AI Content Wizard</a>
      <a href="https://aiseo.buyreadysite.com/" target="_blank" rel="noopener">AI SEO Pro</a>
      <a href="https://top-bit.biz/" target="_blank" rel="noopener">Top‑Bit</a>
      <a href="/support" class="muted-link">Поддержка</a>
    </div>

    <!-- Bottom line spans full width -->
    <div class="footer-bottom">
      <div>© <?= date('Y') ?> DiscusScan</div>
      <div>Все права защищены</div>
    </div>
  </div>

  <script>
  (function(){
    const el = document.getElementById('brand-type'); if(!el) return;
    const target = el.getAttribute('aria-label')||'DiscusScan';
    const glyphs = '▮░▒▓█ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
    let i=-1, timer=null; const STEP=120, PAUSE=5000;
    function frame(){ i++; if(i>=target.length){ el.textContent=target; clearInterval(timer); setTimeout(start, PAUSE); return; }
      let out=''; for(let k=0;k<target.length;k++){ out += (k<=i)? target[k] : glyphs[(Math.random()*glyphs.length)|0]; }
      el.textContent=out; }
    function start(){ try{ if(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches){ el.textContent=target; return; } }catch(e){}
      i=-1; if(timer) clearInterval(timer); timer=setInterval(frame, STEP); }
    start();
  })();
  </script>
</footer>