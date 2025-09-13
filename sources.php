<?php
require_once __DIR__ . '/db.php';
require_login();

// --- AJAX: fetch links for a domain (lazy load accordion) ---
if (isset($_GET['links']) && ctype_digit($_GET['links'])) {
    $sid = (int)$_GET['links'];
    $stmt = pdo()->prepare("SELECT host,is_active,note FROM sources WHERE id=? LIMIT 1");
    $stmt->execute([$sid]);
    $hostRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$hostRow) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'not_found']);
        exit;
    }
    $stmt = pdo()->prepare("SELECT id,url,title,first_found,last_seen,times_seen,status FROM links WHERE source_id=? ORDER BY COALESCE(last_seen,first_found) DESC LIMIT 500");
    $stmt->execute([$sid]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'=>true,
        'source'=>[
            'id'=>$sid,
            'host'=>$hostRow['host'],
            'is_active'=>(int)$hostRow['is_active'],
            'note'=>$hostRow['note'] ?? ''
        ],
        'links'=>$links
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AJAX: toggle active state ---
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['toggle_id']) && ctype_digit($_POST['toggle_id'])) {
    $id = (int)$_POST['toggle_id'];
    pdo()->exec("UPDATE sources SET is_active = 1 - is_active WHERE id={$id}");
    $st = (int)pdo()->query("SELECT is_active FROM sources WHERE id={$id}")->fetchColumn();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'id'=>$id,'is_active'=>$st]);
    exit;
}

// --- Legacy GET toggle (non-JS fallback) ---
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    pdo()->exec("UPDATE sources SET is_active = 1 - is_active WHERE id = {$id}");
    header('Location: sources.php');
    exit;
}

// --- Domains summary list ---
$domains = pdo()->query("\n    SELECT s.id, s.host, s.is_active, s.note, COUNT(l.id) AS links_count,\n           MAX(COALESCE(l.last_seen, l.first_found)) AS last_seen\n    FROM sources s\n    LEFT JOIN links l ON l.source_id = s.id\n    GROUP BY s.id, s.host, s.is_active, s.note\n    ORDER BY links_count DESC, s.host ASC\n")->fetchAll(PDO::FETCH_ASSOC);

