<?php
// db.php — подключение к БД, авто-создание таблиц, настройки, логирование

// Legacy config.php (optional)
if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
}

// Load .env if present (KEY=VALUE per line). Values may be quoted.
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '' || $ln[0] === '#') continue;
        if (strpos($ln, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $ln, 2));
        // strip quotes
        if (strlen($v) >= 2 && ($v[0] === '"' && substr($v, -1) === '"' || $v[0] === "'" && substr($v, -1) === "'")) {
            $v = substr($v, 1, -1);
        }
        // define constants if not already defined
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

// --- БАЗОВЫЕ НАСТРОЙКИ БД (можно переопределить в config.php или .env) ---
if (!defined('DB_HOST')) define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', $_ENV['DB_NAME'] ?? 'discusscan');
if (!defined('DB_USER')) define('DB_USER', $_ENV['DB_USER'] ?? 'root');
if (!defined('DB_PASS')) define('DB_PASS', $_ENV['DB_PASS'] ?? '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// --- ЛОГИРОВАНИЕ ---
if (!defined('LOG_DIR')) define('LOG_DIR', __DIR__ . '/logs');
if (!defined('APP_LOG')) define('APP_LOG', LOG_DIR . '/app.log');
if (!defined('PHP_ERR_LOG')) define('PHP_ERR_LOG', LOG_DIR . '/php-errors.log');

// Создаём папку logs и настраиваем error_log
if (!is_dir(LOG_DIR)) { @mkdir(LOG_DIR, 0775, true); }
@touch(APP_LOG);
@touch(PHP_ERR_LOG);
ini_set('log_errors', '1');
ini_set('error_log', PHP_ERR_LOG);

// Простой JSON-логгер
function app_log(string $level, string $component, string $msg, array $ctx = []): void {
    $line = json_encode([
        't' => date('Y-m-d H:i:s.u'),
        'level' => strtoupper($level),
        'component' => $component,
        'msg' => $msg,
        'ctx' => $ctx,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli'
    ], JSON_UNESCAPED_UNICODE);
    file_put_contents(APP_LOG, $line . PHP_EOL, FILE_APPEND);
}

// --- ПОДКЛЮЧЕНИЕ К БД ---
function pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
    } catch (Throwable $e) {
        app_log('error', 'db', 'DB connection failed', ['error' => $e->getMessage()]);
        http_response_code(500);
        die('DB connection failed. Check db.php/settings or .env.');
    }
    install_schema($pdo);
    ensure_defaults($pdo);
    return $pdo;
}

// --- ВСПОМОГАТЕЛЬНЫЕ МИГРАЦИИ (idempotent) ---
function ensure_column_exists(PDO $pdo, string $table, string $column, string $ddl): void {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
        }
    } catch (Throwable $e) {
        try { $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN IF NOT EXISTS {$ddl}"); } catch (Throwable $e2) { /* ignore */ }
    }
}

function ensure_index_exists(PDO $pdo, string $table, string $index, string $ddl): void {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->execute([$table, $index]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec($ddl);
        }
    } catch (Throwable $e) {
        try { $pdo->exec(preg_replace('~CREATE\\s+INDEX~i', 'CREATE INDEX IF NOT EXISTS', $ddl)); } catch (Throwable $e2) { /* ignore */ }
    }
}

