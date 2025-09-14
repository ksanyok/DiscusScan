<?php
require_once __DIR__ . '/db.php';
require_login();

// Repo config (используется и в footer.php)
$repoOwner = 'ksanyok';
$repoName  = 'DiscusScan';
$branch    = 'main';
$zipUrl    = "https://github.com/$repoOwner/$repoName/archive/refs/heads/$branch.zip";
$dataDir   = __DIR__ . '/data';
$lastUpdateFile = $dataDir . '/last_update.txt';
if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);

$message = '';
$success = false;

include_once __DIR__ . '/version.php';
$localVersionEarly = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
$remoteRawVersionUrl = "https://raw.githubusercontent.com/$repoOwner/$repoName/$branch/version.php";
$skipUpdate = false; $remoteVersionFound = null;
function fetch_remote_version($url){
    $content = false; $ver = null;
    if (function_exists('curl_init')) { $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>8]); $d=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); if($d!==false && $c>=200 && $c<400) $content=$d; }
    if ($content===false) $content=@file_get_contents($url);
    if ($content && preg_match('/define\s*\(\s*["\']APP_VERSION["\']\s*,\s*["\']([\d\.]+)["\']\s*\)/i',$content,$m)) $ver=$m[1];
    return $ver;
}

// === Расширение: поддержка обновления по тегам (стабильные релизы) ===
$tagsApiUrl = "https://api.github.com/repos/$repoOwner/$repoName/tags?per_page=40"; // первые 40
$tagsCacheFile = $dataDir.'/tags_cache.json';
$tags = [];$stableTags=[];$latestStable=null;

if(!function_exists('e')){ function e($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); } }
// Reliable HTTP fetch with headers
function http_get_ds($url,$timeout=12){
    $ua='DiscusScan-Updater/1.0 (+https://github.com/ksanyok/DiscusScan)';
    $opts=['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"User-Agent: $ua\r\nAccept: */*\r\n"]];
    $ctx=stream_context_create($opts); $data=@file_get_contents($url,false,$ctx); if($data!==false) return $data; if(function_exists('curl_init')){ $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>['User-Agent: '.$ua,'Accept: */*']]); $d=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); if($d!==false && $code>=200 && $code<400) return $d; }
    return false;
}
// Override fetch_tags to force headers even in fallback
function fetch_tags($apiUrl, $cacheFile){
    $useCache=false; if(is_file($cacheFile) && time()-filemtime($cacheFile)<300) $useCache=true; // 5m cache
    if($useCache){ $raw=@file_get_contents($cacheFile); if($raw){ $d=json_decode($raw,true); if(is_array($d)) return $d; }}
    $data=http_get_ds($apiUrl); $res=is_array($data)?$data:json_decode($data,true);
    if(is_array($res)) @file_put_contents($cacheFile,json_encode($res));
    return is_array($res)?$res:[];
}
// Fetch releases meta (optional, no fatal on failure)
$releasesCache=$dataDir.'/releases_cache.json';
$releases=[]; $releasesApi="https://api.github.com/repos/$repoOwner/$repoName/releases?per_page=30";
try{ $releases=json_decode(http_get_ds($releasesApi)?:'[]',true)?:[]; if($releases) @file_put_contents($releasesCache,json_encode($releases)); }
catch(Throwable $e){ if(is_file($releasesCache)) $releases=json_decode(@file_get_contents($releasesCache),true)?:[]; }
// Map tag => body (release notes)
$releaseNotes=[]; foreach($releases as $r){ if(!empty($r['tag_name'])) $releaseNotes[$r['tag_name']] = trim($r['body'] ?? ''); }

try { $tagsRaw = fetch_tags($tagsApiUrl,$tagsCacheFile); } catch(Throwable $e){ $tagsRaw=[]; }
foreach($tagsRaw as $tRow){ if(!isset($tRow['name'])) continue; $n=$tRow['name']; $tags[]=$n; }
// Стабильные: строгий семвер X.Y.Z с необязательным префиксом v
$stableTags = []; // теперь массив вида [['tag'=>'v1.3.5','ver'=>'1.3.5'], ...]
foreach($tags as $t){ if(preg_match('~^v?(\d+\.\d+\.\d+)$~',$t,$m)) $stableTags[]=['tag'=>$t,'ver'=>$m[1]]; }
// Сортировка по нормализованной версии (ver)
usort($stableTags,function($a,$b){ return version_compare($b['ver'],$a['ver']); });
$latestStable = $stableTags[0]['tag'] ?? null;

