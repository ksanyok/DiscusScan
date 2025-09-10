<?php
// migrate.php — run database schema install and optional post-install tasks
chdir(__DIR__);
require_once __DIR__ . '/db.php';

// Run install by obtaining PDO (which triggers install_schema and ensure_defaults)
try {
    $pdo = pdo();
    echo "Schema installation completed.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Optional: create admin user if CLI args provided: --create-user=username:password
foreach ($argv as $arg) {
    if (strpos($arg, '--create-user=') === 0) {
        $pair = substr($arg, strlen('--create-user='));
        [$user, $pass] = array_pad(explode(':', $pair, 2), 2, 'admin');
        $user = trim($user) ?: 'admin';
        $pass = trim($pass) ?: 'admin';
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$user]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmt = $pdo->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)');
            $stmt->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
            echo "User '{$user}' created.\n";
        } else {
            echo "User '{$user}' already exists, skipping.\n";
        }
    }
}

exit(0);