// --- СХЕМА ---
function install_schema(PDO $pdo): void {
    // users
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) UNIQUE NOT NULL,
            pass_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // settings (key-value)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            skey VARCHAR(190) PRIMARY KEY,
            svalue TEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // sources (домены/источники)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            host VARCHAR(255) NOT NULL,
            url TEXT,
            is_active TINYINT(1) DEFAULT 1,
            note VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_host (host)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // links (ссылки сгруппированы по source_id)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS links (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            source_id INT NOT NULL,
            url TEXT NOT NULL,
            title TEXT,
            first_found TIMESTAMP NULL,
            last_seen TIMESTAMP NULL,
            times_seen INT DEFAULT 0,
            status VARCHAR(30) DEFAULT 'new',
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_url (url(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    // scans (запуски сканера)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scans (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            started_at TIMESTAMP NULL,
            finished_at TIMESTAMP NULL,
            status VARCHAR(30) DEFAULT 'started',
            model VARCHAR(100),
            prompt TEXT,
            found_links INT DEFAULT 0,
            new_links INT DEFAULT 0,
            error TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // --- МИГРАЦИИ ---
    // links.published_at DATETIME NULL
    ensure_column_exists($pdo, 'links', 'published_at', "`published_at` DATETIME NULL AFTER `last_seen`");
    // indexes for links
    ensure_index_exists($pdo, 'links', 'idx_links_published_at', "CREATE INDEX idx_links_published_at ON links (published_at)");
    ensure_index_exists($pdo, 'links', 'idx_links_domain_published_at', "CREATE INDEX idx_links_domain_published_at ON links ((substring_index(substring_index(url,'//' , -1),'/',1)), published_at)");
    ensure_index_exists($pdo, 'links', 'idx_links_status', "CREATE INDEX idx_links_status ON links (status)");
    ensure_index_exists($pdo, 'links', 'idx_links_last_seen', "CREATE INDEX idx_links_last_seen ON links (last_seen)");
    // Note: domain is derived from url; if you have a dedicated 'domain' column, adjust accordingly.

    // sources.is_enabled, sources.is_paused
    ensure_column_exists($pdo, 'sources', 'is_enabled', "`is_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_active`");
    ensure_column_exists($pdo, 'sources', 'is_paused',  "`is_paused`  TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_enabled`");

    // NEW: sources.platform + sources.discovered_via
    ensure_column_exists($pdo, 'sources', 'platform', "`platform` ENUM('discourse','phpbb','vbulletin','ips','vanilla','flarum','wp-forum','github','stackexchange','unknown') NULL AFTER `url`");
    ensure_column_exists($pdo, 'sources', 'discovered_via', "`discovered_via` VARCHAR(64) NULL AFTER `platform`");

    // NEW: discovered_sources table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS discovered_sources (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain VARCHAR(255) NOT NULL,
            proof_url TEXT NOT NULL,
            platform_guess VARCHAR(32) NOT NULL,
            reason TEXT NOT NULL,
            activity_hint VARCHAR(255) NOT NULL,
            score TINYINT DEFAULT 0,
            status ENUM('new','verified','rejected','failed') DEFAULT 'new',
            first_seen_at DATETIME NOT NULL,
            last_checked_at DATETIME NULL,
            UNIQUE KEY uniq_discovered_domain (domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// --- НАСТРОЙКИ ---
function get_setting(string $key, $default = null) {
    $stmt = pdo()->prepare("SELECT svalue FROM settings WHERE skey = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetchColumn();
    if ($row === false) return $default;
    $decoded = json_decode($row, true);
    return $decoded === null && $row !== 'null' ? $row : $decoded;
}

function set_setting(string $key, $value): void {
    $val = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    $stmt = pdo()->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    $stmt->execute([$key, $val]);
}

function ensure_defaults(PDO $pdo): void {
    // дефолтный админ
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($cnt === 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, pass_hash) VALUES (?,?)");
        $stmt->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
        app_log('info', 'auth', 'Default admin user created', ['username' => 'admin', 'password' => 'admin']);
    }
    // дефолтные настройки — без get_setting()/set_setting() чтобы избежать рекурсии
    $defaults = [
        'openai_api_key' => '',
        'openai_model' => 'gpt-5-mini',
        'openai_timeout_sec' => 300,
        'openai_max_output_tokens' => 4096,
        'scan_period_min' => 15,
        'search_prompt' => 'Искать упоминания моих плагинов и бренда BuyReadySite на русскоязычных форумах и сайтах за последние 30 дней. Возвращать только уникальные треды/темы.',
        'preferred_sources_enabled' => false,
        'telegram_token' => '',
        'telegram_chat_id' => '',
        'cron_secret' => bin2hex(random_bytes(12)),
        'last_scan_at' => null,
        'freshness_days' => 7,
        'enabled_sources_only' => true,
        'max_results_per_scan' => 80,
        'return_schema_required' => true,
        'languages' => [],
        'regions' => [],
        'scope_domains_enabled' => false,
        'scope_telegram_enabled' => false,
        'scope_forums_enabled' => true,
        'telegram_mode' => 'any',
        // NEW DISCOVERY/HTTP defaults
        'openai_enable_web_search' => true,
        'discovery_daily_candidates' => 20,
        'discovery_enabled' => true,
        'verify_freshness_days_for_new_domain' => 90,
        'http_timeout_sec' => 20,
        'max_parallel_http' => 12,
        // NEW: OpenAI parallel/tool settings
        'openai_max_tool_calls' => 8,
        'max_parallel_openai' => 6,
        // NEW: LLM web search batch size per scan
        'llm_search_domains_per_scan' => 30,
    ];
    $sel = $pdo->prepare("SELECT svalue FROM settings WHERE skey = ?");
    $ins = $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
    foreach ($defaults as $k => $v) {
        $sel->execute([$k]);
        $exists = $sel->fetchColumn();
        if ($exists === false) {
            $val = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            $ins->execute([$k, $val]);
        }
    }
}

// --- СЕССИИ/ОХРАНА ---
function require_login(): void {
    session_start();
    if (empty($_SESSION['uid'])) {
        header('Location: auth.php');
        exit;
    }
}

function current_user(): ?array {
    session_start();
    if (!empty($_SESSION['uid'])) {
        $stmt = pdo()->prepare("SELECT id, username, created_at FROM users WHERE id=?");
        $stmt->execute([$_SESSION['uid']]);
        return $stmt->fetch() ?: null;
    }
    return null;
}

// CSRF Protection
function generate_csrf_token(): string {
    if (!session_id()) session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token): bool {
    if (!session_id()) session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(generate_csrf_token()) . '">';
}

// Rate Limiting для защиты от атак
function check_rate_limit(string $action, string $identifier, int $maxAttempts = 5, int $windowSeconds = 300): bool {
    $key = md5($action . '|' . $identifier);
    $cacheFile = LOG_DIR . '/rate_limit_' . $key . '.json';
    
    $now = time();
    $data = ['attempts' => [], 'blocked_until' => 0];
    
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $data = array_merge($data, $cached);
        }
    }
    
    // Проверяем блокировку
    if ($data['blocked_until'] > $now) {
        return false; // заблокирован
    }
    
    // Очищаем старые попытки
    $data['attempts'] = array_filter($data['attempts'], fn($t) => $t > $now - $windowSeconds);
    
    // Добавляем текущую попытку
    $data['attempts'][] = $now;
    
    // Проверяем лимит
    if (count($data['attempts']) > $maxAttempts) {
        $data['blocked_until'] = $now + $windowSeconds;
        app_log('warning', 'rate_limit', 'Rate limit exceeded', [
            'action' => $action, 
            'identifier' => $identifier,
            'attempts' => count($data['attempts'])
        ]);
    }
    
    // Сохраняем данные
    file_put_contents($cacheFile, json_encode($data));
    
    return $data['blocked_until'] <= $now;
}

function get_client_ip(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', $_SERVER[$header]);
            return trim($ips[0]);
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// --- ПОЛЕЗНЯК ---
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function host_from_url(string $url): string {
    $h = parse_url($url, PHP_URL_HOST) ?: '';
    return strtolower(preg_replace('~^www\.~i', '', $h));
}

// --- DISCOVERY HELPERS ---
function db_upsert_discovered(array $row): void {
    // $row: domain, proof_url, platform_guess, reason, activity_hint, score?, status?
    $domain = strtolower(trim($row['domain'] ?? ''));
    if ($domain === '') return;
    $proof = (string)($row['proof_url'] ?? '');
    $plat  = (string)($row['platform_guess'] ?? 'unknown');
    $reason= (string)($row['reason'] ?? '');
    $hint  = (string)($row['activity_hint'] ?? '');
    $score = (int)($row['score'] ?? 0);
    $status= (string)($row['status'] ?? 'new');
    $sql = "INSERT INTO discovered_sources (domain, proof_url, platform_guess, reason, activity_hint, score, status, first_seen_at, last_checked_at)
            VALUES (?,?,?,?,?,?,?,NOW(),NULL)
            ON DUPLICATE KEY UPDATE 
                proof_url=VALUES(proof_url), platform_guess=VALUES(platform_guess), reason=VALUES(reason), activity_hint=VALUES(activity_hint),
                last_checked_at=VALUES(last_checked_at)";
    $st = pdo()->prepare($sql);
    $st->execute([$domain, $proof, $plat, $reason, $hint, $score, $status]);
}

function db_mark_discovered_status(string $domain, string $status, int $score = 0): void {
    $domain = strtolower(trim($domain));
    if ($domain === '') return;
    $st = pdo()->prepare("UPDATE discovered_sources SET status=?, score=?, last_checked_at=NOW() WHERE domain=?");
    $st->execute([$status, $score, $domain]);
}

function db_sources_existing_domains(): array {
    $domains = [];
    try {
        $rows = pdo()->query("SELECT host FROM sources")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $h) { $h = strtolower(preg_replace('~^www\\.~i', '', trim($h))); if ($h) $domains[$h] = true; }
    } catch (Throwable $e) {}
    try {
        $rows2 = pdo()->query("SELECT domain FROM discovered_sources")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows2 as $h) { $h = strtolower(preg_replace('~^www\\.~i', '', trim($h))); if ($h) $domains[$h] = true; }
    } catch (Throwable $e) {}
    return array_keys($domains);
}