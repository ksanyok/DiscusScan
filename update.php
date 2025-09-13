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

function fetch_tags($apiUrl, $cacheFile){
    $useCache=false; if(is_file($cacheFile)){ if(time()-filemtime($cacheFile) < 600) $useCache=true; }
    if($useCache){ $raw=@file_get_contents($cacheFile); if($raw){ $d=json_decode($raw,true); if(is_array($d)) return $d; } }
    $res=null; $headers=["User-Agent: DiscusScan-Updater","Accept: application/vnd.github+json"];
    if(function_exists('curl_init')){
        $ch=curl_init($apiUrl); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers]);
        $d=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if($d!==false && $c>=200 && $c<400){ $res=json_decode($d,true); }
    }
    if(!$res){ $d=@file_get_contents($apiUrl); if($d) $res=json_decode($d,true); }
    if(is_array($res)) @file_put_contents($cacheFile, json_encode($res));
    return is_array($res)?$res:[];
}

try { $tagsRaw = fetch_tags($tagsApiUrl,$tagsCacheFile); } catch(Throwable $e){ $tagsRaw=[]; }
foreach($tagsRaw as $tRow){ if(!isset($tRow['name'])) continue; $n=$tRow['name']; $tags[]=$n; }
// Стабильные: строгий семвер X.Y.Z (без префиксов / суффиксов)
foreach($tags as $t){ if(preg_match('~^\d+\.\d+\.\d+$~',$t)) $stableTags[]=$t; }
// Сортировка стабильных по убыванию версии
usort($stableTags,function($a,$b){ return version_compare($b,$a); });
$latestStable = $stableTags[0] ?? null;

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
    .update-box{border:1px solid var(--border); padding:16px 18px; border-radius:12px; background:rgba(255,255,255,0.04); margin:18px 0;}
    .tags-select{max-width:260px;}
    .badge{display:inline-block; background:#394b89; color:#fff; padding:2px 8px; font-size:11px; border-radius:20px; margin-left:6px;}
    .muted-small{font-size:11px; opacity:.75;}
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="container">
  <h1>Обновление приложения</h1>
  <p>Текущая версия: v<?= htmlspecialchars($localVersion) ?> <?php if($latestStable): ?><span class="badge">Последняя стабильная: v<?=e($latestStable)?></span><?php endif; ?></p>
  <?php if ($message): ?>
    <div class="alert <?= $success ? 'success' : 'danger' ?>"><?= nl2br(htmlspecialchars($message)) ?></div>
  <?php endif; ?>
  <div class="update-box">
    <form method="post">
      <input type="hidden" name="update" value="1">
      <fieldset style="border:none; padding:0; margin:0 0 14px;">
        <legend style="font-weight:600; margin-bottom:6px;">Выбор источника обновления</legend>
        <label style="display:flex; gap:6px; margin-bottom:6px; align-items:center;">
          <input type="radio" name="update_type" value="branch" <?= $updateType!=='tag'?'checked':''?>> Beta (ветка <?=e($branch)?>)
        </label>
        <label style="display:flex; gap:6px; align-items:center;">
          <input type="radio" name="update_type" value="tag" <?= $updateType==='tag'?'checked':''?>> Стабильный релиз (тег)
          <?php if(!$stableTags): ?><span class="muted-small">Теги не получены</span><?php endif; ?>
        </label>
        <div style="margin:6px 0 0 26px;">
          <select name="tag_name" class="tags-select" <?= $updateType==='tag'?'':'disabled'?>>
            <option value="">— выбрать тег —</option>
            <?php foreach($stableTags as $t): ?>
              <option value="<?=e($t)?>" <?= ($t===$selectedTag)?'selected':''?>><?=e($t)?><?=$t===$latestStable?' (latest)':''?></option>
            <?php endforeach; ?>
          </select>
          <div class="muted-small">Показываются только стабильные теги (X.Y.Z). Beta — всегда последняя кодовая база, может быть нестабильной.</div>
        </div>
      </fieldset>
      <label style="display:flex; gap:6px; align-items:center; margin-bottom:12px;">
        <input type="checkbox" name="force" value="1"> Принудительно (перезаписать даже если версия не новее)
      </label>
      <button type="submit" class="btn primary">Обновить → <span style="font-weight:400;"><?=$downloadModeLabel?></span></button>
    </form>
    <div class="muted" style="font-size:12px; margin-top:10px; line-height:1.5;">
      <b>Примечание.</b> При выборе тега выполняется checkout этого тега (если есть git) или скачивание ZIP.
      Ветка <?=e($branch)?> = поток beta. Стабильные релизы помечены тегами. Если после перехода на тег хотите вернуться на beta — выберите снова режим Beta и обновите.
    </div>
    <div class="muted-small" style="margin-top:8px;">Папка .git <?= is_dir(__DIR__.'/.git') ? 'найдена — используется git.' : 'не найдена — используется ZIP.' ?></div>
  </div>
</main>
<?php include 'footer.php'; ?>
<script>
// JS для включения/отключения select тега
const radios = document.querySelectorAll('input[name=update_type]');
const tagSelect = document.querySelector('select[name=tag_name]');
radios.forEach(r=>r.addEventListener('change', ()=>{ if(r.value==='tag' && r.checked){ tagSelect.disabled=false; } else if(r.value==='branch' && r.checked){ tagSelect.disabled=true; tagSelect.selectedIndex=0; } }));
</script>
</body>
</html>