$updateType = $_POST['update_type'] ?? 'branch'; // branch|tag
$selectedTag = $_POST['tag_name'] ?? '';
$downloadModeLabel = $updateType==='tag' ? ('Тег '.htmlspecialchars($selectedTag)) : ('Ветка '.htmlspecialchars($branch).' (beta)');

// Переопределим $remoteRawVersionUrl если выбран тег
if($updateType==='tag' && $selectedTag!==''){
    $remoteRawVersionUrl = "https://raw.githubusercontent.com/$repoOwner/$repoName/".rawurlencode($selectedTag)."/version.php";
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update'])) {
    $remoteVersionFound = fetch_remote_version($remoteRawVersionUrl);
    if($updateType==='tag' && $selectedTag===''){
        $message='Не выбран тег.'; $skipUpdate=true;
    } else {
        if ($remoteVersionFound && version_compare($remoteVersionFound, $localVersionEarly, '<=') && empty($_POST['force'])) {
            $message = 'Нет новой версии (локальная v'.$localVersionEarly.', удалённая v'.($remoteVersionFound ?: '—').'). Добавьте флажок принудительно, чтобы перезаписать.';
            $skipUpdate = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update']) && !$skipUpdate) {
    $output = [];
    $successMsg = '';
    if($updateType==='branch'){
        // === Сценарий как раньше ===
        if (is_dir(__DIR__ . '/.git')) {
            $cmd = 'git -C ' . escapeshellarg(__DIR__) . ' pull origin ' . escapeshellarg($branch) . ' 2>&1';
            $ret = 0; exec($cmd, $output, $ret);
            if ($ret === 0) { $successMsg = 'git pull успешно.'; $success = true; } else { $message = 'git pull ошибка: ' . htmlspecialchars(implode("\n", $output)); }
        } else {
            $zipUrl    = "https://github.com/$repoOwner/$repoName/archive/refs/heads/$branch.zip";
            $tmpZip = sys_get_temp_dir() . '/discuscan_update_' . bin2hex(random_bytes(6)) . '.zip';
            $downloaded = false; $outDl='';
            // curl fallback
            if (function_exists('curl_init')) {
                $ch = curl_init($zipUrl);
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>25]);
                $data = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($data !== false && $code >= 200 && $code < 400) { file_put_contents($tmpZip, $data); $downloaded = true; } else { $outDl = 'HTTP ' . ($code ?? 'n/a'); }
            }
            if (!$downloaded) {
                $data = @file_get_contents($zipUrl);
                if ($data) { file_put_contents($tmpZip, $data); $downloaded = true; }
            }
            if (!$downloaded) {
                $message = 'Не удалось скачать архив (' . $outDl . ').';
            } else {
                if (!class_exists('ZipArchive')) {
                    $message = 'ZipArchive недоступен в PHP.';
                } else {
                    $za = new ZipArchive();
                    if ($za->open($tmpZip) === true) {
                        $extractDir = sys_get_temp_dir() . '/discuscan_unpack_' . bin2hex(random_bytes(5));
                        mkdir($extractDir, 0755, true);
                        $za->extractTo($extractDir); $za->close();
                        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR); $srcRoot = $dirs[0] ?? $extractDir;
                        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                        $copied = 0;
                        foreach ($it as $item) {
                            $rel = substr($item->getPathname(), strlen($srcRoot)+1);
                            if ($rel === '' || $rel === false) continue;
                            if (strpos($rel, '.git') === 0) continue;
                            if (strpos($rel, 'installer.php') === 0) continue;
                            if (strpos($rel, '.env') === 0) continue;
                            $target = __DIR__ . '/' . $rel;
                            if ($item->isDir()) { if (!is_dir($target)) @mkdir($target, 0755, true); }
                            else { if (@copy($item->getPathname(), $target)) $copied++; }
                        }
                        // cleanup
                        @unlink($tmpZip);
                        $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($ri as $f) { $f->isFile() ? @unlink($f->getPathname()) : @rmdir($f->getPathname()); }
                        @rmdir($extractDir);
                        if ($copied) { 
                            $successMsg = "Файлы обновлены ($copied)."; 
                            $success = true; 
                            // Clear cache and opcache
                            clearstatcache();
                            if (function_exists('opcache_reset')) @opcache_reset();
                        } else { 
                            $message = 'Архив распакован, но файлы не скопированы.'; 
                        }
                    } else { $message = 'Не удалось открыть архив.'; }
                }
            }
        }
    } else { // tag
        $tag = $selectedTag;
        $zipUrlTag = "https://github.com/$repoOwner/$repoName/archive/refs/tags/".rawurlencode($tag).".zip";
        $gitUsed=false; $gitOk=false;
        if(is_dir(__DIR__.'/.git')){
            // Попытка git checkout тега
            $ret1=0; $ret2=0; $out1=[]; $out2=[];
            exec('git -C '.escapeshellarg(__DIR__).' fetch --tags --quiet 2>&1',$out1,$ret1);
            exec('git -C '.escapeshellarg(__DIR__).' checkout -f tags/'.escapeshellarg($tag).' 2>&1',$out2,$ret2);
            if($ret1===0 && $ret2===0){ $gitUsed=true; $gitOk=true; $success=true; $successMsg='Переключено на тег '.$tag.' (git checkout).'; }
            else { $output=array_merge($out1,$out2); }
        }
        if(!$gitOk){
            // Fallback на ZIP архива тега
            $tmpZip = sys_get_temp_dir() . '/discuscan_tag_' . bin2hex(random_bytes(6)) . '.zip';
            $downloaded=false; $outDl='';
            if(function_exists('curl_init')){
                $ch=curl_init($zipUrlTag); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>25]);
                $data=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                if($data!==false && $code>=200 && $code<400){ file_put_contents($tmpZip,$data); $downloaded=true; } else { $outDl='HTTP '.($code ?? 'n/a'); }
            }
            if(!$downloaded){ $data=@file_get_contents($zipUrlTag); if($data){ file_put_contents($tmpZip,$data); $downloaded=true; } }
            if(!$downloaded){ $message='Не удалось скачать архив тега ('.$outDl.').'; }
            else {
                if(!class_exists('ZipArchive')){ $message='ZipArchive недоступен'; }
                else {
                    $za=new ZipArchive(); if($za->open($tmpZip)===true){
                        $extractDir = sys_get_temp_dir().'/discuscan_unpack_tag_'.bin2hex(random_bytes(4));
                        mkdir($extractDir,0755,true); $za->extractTo($extractDir); $za->close();
                        $dirs = glob($extractDir.'/*', GLOB_ONLYDIR); $srcRoot=$dirs[0] ?? $extractDir;
                        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
                        $copied=0; foreach($it as $item){ $rel=substr($item->getPathname(), strlen($srcRoot)+1); if($rel===''||$rel===false) continue; if(strpos($rel,'.git')===0) continue; if(strpos($rel,'installer.php')===0) continue; if(strpos($rel,'.env')===0) continue; $target=__DIR__.'/'.$rel; if($item->isDir()){ if(!is_dir($target)) @mkdir($target,0755,true); } else { if(@copy($item->getPathname(),$target)) $copied++; } }
                        // cleanup
                        @unlink($tmpZip);
                        $ri=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
                        foreach($ri as $f){ $f->isFile()? @unlink($f->getPathname()):@rmdir($f->getPathname()); }
                        @rmdir($extractDir);
                        if($copied){ $success=true; $successMsg='Файлы тега '.$tag.' обновлены ('.$copied.').'; if(function_exists('opcache_reset')) @opcache_reset(); }
                        else $message='Архив распакован, но не скопированы файлы.';
                    } else { $message='Не удалось открыть архив тега.'; }
                }
            }
        }
    }

    if ($success) {
        try { pdo(); $successMsg .= ' Миграции выполнены.'; } catch (Throwable $e) { $message .= ' Ошибка миграций: ' . $e->getMessage(); }
        // Записываем дату обновления
        @file_put_contents($lastUpdateFile, date('Y-m-d'));
    }
    if ($successMsg) $message = trim($successMsg . ' ' . $message);
}

