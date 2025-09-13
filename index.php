<?php
require_once __DIR__ . '/db.php';
require_login();

// Utility helpers
function json_out($arr){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit;
}

$period = (int)get_setting('scan_period_min', 15);
$cronSecret = (string)get_setting('cron_secret', '');
$cronUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
         . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . rtrim(dirname($_SERVER['SCRIPT_NAME']),'/') . '/scan.php?secret=' . urlencode($cronSecret);

// Mass activate candidates
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='activate_candidates') {
    $rows = pdo()->exec("UPDATE sources SET is_active=1 WHERE is_active=0 AND (note LIKE '%candidate%' OR note LIKE '%cand%')");
    json_out(['ok'=>true,'activated'=>$rows]);
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='activate_candidate' && isset($_POST['id']) && ctype_digit($_POST['id'])) {
    $id=(int)$_POST['id'];
    $row = pdo()->query("SELECT id FROM sources WHERE id={$id} AND is_active=0")->fetch();
    if(!$row) json_out(['ok'=>false,'error'=>'not_candidate']);
    pdo()->exec("UPDATE sources SET is_active=1 WHERE id={$id}");
    json_out(['ok'=>true,'id'=>$id]);
}

// AJAX endpoints
if (isset($_GET['ajax'])) {
    $ajax = $_GET['ajax'];
    if ($ajax==='metrics') {
        $lastScan = pdo()->query("SELECT * FROM scans ORDER BY id DESC LIMIT 1")->fetch() ?: null;
        $lastFinishedTs = $lastScan && $lastScan['finished_at'] ? strtotime($lastScan['finished_at']) : null;
        $guardRemaining = 0;
        if ($lastFinishedTs) {
            $nextAllowed = $lastFinishedTs + $period*60;
            $guardRemaining = max(0, $nextAllowed - time());
        }
        $totalSites = (int)pdo()->query("SELECT COUNT(*) FROM sources WHERE is_active=1")->fetchColumn();
        $candidateCount = (int)pdo()->query("SELECT COUNT(*) FROM sources WHERE is_active=0 AND (note LIKE '%candidate%' OR note LIKE '%cand%')")->fetchColumn();
        $totalLinks = (int)pdo()->query("SELECT COUNT(*) FROM links")->fetchColumn();
        $new24 = (int)pdo()->query("SELECT COUNT(*) FROM links WHERE first_found >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
        $new7d = (int)pdo()->query("SELECT COUNT(*) FROM links WHERE first_found >= NOW() - INTERVAL 7 DAY")->fetchColumn();
        // Sparkline (last 7 days new links)
        $rows = pdo()->query("SELECT DATE(first_found) d, COUNT(*) c FROM links WHERE first_found >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(first_found)")->fetchAll(PDO::FETCH_KEY_PAIR);
        $spark = [];
        for($i=6;$i>=0;$i--){ $d=date('Y-m-d', strtotime("-$i day")); $spark[] = ['d'=>$d,'c'=> (int)($rows[$d]??0)]; }
        json_out([
            'ok'=>true,
            'metrics'=>[
                'last_scan_at'=>$lastScan && $lastScan['finished_at'] ? $lastScan['finished_at'] : null,
                'last_scan_new'=>$lastScan ? (int)$lastScan['new_links'] : 0,
                'last_scan_found'=>$lastScan ? (int)$lastScan['found_links'] : 0,
                'total_links'=>$totalLinks,
                'active_sources'=>$totalSites,
                'candidates'=>$candidateCount,
                'new_24h'=>$new24,
                'new_7d'=>$new7d,
                'guard_remaining'=>$guardRemaining,
                'period_min'=>$period,
                'spark'=>$spark
            ]
        ]);
    } elseif ($ajax==='scans') {
        $rows = pdo()->query("SELECT id, started_at, finished_at, found_links, new_links, status FROM scans ORDER BY id DESC LIMIT 12")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $dur = null;
            if ($r['finished_at']) $dur = strtotime($r['finished_at']) - strtotime($r['started_at']);
            $out[] = [
                'id'=>(int)$r['id'],
                'started_at'=>$r['started_at'],
                'finished_at'=>$r['finished_at'],
                'duration_sec'=>$dur,
                'found'=>(int)$r['found_links'],
                'new'=>(int)$r['new_links'],
                'status'=>$r['status']
            ];
        }
        json_out(['ok'=>true,'scans'=>$out]);
    } elseif ($ajax==='daily') {
        $rows = pdo()->query("SELECT DATE(first_found) d, COUNT(*) c, COUNT(DISTINCT source_id) u FROM links WHERE first_found >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(first_found)")->fetchAll(PDO::FETCH_ASSOC);
        $map = []; foreach($rows as $r){ $map[$r['d']] = $r; }
        $days = [];
        for($i=6;$i>=0;$i--){ $d=date('Y-m-d', strtotime("-$i day")); $row=$map[$d]??['d'=>$d,'c'=>0,'u'=>0]; $days[]=$row; }
        json_out(['ok'=>true,'days'=>$days]);
    } elseif ($ajax==='top_domains') {
        $rows = pdo()->query("SELECT s.id,s.host,s.is_active,s.note,\n            (SELECT COUNT(*) FROM links l WHERE l.source_id=s.id AND l.first_found >= NOW() - INTERVAL 24 HOUR) AS new_24,\n            (SELECT COUNT(*) FROM links l WHERE l.source_id=s.id AND l.first_found >= NOW() - INTERVAL 7 DAY) AS new_7d,\n            (SELECT COUNT(*) FROM links l WHERE l.source_id=s.id) AS total\n            FROM sources s ORDER BY new_7d DESC, total DESC LIMIT 25")->fetchAll();
        json_out(['ok'=>true,'domains'=>$rows]);
    } elseif ($ajax==='links') {
        $limit = 50; $offset = isset($_GET['offset']) && ctype_digit($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $domain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
        $q = isset($_GET['q']) ? trim($_GET['q']) : '';
        $range = $_GET['range'] ?? '24h';
        $conds = []; $params=[];
        if ($domain !== '') { $conds[] = 's.host = ?'; $params[]=$domain; }
        if ($q !== '') { $conds[] = '(l.title LIKE ? OR l.url LIKE ?)'; $params[]='%'.$q.'%'; $params[]='%'.$q.'%'; }
        if ($range==='24h') $conds[] = 'l.first_found >= NOW() - INTERVAL 24 HOUR';
        elseif ($range==='7d') $conds[] = 'l.first_found >= NOW() - INTERVAL 7 DAY';
        $where = $conds ? ('WHERE '.implode(' AND ',$conds)) : '';
        $sql = "SELECT l.id,l.url,l.title,l.first_found,l.last_seen,l.times_seen,l.status,s.host FROM links l JOIN sources s ON s.id=l.source_id $where ORDER BY COALESCE(l.last_seen,l.first_found) DESC LIMIT $limit OFFSET $offset";
        $stmt = pdo()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
        // check if more
        $sql2 = "SELECT COUNT(*) FROM links l JOIN sources s ON s.id=l.source_id $where"; $stmt2=pdo()->prepare($sql2); $stmt2->execute($params); $total=(int)$stmt2->fetchColumn();
        json_out(['ok'=>true,'links'=>$rows,'offset'=>$offset,'limit'=>$limit,'total'=>$total]);
    } elseif ($ajax==='candidates') {
        // FIX: MySQL не поддерживает "NULLS LAST" — используем (first_found IS NULL) сортировку.
        // Добавлен try/catch чтобы при ошибке SQL не падал весь дашборд.
        try {
            $rows = pdo()->query("SELECT s.id,s.host,s.note,s.is_active, MIN(l.first_found) first_found, COUNT(l.id) link_count FROM sources s LEFT JOIN links l ON l.source_id=s.id WHERE s.is_active=0 AND (s.note LIKE '%candidate%' OR s.note LIKE '%cand%') GROUP BY s.id ORDER BY (first_found IS NULL), first_found DESC, s.id DESC LIMIT 100")->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
        json_out(['ok'=>true,'candidates'=>$rows]);
    } else {
        json_out(['ok'=>false,'error'=>'unknown_ajax']);
    }
}

// Basic initial data (lightweight fallback if JS disabled)
$lastScan = pdo()->query("SELECT * FROM scans ORDER BY id DESC LIMIT 1")->fetch() ?: null;
$totalSites = (int)pdo()->query("SELECT COUNT(*) FROM sources WHERE is_active=1")->fetchColumn();
$totalLinks = (int)pdo()->query("SELECT COUNT(*) FROM links")->fetchColumn();
$lastFound = $lastScan ? (int)$lastScan['found_links'] : 0;
$lastScanAtFmt = $lastScan && $lastScan['finished_at'] ? date('Y-m-d H:i', strtotime($lastScan['finished_at'])) : '—';
$recentLinks = pdo()->query("SELECT l.*, s.host FROM links l JOIN sources s ON s.id=l.source_id ORDER BY COALESCE(l.last_seen,l.first_found) DESC LIMIT 10")->fetchAll();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Дашборд — Мониторинг</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Dashboard redesign additions */
    .metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:18px}
    .metric-card{padding:14px 16px;border:1px solid var(--border);border-radius:14px;background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.02));backdrop-filter:blur(8px);position:relative;overflow:hidden}
    .metric-card h4{margin:0 0 4px;font-size:12px;letter-spacing:.5px;font-weight:600;text-transform:uppercase;color:var(--muted)}
    .metric-val{font-size:26px;font-weight:700;line-height:1.1}
    .metric-sub{font-size:11px;color:var(--muted);margin-top:2px}
    .spark{position:absolute;bottom:4px;left:4px;right:4px;height:28px;opacity:.55}
    .scan-control{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-bottom:12px}
    .guard-badge{display:inline-flex;align-items:center;font-size:11px;padding:4px 8px;border-radius:30px;background:#13214a;color:var(--muted);gap:6px}
    .history-table th,.history-table td{padding:4px 6px;font-size:12px}
    .history-new{color:#2ecc71;font-weight:600}
    .history-zero{color:#ffb347;font-weight:600}
    .daily-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px}
    .daily-col{padding:10px 10px 12px;border:1px solid var(--border);border-radius:12px;background:#0f1733;display:flex;flex-direction:column;gap:6px}
    .daily-col .day{font-size:13px;font-weight:600}
    .bar-wrap{height:42px;display:flex;align-items:flex-end;gap:4px}
    .bar{flex:1;display:flex;flex-direction:column;justify-content:flex-end}
    .bar span{display:block;border-radius:4px 4px 2px 2px;background:var(--grad-primary)}
    .bar .u{background:var(--grad-coral);margin-top:2px}
    .top-domains-extended table{width:100%;border-collapse:collapse}
    .top-domains-extended th,.top-domains-extended td{padding:6px 8px;border-bottom:1px solid var(--border);font-size:12px;text-align:left;white-space:nowrap}
    .dom-bar{height:6px;border-radius:4px;background:linear-gradient(90deg,var(--pri),var(--pri-2));box-shadow:0 1px 3px rgba(0,0,0,.3);min-width:4px}
    .links-filters{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px}
    .links-filters input, .links-filters select{padding:8px 10px;font-size:13px;border-radius:10px;border:1px solid var(--border);background:#0f1733;color:#fff;width:auto}
    .links-list{width:100%;border-collapse:collapse}
    .links-list th,.links-list td{padding:6px 8px;font-size:12px;border-bottom:1px solid var(--border);white-space:nowrap;vertical-align:top}
    .link-row{cursor:pointer}
    .link-row:hover{background:rgba(255,255,255,.05)}
    .link-detail{background:rgba(255,255,255,.04);animation:fadeIn .25s ease}
    @keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
    .status-new{background:#13214a;padding:2px 6px;border-radius:6px;font-size:11px}
    .candidate-badge{background:linear-gradient(135deg,#6f42c1,#8e5bd6);color:#fff;padding:2px 6px;border-radius:6px;font-size:11px}
    .candidates-list th,.candidates-list td{padding:6px 8px;font-size:12px;border-bottom:1px solid var(--border);white-space:nowrap}
    .quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;width:100%}
    .modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50}
    .modal{background:#10203f;border:1px solid var(--border);border-radius:16px;max-width:480px;width:90%;padding:22px;box-shadow:0 18px 60px rgba(0,0,0,.45)}
    .modal h3{margin:0 0 10px;font-size:18px}
    .modal p{margin:0 0 14px;font-size:14px;color:var(--muted)}
    .modal .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:4px}
    .danger-btn{background:var(--grad-coral);color:#2a0e08;font-weight:700}
    .auto-refresh-toggle{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);margin-left:auto}
    #dashboardMain{display:flex;flex-direction:column;gap:18px}
    .btn.small{ padding:8px 12px; border-radius:10px; font-size:13px; }
    .btn-ghost.small{ padding:8px 12px; }
    .quick-actions .btn{ width:100%; justify-content:center; }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="container" id="dashboardMain">

  <!-- Metrics (initial fallback static) -->
  <section class="metrics-grid" id="metricsGrid" aria-live="polite">
    <div class="metric-card"><h4>Последний скан</h4><div class="metric-val"><?=e($lastScanAtFmt)?></div><div class="metric-sub" id="m-last-age">—</div><svg class="spark" id="spark-total"></svg></div>
    <div class="metric-card"><h4>Активных</h4><div class="metric-val" id="m-active"><?=$totalSites?></div><div class="metric-sub">sources</div></div>
    <div class="metric-card"><h4>Всего ссылок</h4><div class="metric-val" id="m-total"><?=$totalLinks?></div><div class="metric-sub">aggregate</div></div>
    <div class="metric-card"><h4>Последний проход</h4><div class="metric-val" id="m-last-found"><?=$lastFound?></div><div class="metric-sub">found links</div></div>
    <div class="metric-card"><h4>24ч</h4><div class="metric-val" id="m-24h">—</div><div class="metric-sub">new links</div></div>
    <div class="metric-card"><h4>Кандидатов</h4><div class="metric-val" id="m-cand">—</div><div class="metric-sub">pending</div></div>
  </section>

  <!-- Scan control + history -->
  <section class="card glass" id="scanControl">
    <div class="scan-control">
      <button class="btn primary" id="scanBtn" data-url="scan.php?manual=1" type="button">🚀 Запустить скан</button>
      <div class="guard-badge" id="guardInfo">Guard: —</div>
      <div class="auto-refresh-toggle"><label style="display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" id="autoRefresh" style="margin:0"> <span>Автообновление</span></label></div>
    </div>
    <div class="table-wrap" style="max-height:240px">
      <table class="table history-table" id="scanHistoryTbl">
        <thead><tr><th>ID</th><th>Старт</th><th>Финиш</th><th>Длит</th><th>New</th><th>Total</th><th>Статус</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- Daily trend -->
  <section class="card glass" id="dailyTrend">
    <div class="card-title">Активность (7 дней)</div>
    <div class="daily-grid" id="dailyGrid"></div>
  </section>

  <!-- Top domains extended -->
  <section class="card glass top-domains-extended" id="topDomainsExt">
    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">Топ доменов <small id="topDomCount" class="muted-inline"></small></div>
    <div class="table-wrap" style="max-height:300px">
      <table id="topDomainsTable">
        <thead><tr><th>Домен</th><th>24h</th><th>7d</th><th>Total</th><th>%</th><th>Статус</th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- Recent links with filters -->
  <section class="card glass" id="recentLinksBlock">
    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">Последние ссылки <small id="linksTotal" class="muted-inline"></small></div>
    <div class="links-filters">
      <input type="search" id="linksSearch" placeholder="Поиск..." aria-label="Поиск ссылок">
      <select id="linksDomain"><option value="">Все домены</option></select>
      <select id="linksRange"><option value="24h">24 часа</option><option value="7d">7 дней</option><option value="all">Все</option></select>
      <button class="btn btn-ghost small" id="linksReload" type="button">Обновить</button>
      <div style="margin-left:auto;display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted)"><span id="linksOffsetInfo"></span></div>
    </div>
    <div class="table-wrap" style="max-height:380px">
      <table class="links-list" id="linksTable">
        <thead><tr><th>Домен</th><th>Заголовок</th><th>URL</th><th>Найдено</th><th>Обн.</th><th>Пок.</th></tr></thead>
        <tbody>
          <?php foreach($recentLinks as $l): ?>
            <tr class="link-row"><td><?=e($l['host'])?></td><td class="ellipsis" style="max-width:220px;"><?=e($l['title'] ?: '—')?></td><td class="ellipsis" style="max-width:320px;"><a href="<?=e($l['url'])?>" target="_blank" rel="noopener"><?=e($l['url'])?></a></td><td><?= $l['first_found']?date('d.m H:i',strtotime($l['first_found'])):'—'?></td><td><?= $l['last_seen']?date('d.m H:i',strtotime($l['last_seen'])):'—'?></td><td><?= (int)$l['times_seen']?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:10px;margin-top:8px;">
      <button class="btn btn-ghost small" id="linksMore" type="button">Показать ещё</button>
    </div>
  </section>

  <!-- Candidates management -->
  <section class="card glass" id="candidatesBlock">
    <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;gap:10px;">Кандидаты доменов
      <button type="button" class="btn btn-ghost small" id="activateAllCand">Активировать все</button>
    </div>
    <p class="muted-inline" style="margin:6px 0 10px;font-size:12px;">Здесь показываются неактивные домены с пометкой <code>candidate</code>, найденные автоматически. Активируйте их, чтобы добавить в регулярные сканы.</p>
    <div class="table-wrap" style="max-height:280px">
      <table class="candidates-list" id="candTable">
        <thead><tr><th>Домен</th><th>Ссылок</th><th>Первая ссылка</th><th>Note</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <!-- Quick actions -->
  <section class="card glass" id="quickActions">
    <div class="card-title">Быстрые действия</div>
    <div class="quick-actions">
      <a class="btn btn-ghost small" href="settings.php">⚙️ Настройки</a>
      <a class="btn btn-ghost small" href="sources.php">🌐 Домены</a>
      <button class="btn btn-ghost small" id="openClearModal" type="button">🧹 Очистить данные</button>
      <a class="btn btn-ghost small" href="scan.php?manual=1" target="_blank" rel="noopener">🚀 Форс-проход</a>
      <button class="btn btn-ghost small" id="refreshAll" type="button">🔁 Обновить всё</button>
    </div>
  </section>

</main>
<?php include 'footer.php'; ?>
<div id="modalRoot"></div>
<script>
const fmtDuration = s=> s==null? '—' : (s<60? (s+'s') : ( (s/60).toFixed(1)+'m'));
const timeAgo = iso=>{ if(!iso) return '—'; const t=new Date(iso.replace(' ','T')); const diff=(Date.now()-t.getTime())/1000; if(diff<60) return Math.floor(diff)+'s ago'; if(diff<3600) return Math.floor(diff/60)+'m ago'; if(diff<86400) return Math.floor(diff/3600)+'h ago'; return Math.floor(diff/86400)+'d ago'; };

// METRICS
async function loadMetrics(){
  const r = await fetch('index.php?ajax=metrics'); const j = await r.json(); if(!j.ok) return;
  const m=j.metrics; const lastAge = document.getElementById('m-last-age');
  if(m.last_scan_at){ lastAge.textContent = timeAgo(m.last_scan_at); }
  document.getElementById('m-active').textContent = m.active_sources; document.getElementById('m-total').textContent=m.total_links; document.getElementById('m-last-found').textContent=m.last_scan_found; document.getElementById('m-24h').textContent=m.new_24h; document.getElementById('m-cand').textContent=m.candidates;
  // guard
  const guardEl = document.getElementById('guardInfo');
  let remain = m.guard_remaining; const btn = document.getElementById('scanBtn');
  if(remain>0){ btn.disabled=true; guardEl.textContent='guard '+remain+'s'; const iv=setInterval(()=>{remain--; guardEl.textContent='guard '+remain+'s'; if(remain<=0){clearInterval(iv); btn.disabled=false; guardEl.textContent='готов';}},1000);} else { guardEl.textContent='готов'; btn.disabled=false; }
  // sparkline
  drawSpark(document.getElementById('spark-total'), m.spark.map(x=>x.c));
}
function drawSpark(svg, data){ if(!svg) return; const w=svg.clientWidth||160; const h=svg.clientHeight||28; const max=Math.max(...data,1); const step=w/(data.length-1); let d=''; data.forEach((v,i)=>{ const x=i*step; const y=h - (v/max)* (h-4) -2; d += (i?'L':'M')+x+','+y;}); svg.setAttribute('viewBox','0 0 '+w+' '+h); svg.innerHTML='<path d="'+d+'" fill="none" stroke="url(#g)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />'+`<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#5b8cff"/><stop offset="1" stop-color="#7ea2ff"/></linearGradient></defs>`; }

// SCAN HISTORY
async function loadScanHistory(){ const r=await fetch('index.php?ajax=scans'); const j=await r.json(); if(!j.ok)return; const tb=document.querySelector('#scanHistoryTbl tbody'); tb.innerHTML=''; j.scans.forEach(s=>{ const tr=document.createElement('tr'); tr.innerHTML=`<td>${s.id}</td><td>${s.started_at.slice(5,16)}</td><td>${s.finished_at? s.finished_at.slice(5,16):'—'}</td><td>${fmtDuration(s.duration_sec)}</td><td class="${s.new? 'history-new':'history-zero'}">${s.new}</td><td>${s.found}</td><td>${s.status}</td>`; tb.appendChild(tr);}); }

// DAILY TREND
async function loadDaily(){ const r=await fetch('index.php?ajax=daily'); const j=await r.json(); if(!j.ok)return; const wrap=document.getElementById('dailyGrid'); wrap.innerHTML=''; const max=Math.max(...j.days.map(d=>d.c),1); j.days.forEach(d=>{ const col=document.createElement('div'); col.className='daily-col'; const pct = d.c? ((d.c/max)*100):0; const pctU = d.u? ((d.u/max)*100):0; col.innerHTML=`<div class="day">${d.d.slice(5)}</div><div style="font-size:12px;color:var(--muted)">${d.c} links / ${d.u} dom</div><div class="bar-wrap"><div class="bar" style="height:100%"><span style="height:${pct||4}%;"></span><span class="u" style="height:${pctU||4}%;"></span></div></div>`; wrap.appendChild(col); }); }

// TOP DOMAINS
async function loadTopDomains(){ const r=await fetch('index.php?ajax=top_domains'); const j=await r.json(); if(!j.ok)return; const tb=document.querySelector('#topDomainsTable tbody'); tb.innerHTML=''; let totalAll= j.domains.reduce((a,b)=>a+parseInt(b.total),0)||1; j.domains.forEach(d=>{ const share = d.total? ((d.total/totalAll)*100):0; const tr=document.createElement('tr'); const status = d.is_active? '✅':'⏸'; tr.innerHTML=`<td><a href="sources.php?source=${d.id}">${escapeHtml(d.host)}</a></td><td>${d.new_24}</td><td>${d.new_7d}</td><td>${d.total}</td><td><div class="dom-bar" style="width:${Math.max(4,share*1.4)}px" title="${share.toFixed(1)}%"></div></td><td>${status}</td>`; tb.appendChild(tr); }); document.getElementById('topDomCount').textContent='('+j.domains.length+')'; // fill domain filter
  const sel=document.getElementById('linksDomain'); const cur=sel.value; const added=new Set(); Array.from(sel.options).forEach((o,i)=>{ if(i>0) sel.removeChild(o);}); j.domains.forEach(d=>{ if(!added.has(d.host)){ const opt=document.createElement('option'); opt.value=d.host; opt.textContent=d.host; sel.appendChild(opt); added.add(d.host);} }); if(cur) sel.value=cur; }

// RECENT LINKS + filters
let linksOffset=0; let linksTotal=0; let linksRange='24h'; let linksDomain=''; let linksQ='';
async function loadLinks(reset=false){ if(reset){ linksOffset=0; } const params=new URLSearchParams({ajax:'links', offset:linksOffset, range:linksRange}); if(linksDomain) params.append('domain',linksDomain); if(linksQ) params.append('q',linksQ); const r=await fetch('index.php?'+params.toString()); const j=await r.json(); if(!j.ok)return; linksTotal=j.total; document.getElementById('linksTotal').textContent='Всего: '+linksTotal; const tb=document.querySelector('#linksTable tbody'); if(reset) tb.innerHTML=''; j.links.forEach(l=>{ const tr=document.createElement('tr'); tr.className='link-row'; tr.dataset.id=l.id; tr.innerHTML=`<td>${escapeHtml(l.host)}</td><td class="ellipsis" style="max-width:240px;">${escapeHtml(l.title||'—')}</td><td class="ellipsis" style="max-width:340px;"><a href="${escapeHtml(l.url)}" target="_blank" rel="noopener">${escapeHtml(l.url)}</a></td><td>${l.first_found? l.first_found.slice(5,16):'—'}</td><td>${l.last_seen? l.last_seen.slice(5,16):'—'}</td><td>${l.times_seen||1}</td>`; tb.appendChild(tr); }); linksOffset += j.links.length; document.getElementById('linksOffsetInfo').textContent = linksOffset + ' / ' + linksTotal; const moreBtn=document.getElementById('linksMore'); moreBtn.disabled = linksOffset >= linksTotal; }

// INLINE link detail toggle
const linksTable=document.getElementById('linksTable');
linksTable.addEventListener('click',e=>{ const row=e.target.closest('.link-row'); if(!row) return; const next=row.nextElementSibling; if(next && next.classList.contains('link-detail')){ next.remove(); return; } if(next && next.classList.contains('link-row')){} else if(next){ next.remove(); } const detail=document.createElement('tr'); detail.className='link-detail'; detail.innerHTML=`<td colspan="6" style="font-size:12px;padding:10px 12px;">ID: ${row.dataset.id} • <a href="sources.php" style="text-decoration:none">Домены</a> • <span class="muted-inline">Подробнее функционал позже</span></td>`; row.parentNode.insertBefore(detail,row.nextSibling); });

// CANDIDATES
async function loadCandidates(){ const r=await fetch('index.php?ajax=candidates'); const j=await r.json(); if(!j.ok)return; const tb=document.querySelector('#candTable tbody'); tb.innerHTML=''; if(!j.candidates.length){ tb.innerHTML='<tr><td colspan="5" style="font-size:12px;color:var(--muted)">Нет кандидатов</td></tr>'; return; } j.candidates.forEach(c=>{ const tr=document.createElement('tr'); tr.innerHTML=`<td>${escapeHtml(c.host)}</td><td>${c.link_count}</td><td>${c.first_found? c.first_found.slice(5,16):'—'}</td><td class="ellipsis" style="max-width:200px;">${escapeHtml(c.note||'candidate')}</td><td><button class="btn small" data-act-cand="${c.id}">Активировать</button></td>`; tb.appendChild(tr); }); }

// Quick actions: candidates activation
 document.getElementById('activateAllCand').addEventListener('click', async()=>{ if(!confirm('Активировать все кандидаты?')) return; const r=await fetch('index.php',{method:'POST',body:new URLSearchParams({action:'activate_candidates'})}); const j=await r.json(); if(j.ok){ loadCandidates(); loadMetrics(); loadTopDomains(); }});
 document.getElementById('candTable').addEventListener('click', async e=>{ const btn=e.target.closest('button[data-act-cand]'); if(!btn) return; const id=btn.getAttribute('data-act-cand'); btn.disabled=true; btn.textContent='...'; const r=await fetch('index.php',{method:'POST',body:new URLSearchParams({action:'activate_candidate',id})}); const j=await r.json(); if(j.ok){ btn.textContent='OK'; setTimeout(()=>{loadCandidates(); loadMetrics(); loadTopDomains();},500);} else { btn.textContent='ERR'; } });

// Scan button
 document.getElementById('scanBtn').addEventListener('click',()=>{ const u=document.getElementById('scanBtn').dataset.url; window.open(u,'_blank'); setTimeout(()=>{ refreshAll(); }, 4000); });

// Links filters events
 document.getElementById('linksReload').addEventListener('click',()=> loadLinks(true));
 document.getElementById('linksMore').addEventListener('click',()=> loadLinks(false));
 document.getElementById('linksRange').addEventListener('change',e=>{ linksRange=e.target.value; loadLinks(true); });
 document.getElementById('linksDomain').addEventListener('change',e=>{ linksDomain=e.target.value; loadLinks(true); });
 document.getElementById('linksSearch').addEventListener('input', debounce(()=>{ linksQ=document.getElementById('linksSearch').value.trim(); loadLinks(true); },400));

function debounce(fn,ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); } }
function escapeHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

// Modal for clear data
 const modalRoot=document.getElementById('modalRoot');
 document.getElementById('openClearModal').addEventListener('click',()=>{
   modalRoot.innerHTML=`<div class='modal-backdrop'><div class='modal'><h3>Очистить данные?</h3><p>Будут очищены таблицы ссылок и статистика (links/topics/scans/runs/domains/sources). Конфигурация сохранится. Действие нельзя отменить.</p><div class='actions'><button class='qa-btn' id='cancelModal'>Отмена</button><a class='qa-btn danger-btn' href='settings.php?action=clear_data' id='doClear'>Да, очистить</a></div></div></div>`;
 });
 modalRoot.addEventListener('click',e=>{ if(e.target.id==='cancelModal' || e.target===modalRoot.querySelector('.modal-backdrop')) modalRoot.innerHTML=''; });

// Refresh routines
function refreshAll(){ loadMetrics(); loadScanHistory(); loadDaily(); loadTopDomains(); loadLinks(true); loadCandidates(); }
 document.getElementById('refreshAll').addEventListener('click',()=> refreshAll());

// Auto refresh
 const autoChk=document.getElementById('autoRefresh'); let autoTimer=null; autoChk.addEventListener('change',()=>{ if(autoChk.checked){ autoTimer=setInterval(()=>{ refreshAll(); }, 60000); } else { clearInterval(autoTimer); autoTimer=null; } });

// Initial load
refreshAll();
</script>
</body>
</html>