// --- Non-JS fallback: ?source=ID shows links at bottom ---
$legacyLinks = [];$legacyHost=null;
if (isset($_GET['source']) && ctype_digit($_GET['source'])) {
    $sid=(int)$_GET['source'];
    $legacyHost = pdo()->query("SELECT host FROM sources WHERE id={$sid}")->fetchColumn();
    if ($legacyHost) {
        $stmt=pdo()->prepare("SELECT id,url,title,first_found,last_seen,times_seen,status FROM links WHERE source_id=? ORDER BY COALESCE(last_seen,first_found) DESC LIMIT 500");
        $stmt->execute([$sid]);
        $legacyLinks=$stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Домены — Мониторинг</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    /* --- Accordion table redesign --- */
    .sources-layout{max-width:1400px;margin:0 auto;}
    .domains-toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:14px;}
    .domains-toolbar input[type=search]{flex:1;min-width:240px;padding:8px 10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);border-radius:8px;color:var(--text);}
    .domains-table{width:100%;border-collapse:collapse;}
    .domains-table th,.domains-table td{padding:8px 10px;font-size:13px;text-align:left;border-bottom:1px solid rgba(255,255,255,0.06);vertical-align:middle;}
    .domains-table th{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);}
    .domains-table tbody tr.domain-row{cursor:pointer;}
    .domains-table tbody tr.domain-row:hover{background:rgba(255,255,255,.04);}
    .expander-cell{width:32px;text-align:center;font-size:16px;user-select:none;}
    .badge{display:inline-block;padding:2px 8px;border-radius:30px;font-size:11px;font-weight:600;letter-spacing:.5px;line-height:1;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);}
    .badge.active{background:linear-gradient(135deg,#2e8b57,#3fae72);border-color:#2e8b57;color:#fff;}
    .badge.paused{background:linear-gradient(135deg,#555,#333);color:#bbb;}
    .badge.candidate{background:linear-gradient(135deg,#6f42c1,#8e5bd6);color:#fff;}
    .toggle-btn{background:none;border:1px solid var(--border);padding:4px 8px;border-radius:6px;font-size:11px;cursor:pointer;color:var(--text);}
    .toggle-btn:hover{background:var(--pri);color:#fff;border-color:var(--pri);}
    .links-panel{padding:12px 4px 18px;background:rgba(0,0,0,0.25);border-left:3px solid var(--pri);border-radius:8px;margin:4px 0 10px;animation:slideDown .28s ease;}
    @keyframes slideDown{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);} }
    .links-panel table{width:100%;border-collapse:collapse;}
    .links-panel th,.links-panel td{padding:4px 6px;font-size:12px;border-bottom:1px solid rgba(255,255,255,0.06);}
    .links-panel th{font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;}
    .links-panel tbody tr:hover{background:rgba(255,255,255,0.05);}
    .links-loading{padding:14px 10px;font-size:12px;color:var(--muted);}
    .row-open{background:rgba(91,140,255,0.08)!important;}
    .empty-msg{padding:6px 4px;font-size:12px;color:var(--muted);}
    @media (max-width:900px){
      .domains-table th:nth-child(5), .domains-table td:nth-child(5){display:none;} /* hide note column */
      .domains-table th.links-col, .domains-table td.links-col{width:60px;}
    }
    @media (max-width:700px){
      .domains-table th:nth-child(4), .domains-table td:nth-child(4){display:none;} /* hide last seen */
      .domains-table th:nth-child(3), .domains-table td:nth-child(3){display:none;} /* hide links count on very small */
      .toggle-btn{padding:4px 6px;}
    }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="container sources-layout">
  <div class="card glass" style="overflow:hidden;">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <span>Домены (<?=count($domains)?>)</span>
      <div style="font-size:12px;color:var(--muted);display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <span><span class="badge active">Активен</span> участвует в per-domain поиске</span>
        <span><span class="badge paused">Пауза</span> не опрашивается</span>
        <span><span class="badge candidate">Candidate</span> новый найденный</span>
      </div>
    </div>

    <div class="domains-toolbar">
      <input type="search" id="domainFilter" placeholder="Фильтр по домену..." aria-label="Фильтр доменов">
      <button type="button" id="collapseAll" class="toggle-btn">Свернуть все</button>
    </div>

    <div class="table-wrap" style="overflow-x:auto;">
      <table class="domains-table" id="domainsTable">
        <thead>
          <tr>
            <th class="expander-cell"></th>
            <th>Домен</th>
            <th class="links-col">Ссылок</th>
            <th>Обновлено</th>
            <th>Примечание</th>
            <th>Статус</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($domains as $d): ?>
            <?php
              $badgeClass = $d['is_active'] ? 'active' : 'paused';
              if (!$d['is_active'] && stripos((string)$d['note'],'candidate')!==false) $badgeClass='candidate';
            ?>
            <tr class="domain-row" data-id="<?=$d['id']?>" data-host="<?=e($d['host'])?>">
              <td class="expander-cell" aria-label="Expand">▸</td>
              <td style="font-weight:600;white-space:nowrap;"><?=e($d['host'])?></td>
              <td class="links-col"><span class="pill" style="background:rgba(255,255,255,.08);"><?=$d['links_count']?></span></td>
              <td><?= $d['last_seen'] ? date('Y-m-d H:i', strtotime($d['last_seen'])) : '—' ?></td>
              <td style="max-width:160px;" class="ellipsis" title="<?=e($d['note'] ?? '')?>"><?= $d['note'] ? e($d['note']) : '—' ?></td>
              <td><span class="badge <?=$badgeClass?>" data-status="<?=$d['is_active']?>"><?= $d['is_active'] ? 'Active' : ($badgeClass==='candidate'?'Candidate':'Paused') ?></span></td>
              <td><button class="toggle-btn js-toggle" data-id="<?=$d['id']?>"><?= $d['is_active'] ? 'Пауза' : 'Включить' ?></button></td>
            </tr>
            <tr class="domain-links-row" data-parent="<?=$d['id']?>" style="display:none;">
              <td colspan="7">
                <div class="links-panel" id="panel-<?=$d['id']?>">
                  <div class="links-loading">Загрузка...</div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if($legacyHost && $legacyLinks): ?>
      <div style="margin-top:30px;">
        <h3 style="margin:0 0 8px;">(Fallback) Ссылки: <?=e($legacyHost)?></h3>
        <div class="links-panel">
          <?php if($legacyLinks): ?>
            <table>
              <thead><tr><th>Заголовок</th><th>URL</th><th>Найдено</th><th>Обновлено</th><th>Пок.</th></tr></thead>
              <tbody>
                <?php foreach($legacyLinks as $l): ?>
                  <tr>
                    <td class="ellipsis" style="max-width:300px;"><?=e($l['title'] ?: '—')?></td>
                    <td class="ellipsis" style="max-width:380px;"><a href="<?=e($l['url'])?>" target="_blank" rel="noopener"><?=e($l['url'])?></a></td>
                    <td><?= $l['first_found'] ? date('Y-m-d H:i', strtotime($l['first_found'])) : '—' ?></td>
                    <td><?= $l['last_seen'] ? date('Y-m-d H:i', strtotime($l['last_seen'])) : '—' ?></td>
                    <td><?= (int)$l['times_seen'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</main>
<?php include 'footer.php'; ?>
<script>
// Filter domains
const filterInput = document.getElementById('domainFilter');
const table = document.getElementById('domainsTable');
filterInput.addEventListener('input', ()=>{
  const q = filterInput.value.trim().toLowerCase();
  table.querySelectorAll('tbody tr.domain-row').forEach(tr=>{
    const host = tr.getAttribute('data-host').toLowerCase();
    const linksRow = table.querySelector('tr.domain-links-row[data-parent="'+tr.dataset.id+'"]');
    const show = !q || host.includes(q);
    tr.style.display = show ? '' : 'none';
    if (linksRow) linksRow.style.display = show && linksRow.classList.contains('open') ? '' : (show ? 'none' : 'none');
  });
});

// Accordion behavior
let openId = null;
function loadLinks(id, panel){
  panel.innerHTML = '<div class="links-loading">Загрузка...</div>';
  fetch('sources.php?links='+id).then(r=>r.json()).then(data=>{
    if(!data.ok){ panel.innerHTML='<div class="empty-msg">Ошибка загрузки</div>'; return; }
    if(!data.links.length){ panel.innerHTML='<div class="empty-msg">Нет ссылок для этого домена</div>'; return; }
    let html = '<table><thead><tr><th style="width:34%;">Заголовок</th><th style="width:36%;">URL</th><th>Найдено</th><th>Обновлено</th><th>Пок.</th></tr></thead><tbody>';
    data.links.forEach(l=>{
      const title = escapeHtml(l.title || '—');
      const url = escapeHtml(l.url);
      html += `<tr><td class="ellipsis" title="${title}">${title}</td><td class="ellipsis"><a href="${url}" target="_blank" rel="noopener">${url}</a></td><td>${l.first_found?fmtDate(l.first_found):'—'}</td><td>${l.last_seen?fmtDate(l.last_seen):'—'}</td><td>${l.times_seen||1}</td></tr>`;
    });
    html += '</tbody></table>';
    panel.innerHTML = html;
  }).catch(()=>{ panel.innerHTML='<div class="empty-msg">Сбой сети</div>'; });
}
function fmtDate(str){
  // Expect YYYY-MM-DD HH:MM:SS
  return str ? str.slice(0,16) : '—';
}
function escapeHtml(s){ const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

table.addEventListener('click', e=>{
  const row = e.target.closest('tr.domain-row');
  if (row && !e.target.classList.contains('js-toggle')) {
    const id = row.dataset.id;
    const linksRow = table.querySelector('tr.domain-links-row[data-parent="'+id+'"]');
    const expCell = row.querySelector('.expander-cell');
    const opened = linksRow.style.display !== 'none';
    // close previous
    if (openId && openId !== id){
      const prevLinks = table.querySelector('tr.domain-links-row[data-parent="'+openId+'"]');
      const prevRow = table.querySelector('tr.domain-row[data-id="'+openId+'"]');
      if (prevLinks){ prevLinks.style.display='none'; prevLinks.classList.remove('open'); }
      if (prevRow){ prevRow.classList.remove('row-open'); const ec=prevRow.querySelector('.expander-cell'); if(ec) ec.textContent='▸'; }
    }
    if (opened){
      linksRow.style.display='none';
      linksRow.classList.remove('open');
      row.classList.remove('row-open');
      if (expCell) expCell.textContent='▸';
      openId=null;
    } else {
      linksRow.style.display='';
      linksRow.classList.add('open');
      row.classList.add('row-open');
      if (expCell) expCell.textContent='▾';
      openId=id;
      const panel = linksRow.querySelector('.links-panel');
      if (!panel.dataset.loaded){
        loadLinks(id,panel);
        panel.dataset.loaded='1';
      }
    }
  }
});

document.getElementById('collapseAll').addEventListener('click',()=>{
  table.querySelectorAll('tr.domain-links-row').forEach(r=>{ r.style.display='none'; r.classList.remove('open'); });
  table.querySelectorAll('tr.domain-row').forEach(r=>{ r.classList.remove('row-open'); const ec=r.querySelector('.expander-cell'); if(ec) ec.textContent='▸'; });
  openId=null;
});

// Toggle status via AJAX
 table.addEventListener('click', e=>{
   const btn = e.target.closest('.js-toggle');
   if(!btn) return;
   e.stopPropagation();
   const id = btn.getAttribute('data-id');
   btn.disabled=true; btn.textContent='...';
   fetch('sources.php',{method:'POST',body:new URLSearchParams({toggle_id:id})})
     .then(r=>r.json()).then(data=>{
       if(!data.ok) throw new Error();
       const row = table.querySelector('tr.domain-row[data-id="'+id+'"]');
       const badge = row.querySelector('.badge');
       if(data.is_active){
         badge.textContent='Active';
         badge.classList.remove('paused','candidate');
         badge.classList.add('active');
         btn.textContent='Пауза';
       } else {
         badge.textContent='Paused';
         badge.classList.remove('active');
         badge.classList.add('paused');
         btn.textContent='Включить';
       }
     }).catch(()=>{ btn.textContent='Ошибка'; setTimeout(()=>{btn.textContent='Повтор'; btn.disabled=false;},1200); })
     .finally(()=>{ setTimeout(()=>{ if(btn.textContent!=='Повтор' && btn.textContent!=='Ошибка') btn.disabled=false; },400); });
 });
</script>
</body>
</html>
