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
            // Attempt to run migrations and create admin user
            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/migrate.php') . ' --create-user=' . escapeshellarg($admin_user . ':' . $admin_pass);
            exec($cmd . ' 2>&1', $output, $rv);

            if ($rv === 0) {
                $success = true;
                // Try to delete installer.php itself (works on Unix-like systems)
                try {
                    if (@unlink(__FILE__)) {
                        $selfDeleted = true;
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            } else {
                $errors[] = 'Миграции завершились с ошибкой: ' . implode("\n", $output);
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
    /* Self-contained installer styles */
    :root{--bg:#0f1724;--card:#0f1728;--muted:#9aa4b2;--accent:#5b8cff;--glass:rgba(255,255,255,0.03)}
    html,body{height:100%;margin:0;font-family:Inter,Segoe UI,Roboto,Arial,sans-serif;background:linear-gradient(180deg,#071025 0%,#07142a 100%);color:#e6eef8}
    .container{max-width:900px;margin:36px auto;padding:16px}
    .card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));border-radius:12px;padding:18px;box-shadow:0 6px 24px rgba(2,6,23,0.6)}
    .top{display:flex;align-items:center;gap:12px}
    .logo{width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,var(--accent),#7ea2ff);display:flex;align-items:center;justify-content:center;font-weight:700}
    label{display:block;font-size:13px;margin-bottom:6px;color:var(--muted)}
    input[type=text],input[type=password],input[name]{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.04);background:transparent;color:inherit}
    .row{display:flex;gap:12px}
    .col{flex:1}
    .muted{color:var(--muted);font-size:13px}
    .btn{padding:10px 14px;border-radius:8px;border:none;background:var(--accent);color:#012;text-transform:none;cursor:pointer}
    .alert{padding:10px;border-radius:8px;margin-bottom:12px}
    .alert.error{background:rgba(255,60,60,0.12);color:#ffb3b3}
    .alert.success{background:rgba(40,200,120,0.12);color:#bff7cf}
    .result{white-space:pre-wrap;font-family:monospace;font-size:13px;color:#cfe7ff}
    footer{margin-top:12px;text-align:center;color:var(--muted);font-size:12px}
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