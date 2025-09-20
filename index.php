<?php
require_once __DIR__ . '/db.php';
require_login();

// Start output buffering to prevent stray output from corrupting JSON
if (!headers_sent()) { @ob_start(); }

// Utility helpers
function json_out($arr){
    // Clear any previous output to keep JSON clean
    if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit;
}

// If this is an ajax/json request, don't display PHP warnings in response body
if (isset($_GET['ajax']) || (isset($_POST['action']) && $_POST['action'])) {
    @ini_set('display_errors','0');
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
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='cancel_scan') {
    $scan = pdo()->query("SELECT id FROM scans WHERE status IN ('running','started') ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!$scan){
        json_out(['ok'=>false,'error'=>'no_running']);
    }
    $stmt = pdo()->prepare("UPDATE scans SET status='cancelled', finished_at=NOW(), error='cancelled by user' WHERE id=?");
    $stmt->execute([(int)$scan['id']]);
    app_log('info','scan','cancel_requested',['scan_id'=>(int)$scan['id'],'user'=>$_SESSION['user']??null]);
    json_out(['ok'=>true,'id'=>(int)$scan['id']]);
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
        $rows = pdo()->query("SELECT id, started_at, finished_at, found_links, new_links, status, error FROM scans ORDER BY id DESC LIMIT 12")->fetchAll();
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
                'status'=>$r['status'],
                'error'=>$r['error']
            ];
        }
        json_out(['ok'=>true,'scans'=>$out]);
    } elseif ($ajax==='scan_status') {
        $row = pdo()->query("SELECT id, started_at, finished_at, found_links, new_links, status, error FROM scans ORDER BY id DESC LIMIT 1")->fetch();
        if (!$row) {
            json_out(['ok'=>false,'error'=>'no_scans']);
        }
        $dur = null;
        if ($row['finished_at']) {
            $dur = strtotime($row['finished_at']) - strtotime($row['started_at']);
        } elseif ($row['started_at']) {
            $dur = time() - strtotime($row['started_at']);
        }
        json_out(['ok'=>true,'scan'=>[
            'id'=>(int)$row['id'],
            'started_at'=>$row['started_at'],
            'finished_at'=>$row['finished_at'],
            'duration_sec'=>$dur,
            'found'=>(int)$row['found_links'],
            'new'=>(int)$row['new_links'],
            'status'=>$row['status'],
            'error'=>$row['error']
        ]]);
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
        $sql = "SELECT l.id,l.url,l.title,l.first_found,l.last_seen,l.content_updated_at,l.times_seen,l.status,s.host FROM links l JOIN sources s ON s.id=l.source_id $where ORDER BY COALESCE(l.content_updated_at,l.last_seen,l.first_found) DESC LIMIT $limit OFFSET $offset";
        $stmt = pdo()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
        // check if more
        $sql2 = "SELECT COUNT(*) FROM links l JOIN sources s ON s.id=l.source_id $where"; $stmt2=pdo()->prepare($sql2); $stmt2->execute($params); $total=(int)$stmt2->fetchColumn();
        json_out(['ok'=>true,'links'=>$rows,'offset'=>$offset,'limit'=>$limit,'total'=>$total]);
    } elseif ($ajax==='candidates') {
        // MySQL doesn't support "NULLS LAST"; emulate with boolean expression
        // Also be compatible with ONLY_FULL_GROUP_BY by grouping all selected non-aggregated columns
        $rows = pdo()->query(
            "SELECT 
                s.id,
                s.host,
                s.note,
                s.is_active,
                MIN(l.first_found) AS first_found,
                COUNT(l.id) AS link_count
             FROM sources s
             LEFT JOIN links l ON l.source_id = s.id
             WHERE s.is_active = 0
               AND (s.note LIKE '%candidate%' OR s.note LIKE '%cand%')
             GROUP BY s.id, s.host, s.note, s.is_active
             ORDER BY (first_found IS NULL) ASC, first_found DESC, s.id DESC
             LIMIT 100"
        )->fetchAll();
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
$recentLinks = pdo()->query("SELECT l.*, s.host FROM links l JOIN sources s ON s.id=l.source_id ORDER BY COALESCE(l.content_updated_at,l.last_seen,l.first_found) DESC LIMIT 10")->fetchAll();
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
    .btn.danger{background:linear-gradient(135deg,#ff8a8a,#ff5757);color:#2a0e08;font-weight:600;}
    .history-error{color:#ff7b7b;font-weight:600}
    .history-running{color:#f7c948;font-weight:600}
    .history-done{color:#2ecc71;font-weight:600}
    .scan-overlay{position:fixed;inset:0;background:rgba(7,12,28,.76);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:60;padding:20px}
    .scan-overlay.active{display:flex}
    .scan-dialog{width:min(520px,100%);background:#0f1d3a;border:1px solid var(--border);border-radius:20px;padding:24px;position:relative;box-shadow:0 18px 48px rgba(0,0,0,.55)}
    .scan-header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
    .scan-status{display:flex;align-items:center;gap:14px}
    .scan-status h3{margin:0;font-size:20px}
    .scan-status p{margin:4px 0 0;font-size:13px;color:var(--muted)}
    .scan-close{background:none;border:none;color:var(--muted);font-size:20px;cursor:pointer;padding:2px 6px}
    .scan-spinner{width:38px;height:38px;border-radius:50%;border:3px solid rgba(255,255,255,.14);border-top-color:#7ea2ff;animation:spin 1s linear infinite}
    .scan-progress{display:flex;gap:14px;margin:22px 0}
    .scan-metric{flex:1;padding:12px;border-radius:14px;background:rgba(255,255,255,.05);text-align:center}
    .scan-metric__value{display:block;font-size:26px;font-weight:700}
    .scan-metric__label{display:block;font-size:11px;margin-top:4px;letter-spacing:.4px;color:var(--muted);text-transform:uppercase}
    .scan-log{font-size:12px;color:#ff9c9c;background:rgba(255,82,82,.12);border:1px solid rgba(255,82,82,.32);border-radius:12px;padding:12px;margin-bottom:18px;white-space:pre-line}
    .scan-actions{display:flex;justify-content:flex-end;gap:12px}
    .scan-dialog button[hidden]{display:none!important}
    @keyframes spin{to{transform:rotate(360deg);}}
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
      <button class="btn primary" id="scanBtn" type="button">🚀 Запустить скан</button>
      <button class="btn" id="scanResumeBtn" type="button" style="display:none;">↻ Продолжить</button>
      <button class="btn danger" id="scanCancelBtn" type="button" style="display:none;">⛔ Прервать</button>
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
        <thead><tr><th>Домен</th><th>Заголовок</th><th>URL</th><th>Найдено</th><th>Контент</th><th>Обновл.</th><th>Пок.</th></tr></thead>
        <tbody>
          <?php foreach($recentLinks as $l): ?>
            <tr class="link-row"><td><?=e($l['host'])?></td><td class="ellipsis" style="max-width:220px;"><?=e($l['title'] ?: '—')?></td><td class="ellipsis" style="max-width:320px;"><a href="<?=e($l['url'])?>" target="_blank" rel="noopener"><?=e($l['url'])?></a></td><td><?= $l['first_found']?date('d.m H:i',strtotime($l['first_found'])):'—'?></td><td><?= $l['content_updated_at']?date('d.m H:i',strtotime($l['content_updated_at'])):'—'?></td><td><?= $l['last_seen']?date('d.m H:i',strtotime($l['last_seen'])):'—'?></td><td><?= (int)$l['times_seen']?></td></tr>
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
<div id="scanOverlay" class="scan-overlay" aria-hidden="true">
  <div class="scan-dialog" role="dialog" aria-modal="true" aria-labelledby="scanOverlayTitle">
    <div class="scan-header">
      <div class="scan-status">
        <div class="scan-spinner" id="scanSpinner"></div>
        <div>
          <h3 id="scanOverlayTitle">Мониторинг запускается…</h3>
          <p id="scanStatusText">Подготавливаем запросы.</p>
        </div>
      </div>
      <button type="button" class="scan-close" id="scanOverlayClose" aria-label="Скрыть">✕</button>
    </div>
    <div class="scan-progress">
      <div class="scan-metric">
        <span class="scan-metric__value" id="scanTimer">00:00</span>
        <span class="scan-metric__label">Время</span>
      </div>
      <div class="scan-metric">
        <span class="scan-metric__value" id="scanFound">0</span>
        <span class="scan-metric__label">Ссылок</span>
      </div>
      <div class="scan-metric">
        <span class="scan-metric__value" id="scanNew">0</span>
        <span class="scan-metric__label">Новых</span>
      </div>
    </div>
    <div class="scan-log" id="scanErrorBox" hidden></div>
    <div class="scan-actions">
      <button type="button" class="btn primary" id="scanOverlayContinue" hidden>Повторить поиск</button>
      <button type="button" class="btn" id="scanOverlayHide">Свернуть</button>
    </div>
  </div>
</div>
<div id="modalRoot"></div>
<?php
$initialScan = [
    'id' => $lastScan ? (int)$lastScan['id'] : null,
    'status' => $lastScan ? (string)$lastScan['status'] : null
];
?>
<script>
// Safe JSON fetch wrapper: avoids SyntaxError when server returns HTML
async function fetchJson(url, opts){
  const res = await fetch(url, opts);
  const ct = (res.headers.get('content-type')||'').toLowerCase();
  const text = await res.text();
  try {
    if (ct.includes('application/json') || text.trim().startsWith('{') || text.trim().startsWith('[')) {
      return JSON.parse(text);
    }
  } catch (e) {
    console.error('JSON parse error for', url, e, text.slice(0,400));
    throw e;
  }
  console.error('Expected JSON, got:', url, text.slice(0,400));
  throw new Error('Bad JSON response');
}

const fmtDuration = s=> s==null? '—' : (s<60? (s+'s') : ( (s/60).toFixed(1)+'m'));
const timeAgo = iso=>{ if(!iso) return '—'; const t=new Date(iso.replace(' ','T')); const diff=(Date.now()-t.getTime())/1000; if(diff<60) return Math.floor(diff)+'s ago'; if(diff<3600) return Math.floor(diff/60)+'m ago'; if(diff<86400) return Math.floor(diff/3600)+'h ago'; return Math.floor(diff/86400)+'d ago'; };

const initialScanInfo = <?= json_encode($initialScan, JSON_UNESCAPED_UNICODE); ?>;
let lastScanId = initialScanInfo.id;
let lastScanStatus = initialScanInfo.status;
let currentScanId = null;
let pendingScan = false;
let scanSettled = false;
let scanStartTs = null;
let scanTimerInterval = null;
let scanPollTimer = null;
let cancelRequestPending = false;
const SCAN_POLL_INTERVAL = 2000;

const scanBtn = document.getElementById('scanBtn');
const scanResumeBtn = document.getElementById('scanResumeBtn');
const scanOverlay = document.getElementById('scanOverlay');
const scanSpinner = document.getElementById('scanSpinner');
const scanOverlayTitle = document.getElementById('scanOverlayTitle');
const scanStatusText = document.getElementById('scanStatusText');
const scanTimerEl = document.getElementById('scanTimer');
const scanFoundEl = document.getElementById('scanFound');
const scanNewEl = document.getElementById('scanNew');
const scanErrorBox = document.getElementById('scanErrorBox');
const scanOverlayContinue = document.getElementById('scanOverlayContinue');
const scanOverlayHide = document.getElementById('scanOverlayHide');
const scanOverlayClose = document.getElementById('scanOverlayClose');
const scanCancelBtn = document.getElementById('scanCancelBtn');

scanOverlayHide.hidden = true;
scanOverlayClose.hidden = true;

function updateScanButtons(){
  if(scanResumeBtn){
    if(pendingScan){
      scanResumeBtn.style.display='none';
    } else if(lastScanStatus && ['error','cancelled'].includes(lastScanStatus)){
      scanResumeBtn.style.display='';
    } else {
      scanResumeBtn.style.display='none';
    }
  }
  if(scanCancelBtn){
    const running = pendingScan || (lastScanStatus && ['running','started'].includes(lastScanStatus));
    if(running){
      scanCancelBtn.style.display='';
      scanCancelBtn.disabled = cancelRequestPending;
    } else {
      scanCancelBtn.style.display='none';
    }
  }
}

function resetScanOverlayMetrics(){
  scanFoundEl.textContent = '0';
  scanNewEl.textContent = '0';
  scanTimerEl.textContent = '00:00';
}

function showScanOverlay(title, status){
  scanOverlay.classList.add('active');
  scanOverlay.setAttribute('aria-hidden','false');
  scanOverlayTitle.textContent = title;
  scanStatusText.textContent = status;
  scanSpinner.style.display = '';
  scanErrorBox.hidden = true;
  scanOverlayContinue.hidden = true;
  scanOverlayHide.hidden = true;
  scanOverlayClose.hidden = true;
  scanOverlayHide.textContent = 'Свернуть';
}

function hideScanOverlay(){
  scanOverlay.classList.remove('active');
  scanOverlay.setAttribute('aria-hidden','true');
  scanOverlayHide.hidden = true;
  scanOverlayClose.hidden = true;
}

function updateScanTimer(){
  if(!scanStartTs){ scanTimerEl.textContent='00:00'; return; }
  const diff = Math.max(0, Math.floor((Date.now() - scanStartTs)/1000));
  const m = Math.floor(diff/60);
  const s = diff % 60;
  scanTimerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}

function startScanTimer(){
  if(scanTimerInterval) clearInterval(scanTimerInterval);
  updateScanTimer();
  scanTimerInterval = setInterval(updateScanTimer, 1000);
}

function stopScanTimer(){
  if(scanTimerInterval){ clearInterval(scanTimerInterval); scanTimerInterval = null; }
}

async function pollScanStatusLoop(){
  if(!pendingScan) return;
  try {
    const j = await fetchJson('index.php?ajax=scan_status');
    if(!j.ok || !j.scan) return;
    const scan = j.scan;
    if(!currentScanId){
      if(lastScanId && scan.id === lastScanId && scan.status !== 'running'){ return; }
      currentScanId = scan.id;
      if(scan.started_at){
        const parsed = new Date(scan.started_at.replace(' ','T'));
        if(!Number.isNaN(parsed.getTime())){
          scanStartTs = parsed.getTime();
          startScanTimer();
        }
      }
    }
    if(scan.id !== currentScanId){ return; }
    if(typeof scan.found === 'number') scanFoundEl.textContent = scan.found;
    if(typeof scan.new === 'number') scanNewEl.textContent = scan.new;
    if(scan.status === 'running' || scan.status === 'started'){
      const totalFound = typeof scan.found === 'number' ? scan.found : scanFoundEl.textContent;
      const totalNew = typeof scan.new === 'number' ? scan.new : scanNewEl.textContent;
      scanOverlayTitle.textContent = 'Мониторинг выполняется…';
      scanStatusText.textContent = `Собираем свежие обсуждения… Найдено: ${totalFound} (новых ${totalNew}).`;
      scanSpinner.style.display = '';
      if(scan.started_at){
        const parsed = new Date(scan.started_at.replace(' ','T'));
        if(!Number.isNaN(parsed.getTime())){
          scanStartTs = parsed.getTime();
        }
      }
    } else if(scan.status === 'done'){
      settleScan({status:'done', data:scan});
    } else if(scan.status === 'error'){
      settleScan({status:'error', data:scan});
    } else if(scan.status === 'cancelled'){
      settleScan({status:'cancelled', data:scan});
    }
  } catch(e){
    console.error('pollScanStatus failed', e);
  }
}

function scheduleScanPolling(){
  if(scanPollTimer) clearInterval(scanPollTimer);
  setTimeout(()=>{ if(pendingScan) pollScanStatusLoop(); }, 1100);
  scanPollTimer = setInterval(pollScanStatusLoop, SCAN_POLL_INTERVAL);
}

function settleScan(result){
  if(scanSettled) return;
  scanSettled = true;
  pendingScan = false;
  if(scanPollTimer){ clearInterval(scanPollTimer); scanPollTimer=null; }
  stopScanTimer();
  scanSpinner.style.display = 'none';
  scanBtn.disabled = false;
  if(scanResumeBtn) scanResumeBtn.disabled = false;

  const data = result?.data || {};
  if(typeof data.found === 'number') scanFoundEl.textContent = data.found;
  if(typeof data.new === 'number') scanNewEl.textContent = data.new;
  if(data.started_at){
    const parsed = new Date(data.started_at.replace(' ','T'));
    if(!Number.isNaN(parsed.getTime())){
      scanStartTs = parsed.getTime();
      updateScanTimer();
    }
  }

  if(result.status === 'error' && !scanOverlay.classList.contains('active')){
    showScanOverlay('Мониторинг прерван', 'Показываем сохранённые результаты.');
    scanSpinner.style.display = 'none';
  }

  scanOverlayHide.hidden = false;
  scanOverlayClose.hidden = false;
  scanOverlayHide.textContent = 'Закрыть';

  if(result.status === 'done'){
    const totalFound = scanFoundEl.textContent;
    const totalNew = scanNewEl.textContent;
    scanOverlayTitle.textContent = 'Мониторинг завершён';
    scanStatusText.textContent = `Новые ссылки сохранены. Итог: ${totalFound} (новых ${totalNew}).`;
    scanErrorBox.hidden = true;
    scanOverlayContinue.hidden = true;
    setTimeout(()=>{ hideScanOverlay(); }, 2500);
  } else if(result.status === 'error'){
    scanOverlayTitle.textContent = 'Мониторинг прерван';
    const totalFound = scanFoundEl.textContent;
    const totalNew = scanNewEl.textContent;
    scanStatusText.textContent = `Часть результатов сохранена: ${totalFound} (новых ${totalNew}). Можно повторить поиск.`;
    scanErrorBox.textContent = data.error ? data.error : 'Неизвестная ошибка. Проверьте логи.';
    scanErrorBox.hidden = false;
    scanOverlayContinue.hidden = false;
    scanOverlayContinue.disabled = false;
  } else if(result.status === 'cancelled'){
    const totalFound = scanFoundEl.textContent;
    const totalNew = scanNewEl.textContent;
    scanOverlayTitle.textContent = 'Мониторинг остановлен';
    scanStatusText.textContent = `Процесс остановлен. Успели сохранить: ${totalFound} (новых ${totalNew}).`;
    scanErrorBox.textContent = data.error ? data.error : 'Отменено пользователем.';
    scanErrorBox.hidden = false;
    scanOverlayContinue.hidden = false;
    scanOverlayContinue.disabled = false;
  } else if(result.status === 'skipped'){
    scanOverlayTitle.textContent = 'Запуск пропущен';
    let reason = 'Скан не был запущен.';
    if(data.reason === 'period_guard') reason = 'Пауза между запусками ещё не прошла.';
    scanStatusText.textContent = reason;
    scanErrorBox.hidden = true;
    scanOverlayContinue.hidden = true;
    setTimeout(()=>{ hideScanOverlay(); }, 2500);
  } else {
    scanOverlayTitle.textContent = 'Скан завершён';
    scanStatusText.textContent = 'Статус: '+result.status;
    scanErrorBox.hidden = true;
    scanOverlayContinue.hidden = true;
  }

  if(data.id) lastScanId = data.id;
  if(result.status) lastScanStatus = result.status;
  cancelRequestPending = false;
  updateScanButtons();
  setTimeout(()=>{ refreshAll(); }, 800);
}

async function triggerManualScan(){
  try {
    const res = await fetch('scan.php?manual=1');
    let payload = null;
    try { payload = await res.clone().json(); } catch(e) {
      payload = null;
    }
    if(res.ok && payload && payload.skipped){
      settleScan({status:'skipped', data:payload});
      return;
    }
    if(res.ok && payload && payload.status){
      settleScan({status:payload.status, data:{
        id: payload.scan_id || currentScanId || lastScanId,
        found: payload.found,
        new: payload.new,
        error: payload.error
      }});
      return;
    }
    if(!res.ok){
      console.warn('scan.php returned status', res.status);
      scanStatusText.textContent = 'Ожидаем подтверждение от сервера…';
    }
  } catch(e){
    console.error('manual scan request failed', e);
    scanStatusText.textContent = 'Пробуем завершить запрос…';
  }
}

function startManualScan(auto=false){
  if(pendingScan) return;
  pendingScan = true;
  scanSettled = false;
  currentScanId = null;
  scanStartTs = Date.now();
  cancelRequestPending = false;
  resetScanOverlayMetrics();
  showScanOverlay(auto ? 'Повторный запуск…' : 'Мониторинг запускается…', 'Подготавливаем запросы.');
  startScanTimer();
  scanBtn.disabled = true;
  if(scanResumeBtn) scanResumeBtn.disabled = true;
  updateScanButtons();
  triggerManualScan();
  scheduleScanPolling();
  setTimeout(()=>{ if(pendingScan) pollScanStatusLoop(); }, 800);
}

scanOverlayHide.addEventListener('click', hideScanOverlay);
scanOverlayClose.addEventListener('click', hideScanOverlay);
scanOverlayContinue.addEventListener('click', ()=> startManualScan(true));
scanBtn.addEventListener('click', ()=> startManualScan(false));
if(scanResumeBtn) scanResumeBtn.addEventListener('click', ()=> startManualScan(true));
if(scanCancelBtn){
  scanCancelBtn.addEventListener('click', async()=>{
    if(scanCancelBtn.disabled) return;
    if(!confirm('Прервать текущий скан?')) return;
    scanCancelBtn.disabled = true;
    cancelRequestPending = true;
    try {
      const res = await fetch('index.php', { method:'POST', body:new URLSearchParams({action:'cancel_scan'}) });
      const text = await res.text();
      let j = null;
      try { j = JSON.parse(text); } catch(e){ console.error('cancel_scan parse', e, text); }
      if(j && j.ok){
        showScanOverlay('Прерываем мониторинг…', 'Запрос отправлен, ждём подтверждения.');
        scanSpinner.style.display = 'none';
        pendingScan = true;
        pollScanStatusLoop();
        scheduleScanPolling();
      } else {
        scanCancelBtn.disabled = false;
        cancelRequestPending = false;
        updateScanButtons();
        const msg = j && j.error ? j.error : 'Не удалось отменить скан.';
        alert(msg);
      }
    } catch(e){
      console.error('cancel scan failed', e);
      scanCancelBtn.disabled = false;
      cancelRequestPending = false;
      updateScanButtons();
    }
  });
}
updateScanButtons();

// METRICS
async function loadMetrics(){
  try {
    const j = await fetchJson('index.php?ajax=metrics'); if(!j.ok) return;
    const m=j.metrics; const lastAge = document.getElementById('m-last-age');
    if(m.last_scan_at){ lastAge.textContent = timeAgo(m.last_scan_at); }
    document.getElementById('m-active').textContent = m.active_sources; document.getElementById('m-total').textContent=m.total_links; document.getElementById('m-last-found').textContent=m.last_scan_found; document.getElementById('m-24h').textContent=m.new_24h; document.getElementById('m-cand').textContent=m.candidates;
    // guard
    const guardEl = document.getElementById('guardInfo');
    let remain = m.guard_remaining; const btn = document.getElementById('scanBtn');
    if(remain>0){ btn.disabled=true; guardEl.textContent='guard '+remain+'s'; const iv=setInterval(()=>{remain--; guardEl.textContent='guard '+remain+'s'; if(remain<=0){clearInterval(iv); btn.disabled = pendingScan ? true : false; guardEl.textContent='готов';}},1000);} else { guardEl.textContent='готов'; btn.disabled = pendingScan ? true : false; }
    // sparkline
    drawSpark(document.getElementById('spark-total'), m.spark.map(x=>x.c));
  } catch(e){ console.error('loadMetrics failed', e); }
}
function drawSpark(svg, data){ if(!svg) return; const w=svg.clientWidth||160; const h=svg.clientHeight||28; const max=Math.max(...data,1); const step=w/(data.length-1); let d=''; data.forEach((v,i)=>{ const x=i*step; const y=h - (v/max)* (h-4) -2; d += (i?'L':'M')+x+','+y;}); svg.setAttribute('viewBox','0 0 '+w+' '+h); svg.innerHTML='<path d="'+d+'" fill="none" stroke="url(#g)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />'+`<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#5b8cff"/><stop offset="1" stop-color="#7ea2ff"/></linearGradient></defs>`; }

function formatShortDate(val){
  if(!val) return '—';
  let normalized = String(val).trim().replace('T',' ').replace('Z','');
  const match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})[\s](\d{2}):(\d{2})/);
  if(match){
    const [, , mm, dd, hh, min] = match;
    return `${dd}.${mm} ${hh}:${min}`;
  }
  const parsed = new Date(val);
  if(!Number.isNaN(parsed.getTime())){
    const dd = String(parsed.getDate()).padStart(2,'0');
    const mm = String(parsed.getMonth()+1).padStart(2,'0');
    const hh = String(parsed.getHours()).padStart(2,'0');
    const mins = String(parsed.getMinutes()).padStart(2,'0');
    return `${dd}.${mm} ${hh}:${mins}`;
  }
  return normalized.length > 16 ? normalized.slice(0,16) : normalized;
}

// SCAN HISTORY
const scanStatusLabels = { done:'Готово', running:'Выполняется', started:'Старт', error:'Ошибка', cancelled:'Отменён' };
async function loadScanHistory(){
  try{
    const j=await fetchJson('index.php?ajax=scans'); if(!j.ok)return;
    const tb=document.querySelector('#scanHistoryTbl tbody');
    tb.innerHTML='';
    if(!j.scans.length){
      lastScanId = null;
      lastScanStatus = null;
      updateScanButtons();
      return;
    }
    j.scans.forEach((s, idx)=>{
      let statusClass = '';
      if(['error','cancelled'].includes(s.status)){ statusClass = 'history-error'; }
      else if(['running','started'].includes(s.status)){ statusClass = 'history-running'; }
      else if(s.status === 'done'){ statusClass = 'history-done'; }
      const statusTitle = s.error ? ` title="${escapeHtml(s.error)}"` : '';
      const label = scanStatusLabels[s.status] || s.status;
      const tr=document.createElement('tr');
      tr.innerHTML=`<td>${s.id}</td><td>${s.started_at.slice(5,16)}</td><td>${s.finished_at? s.finished_at.slice(5,16):'—'}</td><td>${fmtDuration(s.duration_sec)}</td><td class="${s.new? 'history-new':'history-zero'}">${s.new}</td><td>${s.found}</td><td class="${statusClass}"${statusTitle}>${label}</td>`;
      tb.appendChild(tr);
    });
    lastScanId = j.scans[0].id;
    lastScanStatus = j.scans[0].status;
    updateScanButtons();
  } catch(e){ console.error('loadScanHistory failed', e);} }

// DAILY TREND
async function loadDaily(){ try{ const j=await fetchJson('index.php?ajax=daily'); if(!j.ok)return; const wrap=document.getElementById('dailyGrid'); wrap.innerHTML=''; const max=Math.max(...j.days.map(d=>d.c),1); j.days.forEach(d=>{ const col=document.createElement('div'); col.className='daily-col'; const pct = d.c? ((d.c/max)*100):0; col.innerHTML=`<div class="day">${d.d.slice(5)}</div><div style="font-size:12px;color:var(--muted)">${d.c} links / ${d.u} dom</div><div class="bar-wrap"><div class="bar" style="height:100%"><span style="height:${pct||4}%;"></span></div></div>`; wrap.appendChild(col); }); } catch(e){ console.error('loadDaily failed', e);} }

// TOP DOMAINS
async function loadTopDomains(){ try{ const j=await fetchJson('index.php?ajax=top_domains'); if(!j.ok)return; const tb=document.querySelector('#topDomainsTable tbody'); tb.innerHTML=''; let totalAll= j.domains.reduce((a,b)=>a+parseInt(b.total),0)||1; j.domains.forEach(d=>{ const share = d.total? ((d.total/totalAll)*100):0; const tr=document.createElement('tr'); const status = d.is_active? '✅':'⏸'; tr.innerHTML=`<td><a href="sources.php?source=${d.id}">${escapeHtml(d.host)}</a></td><td>${d.new_24}</td><td>${d.new_7d}</td><td>${d.total}</td><td><div class="dom-bar" style="width:${Math.max(4,share*1.4)}px" title="${share.toFixed(1)}%"></div></td><td>${status}</td>`; tb.appendChild(tr); }); document.getElementById('topDomCount').textContent='('+j.domains.length+')'; // fill domain filter
  const sel=document.getElementById('linksDomain'); const cur=sel.value; const added=new Set(); Array.from(sel.options).forEach((o,i)=>{ if(i>0) sel.removeChild(o);}); j.domains.forEach(d=>{ if(!added.has(d.host)){ const opt=document.createElement('option'); opt.value=d.host; opt.textContent=d.host; sel.appendChild(opt); added.add(d.host);} }); if(cur) sel.value=cur; } catch(e){ console.error('loadTopDomains failed', e);} }

// RECENT LINKS + filters
let linksOffset=0; let linksTotal=0; let linksRange='24h'; let linksDomain=''; let linksQ='';
async function loadLinks(reset=false){
  try{
    if(reset){ linksOffset=0; }
    const params=new URLSearchParams({ajax:'links', offset:linksOffset, range:linksRange});
    if(linksDomain) params.append('domain',linksDomain);
    if(linksQ) params.append('q',linksQ);
    const j=await fetchJson('index.php?'+params.toString());
    if(!j.ok)return;
    linksTotal=j.total;
    document.getElementById('linksTotal').textContent='Всего: '+linksTotal;
    const tb=document.querySelector('#linksTable tbody');
    if(reset) tb.innerHTML='';
    j.links.forEach(l=>{
      const tr=document.createElement('tr');
      tr.className='link-row';
      tr.dataset.id=l.id;
      const first=formatShortDate(l.first_found);
      const content=formatShortDate(l.content_updated_at);
      const seen=formatShortDate(l.last_seen);
      tr.innerHTML=`<td>${escapeHtml(l.host)}</td>`+
        `<td class="ellipsis" style="max-width:240px;">${escapeHtml(l.title||'—')}</td>`+
        `<td class="ellipsis" style="max-width:340px;"><a href="${escapeHtml(l.url)}" target="_blank" rel="noopener">${escapeHtml(l.url)}</a></td>`+
        `<td>${first}</td>`+
        `<td>${content}</td>`+
        `<td>${seen}</td>`+
        `<td>${l.times_seen||1}</td>`;
      tb.appendChild(tr);
    });
    linksOffset += j.links.length;
    document.getElementById('linksOffsetInfo').textContent = linksOffset + ' / ' + linksTotal;
    const moreBtn=document.getElementById('linksMore');
    moreBtn.disabled = linksOffset >= linksTotal;
  } catch(e){ console.error('loadLinks failed', e);} }

// INLINE link detail toggle
const linksTable=document.getElementById('linksTable');
linksTable.addEventListener('click', e => {
  const row = e.target.closest('.link-row');
  if(!row) return;
  const next = row.nextElementSibling;
  if(next && next.classList.contains('link-detail')){ next.remove(); return; }
  if(next && next.classList.contains('link-row')){
    // keep table compact
  } else if(next){ next.remove(); }
  const detail = document.createElement('tr');
  detail.className = 'link-detail';
  detail.innerHTML = `<td colspan="7" style="font-size:12px;padding:10px 12px;">ID: ${row.dataset.id} • <a href="sources.php" style="text-decoration:none">Домены</a> • <span class="muted-inline">Подробнее функционал позже</span></td>`;
  row.parentNode.insertBefore(detail, row.nextSibling);
});

// CANDIDATES
async function loadCandidates(){ try{ const j=await fetchJson('index.php?ajax=candidates'); if(!j.ok)return; const tb=document.querySelector('#candTable tbody'); tb.innerHTML=''; if(!j.candidates.length){ tb.innerHTML='<tr><td colspan="5" style="font-size:12px;color:var(--muted)">Нет кандидатов</td></tr>'; return; } j.candidates.forEach(c=>{ const tr=document.createElement('tr'); tr.innerHTML=`<td>${escapeHtml(c.host)}</td><td>${c.link_count}</td><td>${c.first_found? c.first_found.slice(5,16):'—'}</td><td class="ellipsis" style="max-width:200px;">${escapeHtml(c.note||'candidate')}</td><td><button class="btn small" data-act-cand="${c.id}">Активировать</button></td>`; tb.appendChild(tr); }); } catch(e){ console.error('loadCandidates failed', e);} }

// Quick actions: candidates activation
 document.getElementById('activateAllCand').addEventListener('click', async()=>{ if(!confirm('Активировать все кандидаты?')) return; try{ const j=await fetchJson('index.php',{method:'POST',body:new URLSearchParams({action:'activate_candidates'})}); if(j.ok){ loadCandidates(); loadMetrics(); loadTopDomains(); } }catch(e){ console.error('activate all failed', e);} });
 document.getElementById('candTable').addEventListener('click', async e=>{ const btn=e.target.closest('button[data-act-cand]'); if(!btn) return; const id=btn.getAttribute('data-act-cand'); btn.disabled=true; btn.textContent='...'; try{ const j=await fetchJson('index.php',{method:'POST',body:new URLSearchParams({action:'activate_candidate',id})}); if(j.ok){ btn.textContent='OK'; setTimeout(()=>{loadCandidates(); loadMetrics(); loadTopDomains();},500);} else { btn.textContent='ERR'; } } catch(e){ console.error('activate failed', e); btn.textContent='ERR'; }
 });

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
