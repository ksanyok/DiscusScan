<?php
require_once __DIR__ . '/db.php';
require_login();

// Check if user is admin or has update permission
// For simplicity, assume logged in user can update

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    // Perform git pull
    $output = [];
    $return_var = 0;
    exec('git pull origin main 2>&1', $output, $return_var);

    if ($return_var === 0) {
        // Apply migrations inline by initializing PDO (install_schema + ensure_defaults)
        try {
            include_once __DIR__ . '/db.php';
            $pdo = pdo(); // will create tables and ensure defaults
            $message = 'Обновление успешно выполнено. Миграции применены.';
            $success = true;
        } catch (Throwable $e) {
            $message = 'Обновление выполнено, но применение миграций завершилось с ошибкой: ' . $e->getMessage();
        }
    } else {
        $message = 'Ошибка при обновлении: ' . implode("\n", $output);
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