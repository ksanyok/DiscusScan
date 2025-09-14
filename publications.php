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
        (SELECT COUNT(*) FROM threads t WHERE t.forum_id=f.id AND t.status='queued') AS queued_cnt,
        (SELECT COUNT(*) FROM threads t WHERE t.forum_id=f.id AND t.status='posted') AS posted_cnt,
        (SELECT MAX(COALESCE(t.last_attempt_at,t.id)) FROM threads t WHERE t.forum_id=f.id) AS last_activity
        FROM forums f
        ORDER BY f.id DESC
        LIMIT 300";
    return pdo()->query($sql)->fetchAll() ?: [];
}

function load_threads_by_host(string $host): array {
    $stmt = pdo()->prepare('SELECT f.id as forum_id, t.* FROM forums f JOIN threads t ON t.forum_id=f.id WHERE f.host=? ORDER BY t.id DESC LIMIT 500');
    $stmt->execute([$host]);
    return $stmt->fetchAll() ?: [];
}

// --- AJAX endpoints (JSON UI) ---
if(isset($_GET['ajax'])) {
    $a = $_GET['ajax'];
    if($a==='forums') {
        $forums = load_forums_summary();
        json_pub(['ok'=>true,'forums'=>$forums]);
    } elseif($a==='threads') {
        $host = trim($_GET['host'] ?? '');
        if($host==='') json_pub(['ok'=>false,'error'=>'no_host']);
        $threads = load_threads_by_host($host);
        json_pub(['ok'=>true,'threads'=>$threads,'host'=>$host]);
    } else {
        json_pub(['ok'=>false,'error'=>'unknown_ajax']);
    }
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
<!-- Убрано: отдельные publish.css (404) -->
<style>
/* Минимальные стили таблицы публикаций (ранее предполагались в publish.css) */
.pub-table{width:100%;border-collapse:collapse;margin-bottom:28px}
.pub-table th,.pub-table td{padding:6px 8px;border-bottom:1px solid var(--border);font-size:12px;text-align:left;vertical-align:top}
.pub-table th{background:rgba(255,255,255,.04);font-weight:600;letter-spacing:.5px;font-size:11px;text-transform:uppercase}
.pub-table tr:hover{background:rgba(255,255,255,.03)}
.pub-table .ellipsis{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.status-posted{color:#40d37b;font-weight:600}
.status-failed{color:#ff7b6b;font-weight:600}
.inline-badge{display:inline-block;padding:2px 6px;font-size:10px;border-radius:6px;background:#13214a;color:#8ea6d8;margin-left:6px}
/* Grid / cards UI */
.forums-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin:18px 0 34px}
.forum-card{position:relative;display:flex;flex-direction:column;padding:14px 14px 12px;border:1px solid var(--border);border-radius:14px;background:linear-gradient(180deg,rgba(255,255,255,.06),rgba(255,255,255,.02));backdrop-filter:blur(6px);cursor:pointer;transition:background .18s,border .18s}
.forum-card:hover{background:rgba(255,255,255,.08)}
.forum-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px}
.forum-host{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
.forum-stats{display:flex;flex-wrap:wrap;gap:6px;font-size:11px;margin-bottom:6px}
.stat-pill{padding:2px 6px;border-radius:30px;background:#13214a;color:#8ea6d8;line-height:1;font-weight:600}
.stat-pill.green{background:#173c2b;color:#40d37b}
.stat-pill.red{background:#40211d;color:#ff7b6b}
.stat-pill.warn{background:#3e3413;color:#ffc966}
.stat-pill.blue{background:#132c4a;color:#5b8cff}
.expand-btn{background:none;border:none;color:var(--muted);font-size:18px;line-height:1;cursor:pointer;padding:2px 4px;border-radius:8px}
.expand-btn:hover{background:rgba(255,255,255,.08);color:#fff}
.threads-box{margin-top:8px;border-top:1px solid rgba(255,255,255,.08);padding-top:8px;display:none;animation:fadeIn .25s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(-3px);}to{opacity:1;transform:translateY(0);}}
.thread-row{display:grid;grid-template-columns:48px 1fr 74px 86px;gap:6px;align-items:start;padding:6px 4px;border-radius:8px;font-size:12px}
.thread-row:hover{background:rgba(255,255,255,.05)}
.thread-title{font-weight:500;line-height:1.25;max-height:32px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.thread-status{font-size:11px;font-weight:600;align-self:center}
.ts-posted{color:#40d37b}
.ts-failed{color:#ff7b6b}
.ts-needs_manual{color:#ffc966}
.ts-queued{color:#5b8cff}
.thread-actions{display:flex;gap:4px;flex-wrap:wrap}
.btn.tiny{padding:4px 8px;font-size:11px;border-radius:8px}
.empty-msg{font-size:12px;color:var(--muted);padding:4px 0 2px}
.loader-min{font-size:11px;color:var(--muted);padding:4px 0}
/* Fallback table hidden by default (noscript shows) */
#fallbackThreads{display:none}
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="container">
  <h1 style="margin-top:10px;">Публикации</h1>
  <p class="muted" style="font-size:12px; margin-bottom:14px;">Плитки форумов с темами. Кликните плитку чтобы раскрыть темы и отправить публикацию.</p>
  <div id="forumsGrid" class="forums-grid" aria-live="polite"></div>
  <noscript>
    <p class="muted" style="font-size:12px;">(No JS) Показана старая таблица.</p>
    <div id="fallbackThreads">
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
    </div>
  </noscript>
</main>
<?php include 'footer.php'; ?>
<script>
function qs(s,p=document){return p.querySelector(s);} function ce(t,o={}){const el=document.createElement(t);Object.assign(el,o);return el;}
function escapeHtml(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}
function showMsg(msg,type='info'){console.log(msg); let box=qs('#pubFlash'); if(!box){ box=document.createElement('div'); box.id='pubFlash'; box.style.position='fixed'; box.style.bottom='14px'; box.style.right='14px'; box.style.zIndex='200'; box.style.maxWidth='320px'; document.body.appendChild(box);} const el=document.createElement('div'); el.textContent=msg; el.style.background= type==='err'? '#ff5544':'#1d355e'; el.style.color='#fff'; el.style.padding='10px 14px'; el.style.marginTop='8px'; el.style.borderRadius='10px'; el.style.fontSize='12px'; el.style.boxShadow='0 4px 18px rgba(0,0,0,.35)'; box.appendChild(el); setTimeout(()=>{el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),450);}, 4200);} 

async function fetchForums(){ const r=await fetch('publications.php?ajax=forums'); const j=await r.json(); if(!j.ok) return; renderForums(j.forums||[]); }
function renderForums(forums){ const grid=qs('#forumsGrid'); grid.innerHTML=''; if(!forums.length){ grid.innerHTML='<div class="empty-msg" style="grid-column:1/-1;">Нет форумов</div>'; return; } forums.forEach(f=>{ const card=ce('div',{className:'forum-card',dataset:{host:f.host}}); card.innerHTML=`<div class='forum-head'><div class='forum-host' title='${escapeHtml(f.host)}'>${escapeHtml(f.host)}</div><button class='expand-btn' aria-label='Раскрыть' data-expand='${f.host}'>▸</button></div>
      <div class='forum-stats'>
        <span class='stat-pill blue' title='Всего тем'>${f.thread_cnt}</span>
        <span class='stat-pill green' title='Опубликовано'>${f.posted_cnt}</span>
        <span class='stat-pill' title='В очереди'>${f.queued_cnt}</span>
        <span class='stat-pill warn' title='Нужен manual'>${f.needs_manual_cnt}</span>
        <span class='stat-pill red' title='Ошибка'>${f.failed_cnt}</span>
      </div>
      <div class='threads-box' id='tb-${f.host}' data-loaded='0'><div class='loader-min'>Нажмите, чтобы загрузить темы…</div></div>`; grid.appendChild(card); }); }

async function loadThreads(host){ const box=qs('#tb-'+CSS.escape(host)); if(!box || box.dataset.loading==='1') return; box.dataset.loading='1'; box.innerHTML='<div class="loader-min">Загрузка…</div>'; try{ const r=await fetch('publications.php?ajax=threads&host='+encodeURIComponent(host)); const j=await r.json(); if(!j.ok){ box.innerHTML='<div class="empty-msg">Ошибка загрузки</div>'; return; } if(!j.threads.length){ box.innerHTML='<div class="empty-msg">Тем нет</div>'; return; } const frag=document.createDocumentFragment(); j.threads.forEach(t=>{ const row=ce('div',{className:'thread-row',dataset:{id:t.id}}); let stClass='ts-'+t.status; row.innerHTML=`<div class='thread-status ${stClass}'>${t.status}</div><div class='thread-title' title='${escapeHtml(t.title||'')}'><a href='${escapeHtml(t.url)}' target='_blank' rel='noopener' style='color:inherit;text-decoration:none;'>${escapeHtml(t.title||'—')}</a></div><div class='thread-actions'><button class='btn tiny' data-publish-now='${t.id}'>Постить</button><button class='btn tiny' data-open-browser='${t.id}'>URL</button><a class='btn tiny' href='publications.php?page=logs&thread_id=${t.id}'>Логи</a></div><div style='font-size:11px;color:var(--muted);text-align:right;'>${t.last_attempt_at? t.last_attempt_at.slice(5,16):'—'}</div>`; frag.appendChild(row); }); box.innerHTML=''; box.appendChild(frag); box.dataset.loaded='1'; } catch(e){ box.innerHTML='<div class="empty-msg">Сбой сети</div>'; } finally { box.dataset.loading='0'; } }

document.addEventListener('click', e=>{
  const ex=e.target.closest('[data-expand]'); if(ex){ const host=ex.getAttribute('data-expand'); const card=ex.closest('.forum-card'); const box=qs('#tb-'+CSS.escape(host)); const opened=box.style.display==='block'; document.querySelectorAll('.threads-box').forEach(b=>{ if(b!==box){ b.style.display='none'; }}); document.querySelectorAll('.forum-card .expand-btn').forEach(b=>{ if(b!==ex) b.textContent='▸'; }); if(opened){ box.style.display='none'; ex.textContent='▸'; } else { box.style.display='block'; ex.textContent='▾'; if(box.dataset.loaded==='0'){ loadThreads(host); } } }
  const pubBtn=e.target.closest('[data-publish-now]'); if(pubBtn){ publishNow(pubBtn.getAttribute('data-publish-now'), pubBtn); }
  const brBtn=e.target.closest('[data-open-browser]'); if(brBtn){ openBrowser(brBtn.getAttribute('data-open-browser'), brBtn); }
});

async function publishNow(threadId, btn){ btn.disabled=true; const orig=btn.textContent; btn.textContent='…'; try { const fd=new URLSearchParams({action:'publish_now', thread_id:String(threadId)}); const r=await fetch('publications.php',{method:'POST',body:fd}); const j=await r.json(); if(!j.ok){ if(j.manual){ showMsg('Нужен ручной вход (manual)', 'err'); btn.textContent='Manual'; btn.classList.add('warn'); } else { showMsg('Ошибка: '+(j.error||'?'),'err'); btn.textContent='Ошибка'; } btn.disabled=false; return; } showMsg('Опубликовано #'+threadId); btn.textContent='OK'; const row=btn.closest('.thread-row'); if(row){ const st=row.querySelector('.thread-status'); if(st){ st.textContent='posted'; st.className='thread-status ts-posted'; } } } catch(e){ console.error(e); showMsg('Сеть','err'); btn.textContent='Err'; btn.disabled=false; } }
function openBrowser(threadId, btn){ const row=btn.closest('.thread-row'); if(!row) return; const a=row.querySelector('.thread-title a'); if(a) window.open(a.href,'_blank','noopener'); }

fetchForums();
</script>
</body>
</html>
