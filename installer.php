<?php
// installer.php — простой мастер установки (загружаемый на хостинг)
// Этот инсталлер создаёт .env в корне, запускает миграции и пытается удалить себя после успешной установки.

// Load version only for display
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';

$errors = [];
$success = false;
$output = [];
$selfDeleted = false;

// Use .env in project root
$envPath = __DIR__ . '/.env';
if (file_exists($envPath) && empty($_POST['force_overwrite'])) {
    $errors[] = 'Файл .env уже существует. Удалите или переименуйте его перед запуском мастера, либо отметьте "Перезаписать".';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = trim($_POST['db_pass'] ?? '');
    $db_charset = trim($_POST['db_charset'] ?? 'utf8mb4');

    $admin_user = trim($_POST['admin_user'] ?? 'admin');
    $admin_pass = trim($_POST['admin_pass'] ?? 'admin');

    if ($db_name === '' || $db_user === '') {
        $errors[] = 'Укажите имя базы данных и пользователя.';
    }

    if (empty($errors)) {
        // Compose .env content
        $lines = [];
        $lines[] = '# DiscusScan configuration created by installer';
        $lines[] = 'DB_HOST=' . str_replace("\n", '', $db_host);
        $lines[] = 'DB_NAME=' . str_replace("\n", '', $db_name);
        $lines[] = 'DB_USER=' . str_replace("\n", '', $db_user);
        $lines[] = 'DB_PASS=' . str_replace("\n", '', $db_pass);
        $lines[] = 'DB_CHARSET=' . str_replace("\n", '', $db_charset);
        $content = implode("\n", $lines) . "\n";

        // Try to write .env
        $w = @file_put_contents($envPath, $content, LOCK_EX);
        if ($w === false) {
            $errors[] = 'Не удалось записать файл .env. Проверьте права на директорию.';
        } else {
            // Instead of calling migrate.php externally, perform install steps here:
            // 1) Try to download latest files from GitHub (main branch zip)
            // 2) Extract and copy files into current directory (don't overwrite .env or installer.php)
            // 3) Include db.php and call pdo() to create tables
            // 4) Create admin user
            try {
                $repoUrl = 'https://github.com/ksanyok/DiscusScan/archive/refs/heads/main.zip';
                $tmpZip = sys_get_temp_dir() . '/discuscan_repo.zip';
                $downloaded = false;

                // Try curl if available
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
                    }
                } else {
                    $data = @file_get_contents($repoUrl);
                    if ($data !== false) {
                        file_put_contents($tmpZip, $data);
                        $downloaded = true;
                    }
                }

                $copiedAnything = false;
                if ($downloaded && class_exists('ZipArchive')) {
                    $za = new ZipArchive();
                    if ($za->open($tmpZip) === true) {
                        $extractDir = sys_get_temp_dir() . '/discuscan_unpack_' . bin2hex(random_bytes(6));
                        mkdir($extractDir, 0755, true);
                        $za->extractTo($extractDir);
                        $za->close();

                        // find extracted top-level folder
                        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
                        $srcRoot = $dirs[0] ?? $extractDir;

                        // recursive copy excluding .env and installer.php and .git
                        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                        foreach ($it as $item) {
                            $rel = substr($item->getPathname(), strlen($srcRoot) + 1);
                            if ($rel === '' || $rel === false) continue;
                            // skip .env and installer
                            if (strpos($rel, '.env') === 0) continue;
                            if (strpos($rel, 'installer.php') === 0) continue;
                            if (strpos($rel, '.git') === 0) continue;

                            $target = __DIR__ . '/' . $rel;
                            if ($item->isDir()) {
                                if (!is_dir($target)) @mkdir($target, 0755, true);
                            } else {
                                @copy($item->getPathname(), $target);
                                $copiedAnything = true;
                            }
                        }

                        // cleanup
                        @unlink($tmpZip);
                        // remove extracted temp dir recursively
                        $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($ri as $f) { if ($f->isFile()) @unlink($f->getPathname()); else @rmdir($f->getPathname()); }
                        @rmdir($extractDir);
                    }
                }

                // diagnostics for db.php include and pdo() availability
                if (!file_exists(__DIR__ . '/db.php')) {
                    $errors[] = 'Файл db.php не найден в директории приложения: ' . __DIR__;
                } else {
                    // Включаем режим инсталлятора, чтобы при ошибке подключения к БД db.php бросал исключение, а не делал die()
                    if (!defined('INSTALLER_MODE')) define('INSTALLER_MODE', true);
                    // record existing user functions to detect changes after include
                    $beforeFuncs = get_defined_functions()['user'] ?? [];
                    $incOk = @include_once __DIR__ . '/db.php';
                    if ($incOk === false) {
                        $errors[] = 'Не удалось включить db.php (include_once вернул false)';
                    } elseif (!function_exists('pdo')) {
                        $afterFuncs = get_defined_functions()['user'] ?? [];
                        $new = array_values(array_diff($afterFuncs, $beforeFuncs));
                        $errors[] = 'Функция pdo() не определена после включения db.php. Новые функции из включённого файла: ' . implode(', ', array_slice($new, 0, 20));
                        $sz = @filesize(__DIR__ . '/db.php') ?: 0;
                        $errors[] = 'Размер файла db.php: ' . $sz . ' байт. Проверьте, не пустой ли файл и корректно ли начинается с "<?php".';
                    } else {
                        try {
                            $pdo = pdo(); // installs schema and ensures defaults
                            // create admin user if not exists (override default if provided)
                            if ($admin_user) {
                                $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
                                $stmt->execute([$admin_user]);
                                if ((int)$stmt->fetchColumn() === 0) {
                                    $stmt = $pdo->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)');
                                    $stmt->execute([$admin_user, password_hash($admin_pass, PASSWORD_DEFAULT)]);
                                }
                            }
                            $success = true;

                            // Try to delete installer.php itself
                            try { @unlink(__FILE__); } catch (Throwable $e) { /* ignore */ }
                            $selfDeleted = !file_exists(__FILE__);

                            if (!$downloaded) {
                                $output[] = 'Не удалось скачать репозиторий автоматически. Приложение установлено локально, но файлы не были обновлены.';
                            } elseif (!$copiedAnything) {
                                $output[] = 'Архив скачан, но файлы не скопированы (возможно структура архива изменилась).';
                            }

                        } catch (Throwable $e) {
                            $errors[] = 'Ошибка при применении схемы: ' . $e->getMessage();
                            $errors[] = 'DSN host: ' . ($db_host ?: 'n/a') . ', DB: ' . ($db_name ?: 'n/a') . '. Проверьте хост/порт, пользователя, пароль и наличие расширения PDO MySQL.';
                        }
                    }
                }

            } catch (Throwable $e) {
                $errors[] = 'Ошибка установки: ' . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Установка — DiscusScan</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    /* Modern installer theme aligned with main styles.css */
    :root{
      --bg:#071025;
      --accent-start:#5b8cff;
      --accent-end:#7ea2ff;
      --card:linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
      --glass:rgba(255,255,255,0.03);
      --text:#eaf0ff;
      --muted:#9fb2ff;
      --border:rgba(255,255,255,0.06);
      --radius:16px;
      --shadow:0 10px 40px rgba(2,6,23,0.6);
    }

    html,body{height:100%;margin:0;font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

    /* Animated gradient + floating blobs (visually consistent with main styles) */
    body{
      background:
        radial-gradient(1200px 600px at 10% -10%, rgba(91,140,255,0.18) 0%, transparent 45%),
        radial-gradient(1200px 600px at 90% -10%, rgba(28,143,209,0.14) 0%, transparent 50%),
        var(--bg);
      background-attachment: fixed, fixed, fixed;
      background-size: 1600px 800px, 1600px 800px, auto;
    }
    body::before, body::after{ content:""; position:fixed; z-index:0; pointer-events:none; filter:blur(70px); }
    body::before{ left:-15%; top:-20%; width:70vw; height:60vh; background: radial-gradient(closest-side, rgba(91,140,255,0.32), transparent 70%); animation: float1 30s ease-in-out infinite alternate; }
    body::after{ right:-10%; bottom:-20%; width:65vw; height:55vh; background: radial-gradient(closest-side, rgba(28,143,209,0.30), transparent 70%); animation: float2 36s ease-in-out infinite alternate; }
    @keyframes float1{ from{ transform: translate3d(-5%, -5%, 0) scale(1);} to{ transform: translate3d(8%, 6%, 0) scale(1.08);} }
    @keyframes float2{ from{ transform: translate3d(6%, 4%, 0) scale(1);} to{ transform: translate3d(-8%, -6%, 0) scale(1.1);} }

    .container{max-width:920px;margin:40px auto;padding:18px;position:relative;z-index:1}
    .card{background:var(--card);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow);border:1px solid var(--border)}

    .top{display:flex;align-items:center;gap:14px}
    .logo{width:56px;height:56px;border-radius:12px;background:linear-gradient(135deg,var(--accent-start),var(--accent-end));display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:18px}

    .card .muted{color:var(--muted)}

    /* Form controls: larger, higher contrast */
    /* Improved: lighter inputs and responsive sizing to avoid overflow */
    .card input[type=text], .card input[type=password], .card input[name], .card input[type=url], .card select, .card textarea {
      box-sizing: border-box;
      width:100%;
      padding:14px 16px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,0.08);
      background: rgba(255,255,255,0.035); /* lighter than before to improve contrast */
      color:var(--text);
      font-size:15px;
      transition: box-shadow .18s ease, border-color .18s ease, transform .06s ease;
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
    }
    .card input::placeholder, .card textarea::placeholder{ color: rgba(230,240,255,0.55) }
    .card input:focus, .card textarea:focus, .card select:focus{
      outline:none;
      border-color: transparent;
      box-shadow: 0 12px 36px rgba(91,140,255,0.16);
      transform: translateY(-1px);
      background: rgba(255,255,255,0.045);
    }

    /* Ensure rows wrap and columns can shrink to prevent overflow */
    .row{ display:flex; gap:12px; flex-wrap:wrap }
    .col{ flex:1 1 0; min-width:0 }

    .btn{ padding:12px 16px;border-radius:12px;border:none;background:linear-gradient(135deg,var(--accent-start),var(--accent-end)); color:#022; font-weight:700; cursor:pointer; box-shadow:0 8px 24px rgba(91,140,255,0.12) }
    .btn:active{ transform:translateY(1px) }

    .alert{ padding:12px;border-radius:10px;margin:10px 0;background:rgba(0,0,0,0.25);border:1px solid var(--border) }
    .alert.error{ background: linear-gradient(180deg, rgba(255,40,40,0.06), rgba(255,40,40,0.03)); color:#ffd7d7 }
    .alert.success{ background: linear-gradient(180deg, rgba(24,180,110,0.06), rgba(24,180,110,0.03)); color:#dfffe8 }

    .result{ white-space:pre-wrap; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, monospace; font-size:13px; color:#cfe7ff }

    footer{ margin-top:18px; text-align:center; color:var(--muted); font-size:13px }

    /* Responsive tweaks */
    @media (max-width:820px){ .row{flex-direction:column} .top{gap:10px} .logo{width:48px;height:48px} }
  </style>
</head>
<body>
<main class="container">
  <div class="card">
    <div class="top">
      <div class="logo">DS</div>
      <div>
        <div style="font-size:18px;font-weight:700">DiscusScan — Мастер установки</div>
        <div style="font-size:13px;color:var(--muted)">v<?= htmlspecialchars($localVersion) ?></div>
      </div>
    </div>

    <hr style="margin:14px 0;border:none;border-top:1px solid rgba(255,255,255,0.03)">

    <div>
      <div style="font-weight:600;margin-bottom:8px">Шаг 1. Параметры базы данных</div>

      <?php if ($errors): ?>
        <div class="alert error">
          <?php foreach ($errors as $err): ?>
            <div><?= htmlspecialchars($err) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert success">Установка завершена успешно. Файл .env создан и миграции применены.</div>
        <div style="margin-top:8px">
          <?php if ($selfDeleted): ?>
            <div class="muted">Файл инсталлятора был автоматически удалён с сервера.</div>
          <?php else: ?>
            <div class="muted">Инсталлятор не смог удалить себя автоматически. Удалите installer.php вручную.</div>
          <?php endif; ?>
        </div>

        <div style="margin-top:12px" class="card">
          <div style="font-weight:600;margin-bottom:6px">Дальше</div>
          <ol style="padding-left:18px; color:var(--muted)">
            <li>Откройте <a href="auth.php" style="color:#9fd1ff">страницу входа</a> и войдите под созданным пользователем: <strong><?= htmlspecialchars($admin_user) ?></strong>.</li>
            <li>Проверьте настройки в панели и введите сервисные ключи.</li>
            <li>Если нужно — загрузите актуальные стили/файлы из репозитория.</li>
          </ol>
        </div>

      <?php else: ?>
        <form method="post" novalidate>
          <div class="row">
            <div class="col">
              <label>Хост БД</label>
              <input type="text" name="db_host" value="localhost">
            </div>
            <div class="col">
              <label>Имя БД</label>
              <input type="text" name="db_name" value="">
            </div>
          </div>

          <div class="row" style="margin-top:8px">
            <div class="col">
              <label>Пользователь БД</label>
              <input type="text" name="db_user" value="">
            </div>
            <div class="col">
              <label>Пароль БД</label>
              <input type="password" name="db_pass" value="">
            </div>
          </div>

          <div style="margin-top:8px">
            <label>Кодировка</label>
            <input type="text" name="db_charset" value="utf8mb4">
          </div>

          <hr style="margin:16px 0;border:none;border-top:1px solid rgba(255,255,255,0.03)">

          <div style="font-weight:600;margin-bottom:6px">Создать администратора</div>
          <div class="row" style="margin-top:8px">
            <div class="col">
              <label>Логин администратора</label>
              <input type="text" name="admin_user" value="admin">
            </div>
            <div class="col">
              <label>Пароль администратора</label>
              <input type="password" name="admin_pass" value="admin">
            </div>
          </div>

          <div style="margin-top:12px;display:flex;gap:12px;align-items:center">
            <label style="display:flex;gap:8px;align-items:center;color:var(--muted)"><input type="checkbox" name="force_overwrite" value="1"> Перезаписать .env, если существует</label>
            <button class="btn" type="submit">Создать .env и применить миграции</button>
          </div>
        </form>

        <?php if (!empty($output)): ?>
          <div style="margin-top:12px" class="card">
            <div style="font-weight:600;margin-bottom:6px">Вывод миграций</div>
            <div class="result"><?= htmlspecialchars(implode("\n", $output)) ?></div>
          </div>
        <?php endif; ?>

      <?php endif; ?>

      <div style="margin-top:12px" class="muted">После успешной установки рекомендуется удалить installer.php с сервера (если он не был удалён автоматически).</div>
    </div>
  </div>

  <footer>
    © <?= date('Y') ?> DiscusScan
  </footer>
</main>
</body>
</html>