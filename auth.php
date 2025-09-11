<?php
require_once __DIR__ . '/db.php';
session_start();

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: auth.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = get_client_ip();
    
    // Проверяем CSRF токен
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $err = 'Неверный токен безопасности';
        app_log('warning', 'auth', 'CSRF token mismatch', ['ip' => $clientIp]);
    }
    // Проверяем rate limiting
    elseif (!check_rate_limit('login', $clientIp, 5, 300)) {
        $err = 'Слишком много попыток входа. Попробуйте через 5 минут.';
        app_log('warning', 'auth', 'Rate limit exceeded', ['ip' => $clientIp]);
    }
    else {
        $u = trim($_POST['username'] ?? '');
        $p = trim($_POST['password'] ?? '');
        if ($u !== '' && $p !== '') {
            $stmt = pdo()->prepare("SELECT id, pass_hash FROM users WHERE username=?");
            $stmt->execute([$u]);
            $row = $stmt->fetch();
            if ($row && password_verify($p, $row['pass_hash'])) {
                $_SESSION['uid'] = $row['id'];
                app_log('info', 'auth', 'Login success', ['username' => $u, 'ip' => $clientIp]);
                header('Location: index.php');
                exit;
            }
        }
        $err = 'Неверный логин или пароль';
        app_log('warning', 'auth', 'Login failed', ['username' => $u, 'ip' => $clientIp]);
    }
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Вход — Мониторинг</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
</head>
<body class="auth">
  <div class="auth-card glass">
    <h1>Мониторинг</h1>
    <p class="muted">Закрытый вход. По умолчанию: <b>admin / admin</b></p>
    <?php if ($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label>Логин<input type="text" name="username" required></label>
      <label>Пароль<input type="password" name="password" required></label>
      <button class="btn primary" type="submit">Войти</button>
    </form>
  </div>
</body>
</html>