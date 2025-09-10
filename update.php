<?php
require_once __DIR__ . '/db.php';
require_login();

// Check if user is admin or has update permission
// For simplicity, assume logged in user can update

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $output = [];
    $successMsg = '';
    // If this directory is a git working copy, do a git pull. Otherwise fall back to downloading the archive.
    if (is_dir(__DIR__ . '/.git')) {
        $cmd = 'git -C ' . escapeshellarg(__DIR__) . ' pull origin main 2>&1';
        $ret = 0;
        exec($cmd, $output, $ret);
        if ($ret === 0) {
            $successMsg = 'git pull выполнен успешно.';
        } else {
            $message = 'Ошибка при обновлении через git: ' . implode("\n", $output);
        }
    } else {
        // Not a git repo — download ZIP from GitHub and unpack
        $repoUrl = 'https://github.com/oleksandr/DiscusScan/archive/refs/heads/main.zip';
        $tmpZip = sys_get_temp_dir() . '/discuscan_update_' . bin2hex(random_bytes(6)) . '.zip';
        $downloaded = false;
        // Try curl then file_get_contents
        if (function_exists('curl_version')) {
            $ch = curl_init($repoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($data !== false && $httpCode >= 200 && $httpCode < 400) {
                file_put_contents($tmpZip, $data);
                $downloaded = true;
            } else {
                $output[] = 'ZIP download failed, HTTP code: ' . ($httpCode ?? 'unknown');
            }
        } else {
            $data = @file_get_contents($repoUrl);
            if ($data !== false) {
                file_put_contents($tmpZip, $data);
                $downloaded = true;
            } else {
                $output[] = 'file_get_contents failed to download ZIP. enable allow_url_fopen or install curl.';
            }
        }

        $copied = false;
        if ($downloaded) {
            if (!class_exists('ZipArchive')) {
                $output[] = 'ZipArchive не доступен в PHP, распаковка архива невозможна.';
            } else {
                $za = new ZipArchive();
                if ($za->open($tmpZip) === true) {
                    $extractDir = sys_get_temp_dir() . '/discuscan_unpack_' . bin2hex(random_bytes(6));
                    mkdir($extractDir, 0755, true);
                    $za->extractTo($extractDir);
                    $za->close();

                    // find extracted root
                    $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
                    $srcRoot = $dirs[0] ?? $extractDir;

                    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($it as $item) {
                        $rel = substr($item->getPathname(), strlen($srcRoot) + 1);
                        if ($rel === '' || $rel === false) continue;
                        // skip .env, installer and .git
                        if (strpos($rel, '.env') === 0) continue;
                        if (strpos($rel, 'installer.php') === 0) continue;
                        if (strpos($rel, '.git') === 0) continue;

                        $target = __DIR__ . '/' . $rel;
                        if ($item->isDir()) {
                            if (!is_dir($target)) @mkdir($target, 0755, true);
                        } else {
                            @copy($item->getPathname(), $target);
                            $copied = true;
                        }
                    }

                    // cleanup
                    @unlink($tmpZip);
                    $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                    foreach ($ri as $f) { if ($f->isFile()) @unlink($f->getPathname()); else @rmdir($f->getPathname()); }
                    @rmdir($extractDir);

                    if ($copied) $successMsg = 'Архив скачан и файлы обновлены.'; else $output[] = 'Архив распакован, но файлы не были скопированы.';
                } else {
                    $output[] = 'Не удалось открыть ZIP-архив.';
                }
            }
        }
    }

    // If we have a success message or no fatal errors, try to run migrations (install_schema via pdo())
    if ($successMsg || empty($message)) {
        try {
            include_once __DIR__ . '/db.php';
            $pdo = pdo(); // will create tables and ensure defaults
            $message = ($successMsg ? $successMsg . ' ' : '') . 'Миграции применены.';
        } catch (Throwable $e) {
            $message = ($successMsg ? $successMsg . ' ' : '') . 'Обновление выполнено, но применение миграций завершилось с ошибкой: ' . $e->getMessage();
        }
    }
}

// Load current version
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Обновление — DiscusScan</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🔎 Мониторинг</div>
  <nav>
    <a href="index.php">Дашборд</a>
    <a href="sources.php">Домены</a>
    <a href="settings.php">Настройки</a>
    <a href="auth.php?logout=1">Выход</a>
  </nav>
</header>

<main class="container">
  <h1>Обновление приложения</h1>
  <p>Текущая версия: v<?= htmlspecialchars($localVersion) ?></p>

  <?php if ($message): ?>
    <div class="alert <?= $success ? 'success' : 'error' ?>">
      <?= nl2br(htmlspecialchars($message)) ?>
    </div>
  <?php endif; ?>

  <form method="post">
    <button type="submit" name="update" class="btn primary">Запустить обновление (git pull)</button>
  </form>

  <p><small>Это выполнит git pull из репозитория, затем применит необходимые изменения в базе данных. Убедитесь, что у сервера есть доступ к git и права на запись в директорию приложения.</small></p>

  <section style="margin-top:18px;">
    <details class="card glass">
      <summary class="card-title">Советы</summary>
      <div class="content">
        <ul>
          <li>Если на сервере нет доступа к git, загрузите архив с GitHub и распакуйте его в папку приложения.</li>
          <li>Обновление автоматически попытается применить необходимые изменения в базе данных.</li>
          <li>Если после обновления вы видите ошибки доступа к файлам, проверьте права и владельца.</li>
        </ul>
      </div>
    </details>
  </section>

</main>
<?php include 'footer.php'; ?>
</body>
</html>