include_once __DIR__ . '/version.php';
// Заменяем чтение константы на парсинг файла, чтобы не зависеть от уже определённой APP_VERSION
$versionFile = __DIR__ . '/version.php';
$localVersion = '0.0.0';
if (is_file($versionFile)) {
  $rawV = @file_get_contents($versionFile);
  if ($rawV && preg_match('/APP_VERSION\s*[,=]?\s*["\']([\d\.]+)["\']/', $rawV, $m)) {
    $localVersion = $m[1];
  } elseif (defined('APP_VERSION')) {
    $localVersion = APP_VERSION; // fallback
  }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Обновление — DiscusScan</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Enhanced updater UI */
    body{background: radial-gradient(circle at 30% 18%,#142542,#0a1326);}
    .update-layout{display:grid;gap:22px;grid-template-columns:1fr 320px;align-items:start;}
    @media (max-width:980px){ .update-layout{grid-template-columns:1fr;} }
    .panel{border:1px solid var(--border);border-radius:18px;padding:20px 22px;background:linear-gradient(180deg,rgba(255,255,255,.05),rgba(255,255,255,.02));backdrop-filter:blur(10px);}
    .panel h2{margin:0 0 14px;font-size:18px;font-weight:600;}
    .tags-list{max-height:380px;overflow:auto;margin:0;padding:0;list-style:none;}
    .tags-list li{padding:8px 10px 10px;border:1px solid rgba(255,255,255,.07);border-radius:12px;margin-bottom:8px;display:flex;flex-direction:column;gap:6px;background:#111d36;}
    .tags-list li.active{border-color:#4b72ff;box-shadow:0 0 0 1px #4b72ff66;}
    .tag-head{display:flex;align-items:center;gap:10px;font-size:13px;}
    .tag-name{font-weight:600;}
    .tag-meta{font-size:11px;color:var(--muted);margin-left:auto;}
    .rel-notes{white-space:pre-wrap;font-size:11px;line-height:1.4;background:#0d192f;padding:10px 12px;border-radius:10px;max-height:150px;overflow:auto;margin:4px 0 0;}
    .empty-box{padding:18px 16px;font-size:13px;border:1px dashed var(--border);border-radius:16px;text-align:center;color:var(--muted);}
    .version-badge{display:inline-flex;align-items:center;gap:6px;background:#1d315d;color:#fff;padding:6px 10px;border-radius:30px;font-size:12px;font-weight:600;}
    .ver-local{background:#233a6b;}
    .ver-remote{background:#314b82;}
    .changelog-hint{font-size:11px;color:var(--muted);margin-top:6px;}
    .radio-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;font-size:13px;}
    .submit-row{display:flex;gap:16px;align-items:center;margin-top:18px;}
    .diff-info{font-size:11px;color:var(--muted);}
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="container">
  <h1 style="margin-top:4px;margin-bottom:14px;">Обновление DiscusScan</h1>
  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
    <span class="version-badge ver-local">Текущая: v<?=e($localVersion)?></span>
    <?php if($latestStable): $latestStableLabel = strpos($latestStable,'v')===0 ? $latestStable : ('v'.$latestStable); ?>
      <span class="version-badge ver-remote">Последняя стабильная: <?=e($latestStableLabel)?></span>
    <?php endif; ?>
    <?php if(isset($remoteVersionFound) && $remoteVersionFound): ?><span class="version-badge">Удалённая выбрана: v<?=e($remoteVersionFound)?></span><?php endif; ?>
  </div>
  <?php if ($message): ?>
    <div class="alert <?= $success ? 'success' : 'danger' ?>" style="margin-bottom:18px;"><?= nl2br(htmlspecialchars($message)) ?></div>
  <?php endif; ?>
  <div class="update-layout">
    <div class="panel">
      <h2>Режим</h2>
      <form method="post" id="updateForm">
        <input type="hidden" name="update" value="1">
        <div class="radio-row"><label><input type="radio" name="update_type" value="branch" <?= $updateType!=='tag'?'checked':''?>> Beta (ветка <?=e($branch)?>, самая свежая, может быть нестабильной)</label></div>
        <div class="radio-row"><label><input type="radio" name="update_type" value="tag" <?= $updateType==='tag'?'checked':''?>> Стабильный релиз (тег)</label></div>
        <div style="margin:10px 0 14px 4px;">
          <select name="tag_name" id="tagSelect" style="max-width:300px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:#0f1733;color:#fff;" <?= $updateType==='tag'?'':'disabled'?>>
            <option value="">— выбрать тег —</option>
            <?php foreach($stableTags as $st): $t=$st['tag']; $ver=$st['ver']; ?>
              <option value="<?=e($t)?>" data-notes="<?= e(substr($releaseNotes[$t] ?? '',0,800)) ?>" <?= $t===$selectedTag?'selected':''?>><?=e($t)?><?=$t===$latestStable?'  • latest':''?></option>
            <?php endforeach; ?>
          </select>
          <?php if(!$stableTags): ?><div class="changelog-hint">Не удалось получить список тегов (возможно, ограничение GitHub API). Попробуйте позже.</div><?php endif; ?>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-top:4px;">
          <input type="checkbox" name="force" value="1" <?= !empty($_POST['force'])?'checked':''?>> Принудительно (перезаписать даже если версия не новее)
        </label>
        <div class="submit-row">
          <?php $btnTagLabel = ($updateType==='tag' && $selectedTag) ? (strpos($selectedTag,'v')===0 ? $selectedTag : 'v'.$selectedTag) : 'Beta'; ?>
          <button type="submit" class="btn primary" style="min-width:180px;">Обновить → <?=e($btnTagLabel)?></button>
          <div class="diff-info" id="diffInfo">&nbsp;</div>
        </div>
      </form>
      <div class="changelog-hint">После обновления на тег для возврата к beta выберите режим Beta и снова выполните обновление (git checkout ветки).</div>
      <div class="muted" style="font-size:11px;margin-top:14px;">.git: <?= is_dir(__DIR__.'/.git') ? 'обнаружен — используется git операции' : 'нет — используется загрузка ZIP' ?>.</div>
    </div>
    <div class="panel" style="min-height:300px;">
      <h2 style="margin-bottom:10px;">Release notes</h2>
      <div id="relNotesBox">
        <?php if($updateType==='tag' && $selectedTag && isset($releaseNotes[$selectedTag]) && $releaseNotes[$selectedTag] !== ''): ?>
          <div class="rel-notes" id="relNotesContent"><?= nl2br(e(mb_substr($releaseNotes[$selectedTag],0,4000))) ?></div>
        <?php else: ?>
          <div class="empty-box" id="relNotesEmpty">Выберите стабильный тег чтобы увидеть заметки релиза.</div>
        <?php endif; ?>
      </div>
      <?php if($stableTags): ?>
      <hr style="margin:18px 0;border:none;border-top:1px solid var(--border);opacity:.4;">
      <h2 style="margin:0 0 12px;font-size:16px;">Последние стабильные</h2>
      <ul class="tags-list" id="tagsList">
        <?php foreach(array_slice($stableTags,0,10) as $st): $t=$st['tag']; ?>
          <li class="<?= $t===$selectedTag? 'active':''?>" data-tag="<?=e($t)?>" data-notes="<?= e(substr($releaseNotes[$t] ?? '',0,1200)) ?>">
            <div class="tag-head"><span class="tag-name"><?= strpos($t,'v')===0? e($t) : 'v'.e($t) ?></span><span class="tag-meta"><?= $t===$latestStable?'latest':''?></span></div>
            <?php if(!empty($releaseNotes[$t])): ?><div class="rel-notes" style="max-height:90px;"><?= nl2br(e(mb_substr($releaseNotes[$t],0,500))) ?></div><?php else: ?><div class="rel-notes" style="background:transparent;padding:0;color:var(--muted);">(нет заметок)</div><?php endif; ?>
            <div><button type="button" class="btn tiny" data-choose-tag="<?=e($t)?>">Выбрать</button></div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php include 'footer.php'; ?>
<script>
const tagRadios=document.querySelectorAll('input[name=update_type]');
const tagSelect=document.getElementById('tagSelect');
const relNotesBox=document.getElementById('relNotesBox');
const form=document.getElementById('updateForm');
function setMode(){ const isTag=document.querySelector('input[name=update_type][value=tag]').checked; tagSelect.disabled=!isTag; if(!isTag){ relNotesBox.innerHTML='<div class="empty-box">Режим Beta: заметки не отображаются.</div>'; } else { if(tagSelect.value===''){ relNotesBox.innerHTML='<div class="empty-box">Выберите стабильный тег чтобы увидеть заметки релиза.</div>'; } else updateNotes(); } }
function updateNotes(){ const opt=tagSelect.options[tagSelect.selectedIndex]; if(!opt){ return; } const notes=opt.dataset.notes||''; if(notes){ relNotesBox.innerHTML='<div class="rel-notes" id="relNotesContent">'+notes.replace(/\n/g,'<br>')+'</div>'; } else { relNotesBox.innerHTML='<div class="empty-box">Нет заметок для этого тега.</div>'; } }

tagRadios.forEach(r=>r.addEventListener('change', setMode));
tagSelect && tagSelect.addEventListener('change', updateNotes);
setMode();
// Quick choose buttons
const list=document.getElementById('tagsList');
if(list){ list.addEventListener('click',e=>{ const btn=e.target.closest('[data-choose-tag]'); if(!btn) return; const tag=btn.getAttribute('data-choose-tag'); document.querySelector('input[name=update_type][value=tag]').checked=true; setMode(); for(const o of tagSelect.options){ if(o.value===tag){ tagSelect.value=tag; updateNotes(); break; } } list.querySelectorAll('li').forEach(li=>li.classList.toggle('active', li.dataset.tag===tag)); }); }
</script>
</body>
</html>