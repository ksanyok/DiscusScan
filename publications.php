<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/app/Agent/AgentProvider.php';
require_once __DIR__.'/app/Agent/NullAgent.php';
require_once __DIR__.'/app/Poster/PosterService.php';
require_login();

// Basic router via ?page=...
$page = $_GET['page'] ?? 'index';

// JSON helper
function json_pub($data){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

// Ensure storage paths
$baseStorage = __DIR__ . '/storage';
if(!is_dir($baseStorage)) @mkdir($baseStorage, 0775, true);
foreach(['screens','cookies','logs'] as $d){ if(!is_dir($baseStorage.'/'.$d)) @mkdir($baseStorage.'/'.$d,0775,true); }

// POST actions
if($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    if($action==='enqueue'){
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $type = $_POST['type'] ?? 'post';
        if(!$threadId) json_pub(['ok'=>false,'error'=>'no_thread']);
        $stmt = pdo()->prepare('SELECT * FROM threads WHERE id=?'); $stmt->execute([$threadId]); $thread=$stmt->fetch();
        if(!$thread) json_pub(['ok'=>false,'error'=>'thread_not_found']);
        pdo()->prepare('INSERT INTO jobs(type,payload,status,attempts,created_at) VALUES(?, ?, "queued",0, NOW())')
            ->execute([$type, json_encode(['thread_id'=>$threadId], JSON_UNESCAPED_UNICODE)]);
        pdo()->prepare('UPDATE threads SET status="queued" WHERE id=?')->execute([$threadId]);
        json_pub(['ok'=>true,'job_type'=>$type]);
    } elseif($action==='manual-complete') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        $cookiesJson = $_POST['cookies'] ?? '[]';
        $accountId = (int)($_POST['account_id'] ?? 0);
        if(!$threadId || !$accountId) json_pub(['ok'=>false,'error'=>'missing_params']);
        $cookiesArr = json_decode($cookiesJson,true); if(!is_array($cookiesArr)) $cookiesArr=[];
        $cookieDir = $baseStorage.'/cookies';
        $path = $cookieDir.'/acc_'.$accountId.'.json';
        file_put_contents($path, json_encode($cookiesArr, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        pdo()->prepare('UPDATE accounts SET cookies_path=? , last_login_at=NOW() WHERE id=?')->execute([$path,$accountId]);
        pdo()->prepare('UPDATE threads SET status="queued" WHERE id=?')->execute([$threadId]);
        json_pub(['ok'=>true,'saved'=>basename($path)]);
    } elseif($action==='publish_now') {
        $threadId = (int)($_POST['thread_id'] ?? 0);
        if(!$threadId) json_pub(['ok'=>false,'error'=>'missing_thread']);
        // thread + forum host
        $stmt = pdo()->prepare('SELECT t.*, f.host FROM threads t JOIN forums f ON f.id=t.forum_id WHERE t.id=? LIMIT 1');
        $stmt->execute([$threadId]);
        $thread = $stmt->fetch();
        if(!$thread) json_pub(['ok'=>false,'error'=>'not_found']);
        if(in_array($thread['status'], ['posted','posting','verified'])) {
            json_pub(['ok'=>false,'error'=>'already_posted']);
        }
        // pick account
        $accStmt = pdo()->prepare('SELECT * FROM accounts WHERE forum_id=? AND is_active=1 ORDER BY (last_login_at IS NULL) DESC, last_login_at ASC LIMIT 1');
        $accStmt->execute([$thread['forum_id']]);
        $account = $accStmt->fetch();
        if(!$account) json_pub(['ok'=>false,'error'=>'no_account']);
        $poster = new PosterService();
        $loginRes = $poster->loginOrRegister(['host'=>$thread['host']], $account);
        if(!$loginRes['ok']) {
            $poster->fallbackToManual($thread);
            json_pub(['ok'=>false,'error'=>'login_failed','manual'=>true]);
        }
        $content = $poster->compose($thread, $account);
        $postRes = $poster->post($thread, $account, $content);
        json_pub(['ok'=>true,'thread_id'=>$threadId,'content_preview'=>mb_substr($content,0,140),'result'=>$postRes]);
    }
    json_pub(['ok'=>false,'error'=>'unknown_action']);
}

// Импорт форумов из sources/domains
if(isset($_GET['import_forums'])) {
    try {
        $added = 0;
        // Берём host из sources (активные)
        $hosts = pdo()->query("SELECT host FROM sources WHERE is_active=1 LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        // Также из domains (если таблица не пустая)
        try { $dHosts = pdo()->query("SELECT domain FROM domains LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) ?: []; } catch(Throwable $e) { $dHosts = []; }
        $all = array_unique(array_filter(array_map('strtolower', array_merge($hosts, $dHosts))));
        $ins = pdo()->prepare("INSERT IGNORE INTO forums(host,title) VALUES(?, NULL)");
        foreach($all as $h){ if($h==='') continue; $ins->execute([$h]); $added += (int)$ins->rowCount(); }
        header('Location: publications.php?import_done='.$added); exit;
    } catch(Throwable $e) {
        header('Location: publications.php?import_err=1'); exit;
    }
}

// Data providers
function load_forums_summary(): array {
    $sql = "SELECT f.id,f.host,f.title,
        (SELECT COUNT(*) FROM threads t WHERE t.forum_id=f.id) AS thread_cnt,
        (SELECT COUNT(*) FROM threads t WHERE t.forum_id=f.id AND t.status='needs_manual') AS needs_manual_cnt,
        (SELECT COUNT(*) FROM threads t WHERE t.forum_id=f.id AND t.status='failed') AS failed_cnt,
        (SELECT COUNT(*) FROM threads t WHERE t.forum_id=f.id AND t.status='queued') AS queued_cnt
        FROM forums f
        ORDER BY f.id DESC
        LIMIT 200";
    return pdo()->query($sql)->fetchAll() ?: [];
}

function load_threads_by_host(string $host): array {
    $stmt = pdo()->prepare('SELECT f.id as forum_id, t.* FROM forums f JOIN threads t ON t.forum_id=f.id WHERE f.host=? ORDER BY t.id DESC LIMIT 500');
    $stmt->execute([$host]);
    return $stmt->fetchAll() ?: [];
}

// SETTINGS SAVE
if($page==='settings' && $_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['save'])){
    $map = [
        'pub_daily_limit_domain' => 'int',
        'pub_daily_limit_account' => 'int',
        'pub_warm_mode' => 'bool',
        'pub_link_strategy' => 'str',
        'pub_max_reply_len' => 'int',
        'pub_style' => 'str',
        'pub_logs_retention_days' => 'int',
        'pub_make_screenshots' => 'bool'
    ];
    foreach($map as $k=>$t){ if(isset($_GET[$k])){ $val = $_GET[$k]; switch($t){case 'int': $val=(int)$val; break; case 'bool': $val=(isset($_GET[$k]) && $_GET[$k]=='1'); break; } set_setting($k,$val);} }
    header('Location: publications.php?page=settings&saved=1'); exit;
}

// Views
$forumsSummary = [];
if($page==='index'){ $forumsSummary = load_forums_summary(); }
$threads = [];$host='';
if($page==='domain'){ $host = $_GET['host'] ?? ''; if($host!==''){ $threads = load_threads_by_host($host); } }
$settingsVals = [];
if($page==='settings'){ $keys=['pub_daily_limit_domain','pub_daily_limit_account','pub_warm_mode','pub_link_strategy','pub_max_reply_len','pub_style','pub_logs_retention_days','pub_make_screenshots']; foreach($keys as $k){ $settingsVals[$k]=get_setting($k,''); } }

?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Публикации</title>
<link rel="stylesheet" href="styles.css">
<link rel="stylesheet" href="public/css/publish.css">
</head>
<body>
<?php include 'header.php'; ?>
<main class="container">
  <h1 style="margin-top:10px;">Публикации</h1>
  <nav style="margin:12px 0; display:flex; gap:12px;">
    <a class="btn btn-ghost small" href="publications.php">Домены</a>
    <a class="btn btn-ghost small" href="settings.php#pub">Настройки публикаций</a>
  </nav>
  <?php if(isset($_GET['import_done'])): ?><div class="alert success">Импортировано: <?= (int)$_GET['import_done']?> доменов</div><?php endif; ?>
  <?php if(isset($_GET['import_err'])): ?><div class="alert error">Ошибка импорта</div><?php endif; ?>
<?php if($page==='index'): ?>
  <section class="pub-grid">
    <?php if(!$forumsSummary): ?>
      <div class="muted-inline" style="font-size:12px; line-height:1.5;">
        Нет доменов в таблице forums.<br>
        <form method="get" style="margin-top:8px; display:inline-block;">
          <input type="hidden" name="import_forums" value="1">
          <button class="btn small" type="submit">Импортировать активные домены</button>
        </form>
      </div>
    <?php endif; ?>
    <?php foreach($forumsSummary as $f): $st='ok'; if($f['needs_manual_cnt']>0) $st='needs_manual'; elseif($f['failed_cnt']>0) $st='failed'; elseif($f['queued_cnt']>5) $st='cooldown'; ?>
      <div class="pub-card status-<?=$st?>">
        <div class="pc-host"><?=e($f['host'])?></div>
        <div class="pc-metrics">T<?=$f['thread_cnt']?> / Q<?=$f['queued_cnt']?> / M<?=$f['needs_manual_cnt']?> / F<?=$f['failed_cnt']?></div>
        <div class="pc-actions">
          <a href="publications.php?page=domain&host=<?=urlencode($f['host'])?>" class="btn btn-ghost tiny">Открыть</a>
          <button class="btn btn-ghost tiny" data-act-rescan="<?=$f['id']?>" disabled title="TODO">Сканировать</button>
          <a href="publications.php?page=logs&host=<?=urlencode($f['host'])?>" class="btn btn-ghost tiny">Логи</a>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
<?php elseif($page==='domain'): ?>
  <h2>Домен: <?=e($host)?:'—'?></h2>
  <table class="pub-table">
    <thead><tr><th>ID</th><th>Title</th><th>URL</th><th>Статус</th><th>Последняя попытка</th><th>Действия</th></tr></thead>
    <tbody>
    <?php foreach($threads as $t): ?>
      <tr>
        <td><?=$t['id']?></td>
        <td class="ellipsis" style="max-width:220px;"><?=e($t['title'] ?? '—')?></td>
        <td class="ellipsis" style="max-width:280px;"><a target="_blank" rel="noopener" href="<?=e($t['url'])?>">link</a></td>
        <td><?=$t['status']?></td>
        <td><?=$t['last_attempt_at']? date('d.m H:i', strtotime($t['last_attempt_at'])):'—'?></td>
        <td style="display:flex;gap:4px;flex-wrap:wrap;">
          <button class="btn tiny" data-publish-now="<?=$t['id']?>">Постить</button>
          <button class="btn tiny" data-open-browser="<?=$t['id']?>" data-thread="<?=$t['id']?>">Браузер</button>
          <a class="btn tiny" href="publications.php?page=logs&thread_id=<?=$t['id']?>">Логи</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php elseif($page==='settings'): ?>
  <h2>Настройки публикаций</h2>
  <?php if(isset($_GET['saved'])): ?><div class="alert success">Сохранено</div><?php endif; ?>
  <form method="get" action="publications.php" class="pub-settings-form">
    <input type="hidden" name="page" value="settings">
    <input type="hidden" name="save" value="1">
    <div class="form-grid">
      <label>Дневной лимит / домен <input type="number" name="pub_daily_limit_domain" value="<?=e((string)$settingsVals['pub_daily_limit_domain'])?>"></label>
      <label>Дневной лимит / аккаунт <input type="number" name="pub_daily_limit_account" value="<?=e((string)$settingsVals['pub_daily_limit_account'])?>"></label>
      <label>Макс длина ответа <input type="number" name="pub_max_reply_len" value="<?=e((string)$settingsVals['pub_max_reply_len'])?>"></label>
      <label>Стратегия ссылок <select name="pub_link_strategy"><option value="soft_cta" <?=$settingsVals['pub_link_strategy']==='soft_cta'?'selected':''?>>soft CTA</option><option value="strict" <?=$settingsVals['pub_link_strategy']==='strict'?'selected':''?>>strict</option></select></label>
      <label>Стиль <select name="pub_style"><option value="expert" <?=$settingsVals['pub_style']==='expert'?'selected':''?>>expert</option><option value="neutral" <?=$settingsVals['pub_style']==='neutral'?'selected':''?>>neutral</option><option value="friendly" <?=$settingsVals['pub_style']==='friendly'?'selected':''?>>friendly</option></select></label>
      <label>Ротация логов (дней) <input type="number" name="pub_logs_retention_days" value="<?=e((string)$settingsVals['pub_logs_retention_days'])?>"></label>
      <label style="display:flex;align-items:center;gap:6px;">Скриншоты <input type="checkbox" name="pub_make_screenshots" value="1" <?=$settingsVals['pub_make_screenshots']? 'checked':''?>></label>
      <label style="display:flex;align-items:center;gap:6px;">"Тёплый режим" <input type="checkbox" name="pub_warm_mode" value="1" <?=$settingsVals['pub_warm_mode']? 'checked':''?>></label>
    </div>
    <div><button class="btn primary" type="submit">Сохранить</button></div>
  </form>
<?php elseif($page==='logs'): ?>
  <h2>Логи публикаций</h2>
  <form method="get" action="publications.php" class="log-filters">
    <input type="hidden" name="page" value="logs">
    <label>Дата <input type="date" name="day" value="<?=e($_GET['day'] ?? date('Y-m-d'))?>"></label>
    <label>Host <input type="text" name="host" value="<?=e($_GET['host'] ?? '')?>"></label>
    <label>Account <input type="number" name="account_id" value="<?=e($_GET['account_id'] ?? '')?>"></label>
    <button class="btn small">Фильтр</button>
  </form>
  <div class="log-view" style="max-height:420px;overflow:auto;font-size:12px;background:#0f1733;padding:10px;border:1px solid var(--border);">
<?php
    $day = $_GET['day'] ?? date('Y-m-d');
    $logFile = $baseStorage.'/logs/publish-'.$day.'.jsonl';
    if(is_file($logFile)){
        $fh = fopen($logFile,'r'); $limit=1000; $cnt=0; $filterHost=trim($_GET['host'] ?? ''); $filterAcc= (int)($_GET['account_id'] ?? 0);
        while(!feof($fh) && $cnt<$limit){ $line = trim((string)fgets($fh)); if($line==='') continue; $row=json_decode($line,true); if(!is_array($row)) continue; if($filterHost && ($row['host']??'')!==$filterHost) continue; if($filterAcc && (int)($row['account']??0)!==$filterAcc) continue; $cnt++; echo '<div class="log-row"><code>'.e(json_encode($row, JSON_UNESCAPED_UNICODE))."</code></div>"; }
        fclose($fh);
    } else {
        echo '<div class="muted-inline">Файл логов не найден</div>';
    }
?>
  </div>
  <p><a class="btn small" href="download.php?type=publog&day=<?=e($day)?>">Скачать за день</a></p>
<?php endif; ?>
</main>
<script src="public/js/publish.js"></script>
</body>
</html>
