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
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    if ($u !== '' && $p !== '') {
        $stmt = pdo()->prepare("SELECT id, pass_hash FROM users WHERE username=?");
        $stmt->execute([$u]);
        $row = $stmt->fetch();
        if ($row && password_verify($p, $row['pass_hash'])) {
            $_SESSION['uid'] = $row['id'];
            app_log('info', 'auth', 'Login success', ['username' => $u]);
            header('Location: index.php');
            exit;
        }
    }
    $err = 'Неверный логин или пароль';
    app_log('warning', 'auth', 'Login failed', ['username' => $u]);
}

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Вход — Мониторинг</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body class="auth">
  <div class="auth-card glass">
    <h1>Мониторинг</h1>
    <p class="muted">Закрытый вход. По умолчанию: <b>admin / admin</b></p>
    <?php if ($err): ?><div class="alert danger"><?=e($err)?></div><?php endif; ?>
    <form method="post" class="stack">
      <label>Логин<input type="text" name="username" required></label>
      <label>Пароль<input type="password" name="password" required></label>
      <button class="btn primary" type="submit">Войти</button>
    </form>
  </div>
</body>
</html>