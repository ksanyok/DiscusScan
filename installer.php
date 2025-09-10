<?php
// installer.php — простой мастер установки (загружаемый на хостинг)
// Не подключаем db.php, чтобы не требовать наличия config.php заранее.

// Load version only for display
include_once __DIR__ . '/version.php';
$localVersion = defined('APP_VERSION') ? APP_VERSION : '0.0.0';

$errors = [];
$success = false;
$output = [];

// If config.php already exists, prevent accidental overwrite
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath) && empty($_POST['force_overwrite'])) {
    $errors[] = 'Файл config.php уже существует. Удалите или переименуйте его перед запуском мастера, либо отметьте "Перезаписать".';
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
        // Compose config content
        $conf = "<?php\n";
        $conf .= "// config.php — создано мастером установки\n";
        $conf .= "if (!defined('DB_HOST')) define('DB_HOST', " . var_export($db_host, true) . ");\n";
        $conf .= "if (!defined('DB_NAME')) define('DB_NAME', " . var_export($db_name, true) . ");\n";
        $conf .= "if (!defined('DB_USER')) define('DB_USER', " . var_export($db_user, true) . ");\n";
        $conf .= "if (!defined('DB_PASS')) define('DB_PASS', " . var_export($db_pass, true) . ");\n";
        $conf .= "if (!defined('DB_CHARSET')) define('DB_CHARSET', " . var_export($db_charset, true) . ");\n";
        $conf .= "\n// You may add more overrides here (LOG_DIR, APP_LOG, PHP_ERR_LOG)\n";

        // Try to write config.php
        $w = @file_put_contents($configPath, $conf, LOCK_EX);
        if ($w === false) {
            $errors[] = 'Не удалось записать файл config.php. Проверьте права на директорию.';
        } else {
            // Attempt to run migrations and create admin user
            $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/migrate.php') . ' --create-user=' . escapeshellarg($admin_user . ':' . $admin_pass);
            exec($cmd . ' 2>&1', $output, $rv);

            if ($rv === 0) {
                $success = true;
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
  <link rel="stylesheet" href="styles.css">
  <style>
    /* Minor tweaks to make installer look modern */
    .installer { max-width:820px; margin:28px auto; }
    .form-row { display:flex; gap:12px; }
    .form-row .col { flex:1; }
    .muted { color:#888; font-size:13px }
    .result { white-space:pre-wrap; font-family:monospace; font-size:13px }
  </style>
</head>
<body>
<?php // Simple header with version info ?>
<header class="topbar glass">
  <div class="brand">
    <div style="display:flex; align-items:center; gap:10px;">
      <div style="width:44px; height:44px; border-radius:10px; background:linear-gradient(135deg,#5b8cff,#7ea2ff); display:flex; align-items:center; justify-content:center; color:white; font-weight:700;">DS</div>
      <div>
        <div style="font-size:18px; font-weight:600;">DiscusScan — Мастер установки</div>
        <div style="font-size:12px; color: #bfc9d9;">v<?= htmlspecialchars($localVersion) ?></div>
      </div>
    </div>
  </div>
  <nav>
    <a href="index.php">Дашборд</a>
    <a href="update.php">Обновление</a>
  </nav>
</header>

<main class="container installer">
  <div class="card glass">
    <div class="card-title">Шаг 1. Параметры базы данных</div>

    <?php if ($errors): ?>
      <div class="alert error">
        <?php foreach ($errors as $err): ?>
          <div><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert success">Установка завершена успешно. Файл config.php создан и миграции применены.</div>
      <div class="card" style="margin-top:12px; padding:12px">
        <div class="card-title">Дальше</div>
        <ol>
          <li>Удалите installer.php с сервера (рекомендовано по безопасности).</li>
          <li>Откройте <a href="auth.php">страницу входа</a> и войдите под созданным пользователем: <strong><?= htmlspecialchars($admin_user) ?></strong>.</li>
          <li>Проверьте <a href="settings.php">настройки</a> и введите ключи сервисов, если нужно.</li>
        </ol>
      </div>
    <?php else: ?>
      <form method="post" novalidate>
        <div class="row" style="display:flex; gap:12px;">
          <div class="col">
            <label>Хост БД</label>
            <input name="db_host" value="localhost">
          </div>
          <div class="col">
            <label>Имя БД</label>
            <input name="db_name" value="">
          </div>
        </div>
        <div class="form-row" style="margin-top:8px">
          <div class="col">
            <label>Пользователь БД</label>
            <input name="db_user" value="">
          </div>
          <div class="col">
            <label>Пароль БД</label>
            <input name="db_pass" value="">
          </div>
        </div>
        <div style="margin-top:8px">
          <label>Кодировка</label>
          <input name="db_charset" value="utf8mb4">
        </div>

        <hr style="margin:16px 0">
        <div class="card-title">Создать администратора</div>
        <div class="form-row" style="margin-top:8px;">
          <div class="col">
            <label>Логин администратора</label>
            <input name="admin_user" value="admin">
          </div>
          <div class="col">
            <label>Пароль администратора</label>
            <input name="admin_pass" value="admin">
          </div>
        </div>

        <div style="margin-top:12px; display:flex; gap:12px; align-items:center;">
          <label style="display:flex; gap:8px; align-items:center;" class="muted"><input type="checkbox" name="force_overwrite" value="1"> Перезаписать config.php, если существует</label>
          <button class="btn primary">Создать config.php и применить миграции</button>
        </div>
      </form>

      <?php if (!empty($output)): ?>
        <div style="margin-top:12px" class="card">
          <div class="card-title">Вывод миграций</div>
          <div class="result"><?= htmlspecialchars(implode("\n", $output)) ?></div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <div style="margin-top:12px" class="muted">После успешной установки рекомендуется удалить installer.php с сервера.</div>
  </div>
</main>

<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>