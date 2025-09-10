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
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update'])) {
    $remoteVersionFound = fetch_remote_version($remoteRawVersionUrl);
    if ($remoteVersionFound && version_compare($remoteVersionFound, $localVersionEarly, '<=' ) && empty($_POST['force'])) {
        $message = 'Нет новой версии (локальная v'.$localVersionEarly.', удалённая v'.($remoteVersionFound ?: '—').'). Обновление отменено.';
        $skipUpdate = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update']) && !$skipUpdate) {
    $output = [];
    $successMsg = '';
    if (is_dir(__DIR__ . '/.git')) {
        $cmd = 'git -C ' . escapeshellarg(__DIR__) . ' pull origin ' . escapeshellarg($branch) . ' 2>&1';
        $ret = 0; exec($cmd, $output, $ret);
        if ($ret === 0) { $successMsg = 'git pull успешно.'; $success = true; } else { $message = 'git pull ошибка: ' . htmlspecialchars(implode("\n", $output)); }
    } else {
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
</head>
<body>
<?php include 'header.php'; ?>
<main class="container">
  <h1>Обновление приложения</h1>
  <p>Текущая версия: v<?= htmlspecialchars($localVersion) ?></p>
  <?php if ($message): ?>
    <div class="alert <?= $success ? 'success' : 'danger' ?>"><?= nl2br(htmlspecialchars($message)) ?></div>
  <?php endif; ?>
  <form method="post" style="margin-bottom:12px;">
    <button type="submit" name="update" class="btn primary">Обновить (<?= htmlspecialchars($branch) ?>)</button>
  </form>
  <div class="muted" style="font-size:12px;">Папка .git <?= is_dir(__DIR__.'/.git') ? 'обнаружена — используется git pull.' : 'не найдена — скачивание ZIP.' ?></div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>