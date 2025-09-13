<?php
declare(strict_types=1);
require_once __DIR__.'/db.php';
require_once __DIR__.'/app/Agent/AgentProvider.php';
require_once __DIR__.'/app/Agent/NullAgent.php';
require_once __DIR__.'/app/Poster/PosterService.php';
require_login();

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

// Remove router/pages/import; load aggregated threads
$threadsAll = [];
try {
    $threadsAll = pdo()->query('SELECT t.*, f.host FROM threads t JOIN forums f ON f.id=t.forum_id ORDER BY t.id DESC LIMIT 500')->fetchAll();
} catch (Throwable $e) { $threadsAll=[]; }
?>
<!doctype html>
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
  <p class="muted" style="font-size:12px; margin-bottom:18px;">Список тем из всех форумов. Нажмите «Постить» чтобы агент опубликовал ответ. Настройки находятся в Settings → «Настройки публикаций».</p>
  <table class="pub-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Host</th>
        <th>Title</th>
        <th>URL</th>
        <th>Status</th>
        <th>Последняя попытка</th>
        <th>Действия</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($threadsAll as $t): ?>
      <tr>
        <td><?=$t['id']?></td>
        <td><?=e($t['host'])?></td>
        <td class="ellipsis" style="max-width:260px;"><?=e($t['title'] ?? '—')?></td>
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
</main>
<script src="public/js/publish.js"></script>
</body>
</html>
