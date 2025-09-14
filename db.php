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
if (!defined('DB_HOST')) define('DB_HOST', 'topbit.mysql.tools');
if (!defined('DB_NAME')) define('DB_NAME', 'topbit_monitor');
if (!defined('DB_USER')) define('DB_USER', 'topbit_monitor');
if (!defined('DB_PASS')) define('DB_PASS', '(766hxMXd~');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

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
    // Support optional port (избегаем предупреждений Intelephense)
    $port = null;
    if (defined('DB_PORT')) { $port = constant('DB_PORT'); }
    $dsn = 'mysql:host=' . DB_HOST . ($port ? ';port=' . $port : '') . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $opt = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
    } catch (Throwable $e) {
        app_log('error', 'db', 'DB connection failed', [
            'error' => $e->getMessage(),
            'dsn_host' => DB_HOST,
            'dsn_db' => DB_NAME,
            'port' => $port
        ]);
        if (defined('INSTALLER_MODE')) {
            // Let installer catch and show the real message
            throw $e;
        }
        http_response_code(500);
        die('DB connection failed. Check db.php/settings or .env.');
    }
    install_schema($pdo);
    ensure_defaults($pdo);
    return $pdo;
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
    
    // domains (семплированные домены для оркестрации)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS domains (
            id INT AUTO_INCREMENT PRIMARY KEY,
            domain VARCHAR(255) NOT NULL,
            lang_hint VARCHAR(10),
            region VARCHAR(10),
            score FLOAT DEFAULT 0,
            is_paused TINYINT(1) DEFAULT 0,
            last_scan_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_domain (domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // topics (найденные темы/треды)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS topics (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            domain_id INT,
            title TEXT,
            url TEXT NOT NULL,
            published_at TIMESTAMP NULL,
            author VARCHAR(255),
            snippet TEXT,
            score FLOAT DEFAULT 0,
            seen_hash VARCHAR(64),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
            UNIQUE KEY uniq_seen_hash (seen_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    // runs (запуски оркестрированного поиска)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS runs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            finished_at TIMESTAMP NULL,
            found_count INT DEFAULT 0,
            window_from TIMESTAMP NULL,
            window_to TIMESTAMP NULL,
            status VARCHAR(30) DEFAULT 'started'
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
    // дефолтные настройки
    $defaults = [
        'openai_api_key' => '',
        'openai_model' => 'gpt-5-mini',
        'scan_period_min' => 15,
        'search_prompt' => 'Искать упоминания моих плагинов и бренда BuyReadySite на русскоязычных форумах и сайтах за последние 30 дней. Возвращать только уникальные треды/темы.',
        'preferred_sources_enabled' => false,
        'telegram_token' => '',
        'telegram_chat_id' => '',
        'cron_secret' => bin2hex(random_bytes(12)),
        'last_scan_at' => null,
        
        // Настройки оркестрации
        'orchestration_topic' => '',
        'orchestration_sources' => json_encode(['forums']),
        'orchestration_languages' => json_encode(['ru', 'uk', 'en']),
        'orchestration_regions' => json_encode(['UA', 'PL']),
        'orchestration_freshness_window_hours' => 72,
        'orchestration_per_domain_limit' => 5,
        'orchestration_total_limit' => 50,
        'orchestration_paused_domains' => json_encode([]),
        'orchestration_include_domains' => json_encode([]),
        'orchestration_exclude_domains' => json_encode([]),
        'orchestration_enabled' => false,
        'orchestration_last_run' => null
    ];
    foreach ($defaults as $k => $v) {
        if (get_setting($k, '__missing__') === '__missing__') {
            set_setting($k, $v);
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

// --- ПОЛЕЗНЯК ---
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function host_from_url(string $url): string {
    $h = parse_url($url, PHP_URL_HOST) ?: '';
    return strtolower(preg_replace('~^www\.~i', '', $h));
}

// --- ПУБЛИЧНЫЕ ФУНКЦИИ ОРКЕСТРАЦИИ ---

/**
 * Запуск семплинга доменов
 */
function run_seed_domains(array $settings): void {
    $result = [];
    $topic = $settings['topic'] ?? '';
    if (empty($topic)) {
        throw new Exception('Topic is required for domain seeding');
    }
    
    // Сохраняем настройки перед запуском
    set_setting('orchestration_topic', $topic);
    set_setting('orchestration_sources', json_encode($settings['sources'] ?? ['forums']));
    set_setting('orchestration_languages', json_encode($settings['languages'] ?? ['ru']));
    set_setting('orchestration_regions', json_encode($settings['regions'] ?? ['UA']));
    
    // Вызываем функцию семплинга через HTTP (для избежания дублирования кода)
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') 
              . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . dirname($_SERVER['SCRIPT_NAME']);
    $secret = get_setting('cron_secret', '');
    $url = $baseUrl . '/monitoring_cron.php?action=seed_domains&secret=' . urlencode($secret);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Domain seeding failed with HTTP code: $httpCode");
    }
    
    app_log('info', 'orchestration', 'Domain seeding triggered via API', $settings);
}

/**
 * Запуск периодического сканирования
 */
function run_scan(array $settings): array {
    $topic = $settings['topic'] ?? get_setting('orchestration_topic', '');
    if (empty($topic)) {
        throw new Exception('Topic is required for scanning');
    }
    
    // Обновляем настройки если переданы
    if (isset($settings['freshness_window_hours'])) {
        set_setting('orchestration_freshness_window_hours', (int)$settings['freshness_window_hours']);
    }
    if (isset($settings['per_domain_limit'])) {
        set_setting('orchestration_per_domain_limit', (int)$settings['per_domain_limit']);
    }
    if (isset($settings['total_limit'])) {
        set_setting('orchestration_total_limit', (int)$settings['total_limit']);
    }
    
    // Запускаем сканирование
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') 
              . ($_SERVER['HTTP_HOST'] ?? 'localhost')
              . dirname($_SERVER['SCRIPT_NAME']);
    $secret = get_setting('cron_secret', '');
    $url = $baseUrl . '/monitoring_cron.php?action=scan&secret=' . urlencode($secret);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Scan failed with HTTP code: $httpCode");
    }
    
    $result = json_decode($response, true) ?: ['ok' => false, 'error' => 'Invalid response'];
    
    app_log('info', 'orchestration', 'Scan triggered via API', array_merge($settings, $result));
    
    return $result;
}

/**
 * Управление паузой домена
 */
function toggle_domain_pause(string $domain, bool $isPaused): void {
    $stmt = pdo()->prepare("UPDATE domains SET is_paused = ? WHERE domain = ?");
    $stmt->execute([$isPaused ? 1 : 0, $domain]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception("Domain not found: $domain");
    }
    
    app_log('info', 'orchestration', 'Domain pause toggled', [
        'domain' => $domain, 
        'is_paused' => $isPaused
    ]);
}

/**
 * Получение результатов, сгруппированных по доменам
 */
function get_results_grouped_by_domain(array $params = []): array {
    $limit = max(1, min(1000, (int)($params['limit'] ?? 100)));
    $offset = max(0, (int)($params['offset'] ?? 0));
    $source = $params['source'] ?? 'all'; // all, forums, telegram
    $language = $params['language'] ?? 'all';
    $region = $params['region'] ?? 'all';
    $minScore = (float)($params['min_score'] ?? 0);
    $daysBack = max(1, (int)($params['days_back'] ?? 30));
    
    $whereConditions = ["t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
    $whereParams = [$daysBack];
    
    if ($minScore > 0) {
        $whereConditions[] = "t.score >= ?";
        $whereParams[] = $minScore;
    }
    
    if ($language !== 'all') {
        $whereConditions[] = "d.lang_hint = ?";
        $whereParams[] = $language;
    }
    
    if ($region !== 'all') {
        $whereConditions[] = "d.region = ?";
        $whereParams[] = strtoupper($region);
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    $sql = "
        SELECT 
            d.domain,
            d.lang_hint,
            d.region,
            d.is_paused,
            COUNT(t.id) as topics_count,
            AVG(t.score) as avg_score,
            MAX(t.created_at) as latest_topic_at,
            JSON_ARRAYAGG(
                JSON_OBJECT(
                    'title', t.title,
                    'url', t.url,
                    'published_at', t.published_at,
                    'author', t.author,
                    'snippet', LEFT(t.snippet, 200),
                    'score', t.score,
                    'created_at', t.created_at
                )
            ) as topics
        FROM domains d
        INNER JOIN topics t ON t.domain_id = d.id
        $whereClause
        GROUP BY d.id, d.domain, d.lang_hint, d.region, d.is_paused
        ORDER BY topics_count DESC, avg_score DESC
        LIMIT ? OFFSET ?
    ";
    
    $whereParams[] = $limit;
    $whereParams[] = $offset;
    
    $stmt = pdo()->prepare($sql);
    $stmt->execute($whereParams);
    $results = $stmt->fetchAll();
    
    // Декодируем JSON topics
    foreach ($results as &$result) {
        $topics = json_decode($result['topics'], true);
        if (is_array($topics)) {
            // Убираем null записи и сортируем по score
            $topics = array_filter($topics, fn($t) => $t !== null);
            usort($topics, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
            $result['topics'] = $topics;
        } else {
            $result['topics'] = [];
        }
    }
    
    return $results;
}

/**
 * Отправка уведомлений о новых находках
 */
function notify_new(array $findings): void {
    if (empty($findings)) return;
    
    $tgToken = (string)get_setting('telegram_token', '');
    $tgChat = (string)get_setting('telegram_chat_id', '');
    
    if (empty($tgToken) || empty($tgChat)) {
        app_log('warning', 'orchestration', 'Telegram notification skipped - no token/chat configured');
        return;
    }
    
    $totalNew = count($findings);
    $domainsCount = count(array_unique(array_column($findings, 'domain')));

    // Новое форматирование Telegram уведомления (HTML + inline кнопки)
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
             . ($_SERVER['HTTP_HOST'] ?? 'localhost')
             . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $panelUrl = $baseUrl . '/index.php';

    $escape = function(string $s): string { return htmlspecialchars(mb_substr($s,0,160), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

    $message  = "🚀 <b>Мониторинг: найдено {$totalNew} новых упоминаний</b>\n";
    $message .= "🌐 Домены: <b>{$domainsCount}</b>\n";

    if ($totalNew) {
        $sample = array_slice($findings, 0, 3);
        $message .= "\n🔥 <b>Примеры:</b>\n";
        foreach ($sample as $f) {
            $u = $f['url'] ?? ''; $t = $f['title'] ?? ($f['domain'] ?? $u); $d = $f['domain'] ?? '';
            $shortT = $escape($t);
            $shortD = $escape($d);
            // Обрезаем слишком длинные URL (для отображения домена достаточно)
            $message .= "• <a href=\"" . htmlspecialchars($u, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">{$shortT}</a> <code>{$shortD}</code>\n";
        }
        if ($totalNew > 3) {
            $rest = $totalNew - 3;
            $message .= "… и ещё {$rest} на панели\n";
        }
    } else {
        $message .= "\nНовых ссылок нет.\n";
    }

    $message .= "\n⏰ " . date('Y-m-d H:i');

    $replyMarkup = json_encode([
        'inline_keyboard' => [
            [ ['text' => '📊 Открыть панель', 'url' => $panelUrl] ],
        ]
    ], JSON_UNESCAPED_UNICODE);

    $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
    $ch = curl_init($tgUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $tgChat,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 1,
            'reply_markup' => $replyMarkup
        ],
        CURLOPT_TIMEOUT => 15
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    app_log('info', 'orchestration', 'Notification sent', [
        'findings_count' => $totalNew,
        'domains_count' => $domainsCount,
        'telegram_status' => $httpCode
    ]);
}

/**
 * Умный мастер - анализ пользовательского ввода и генерация промпта
 */
function processSmartWizard(string $userInput, string $apiKey, string $model, string $step = 'clarify'): array {
    $userInput = mb_substr(trim($userInput), 0, 4000);
    if ($userInput === '') return ['ok'=>false,'error'=>'Описание пустое'];

    $isClarify = ($step === 'clarify');

    // Определяем минимальные JSON формы (нужны только для инструкций модели)
    $clarifyShape  = '{"questions":[],"auto_detected":{"languages":[],"regions":[]},"recommendations":[]}';
    $generateShape = '{"prompt":"...","languages":[],"regions":[],"sources":[]}';

    // System промпты максимально короткие для уменьшения обрезки
    if ($isClarify) {
        $system = 'Верни строго один JSON ' . $clarifyShape . ' без текста вокруг. Вопросы только если реально не хватает данных. Если достаточно — questions=[]';
        $user   = 'Описание задачи:\n' . $userInput;
    } else {
        $system = 'Верни строго один JSON ' . $generateShape . ' без текста вокруг. prompt: финальная формулировка мониторинга (цель, сущности, исключения, аспекты). НЕ добавляй перечисление источников внутрь текста prompt.';
        $user   = $userInput;
    }

    $chatUrl = 'https://api.openai.com/v1/chat/completions';
    $headers = [ 'Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Expect:' ];

    $maxTokens1 = $isClarify ? 350 : 700;
    $maxTokens2 = $isClarify ? 500 : 1000; // для повторной попытки если truncate

    $makePayload = function(int $maxTokens) use ($model,$system,$user) {
        return [
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>$system],
                ['role'=>'user','content'=>$user]
            ],
            'temperature' => 0.1,
            'max_tokens' => $maxTokens
        ];
    };

    $request = function(array $payload) use ($chatUrl,$headers) {
        $ch = curl_init($chatUrl);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>55,
            CURLOPT_CONNECTTIMEOUT=>10
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status,$resp,$err];
    };

    $extractJSON = function(string $raw): ?array {
        $raw = trim($raw);
        if ($raw === '') return null;
        // Удаляем обрамляющие кодовые блоки
        if (preg_match('~```(json)?\s*(.+?)```~is', $raw, $m)) {
            $raw = trim($m[2]);
        }
        // Если сразу чистый JSON
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
        // Ищем первый сбалансированный объект
        if (preg_match_all('{\{(?:[^{}]|(?R))*\}}u', $raw, $all)) {
            foreach ($all[0] as $frag) {
                $j = json_decode($frag, true);
                if (is_array($j) && (isset($j['questions']) || isset($j['prompt']))) return $j;
            }
        }
        return null;
    };

    [$st1,$body1,$err1] = $request($makePayload($maxTokens1));
    app_log('info','smart_wizard','chat_try1',[ 'step'=>$step,'status'=>$st1,'len'=>strlen((string)$body1) ]);

    $content1 = '';
    $finish1 = '';
    $parsed1 = null;
    if ($st1 === 200 && $body1) {
        $j = json_decode($body1,true);
        $content1 = trim($j['choices'][0]['message']['content'] ?? '');
        $finish1  = $j['choices'][0]['finish_reason'] ?? '';
        $parsed1  = $extractJSON($content1 ?: $body1);
    }

    $needRetry = ($st1 !== 200) || !$parsed1 || ($finish1 === 'length');

    if ($needRetry) {
        [$st2,$body2,$err2] = $request($makePayload($maxTokens2));
        app_log('warning','smart_wizard','chat_retry',[ 'step'=>$step,'status'=>$st2,'len'=>strlen((string)$body2) ]);
        if ($st2 === 200 && $body2) {
            $j2 = json_decode($body2,true);
            $content2 = trim($j2['choices'][0]['message']['content'] ?? '');
            $parsed2 = $extractJSON($content2 ?: $body2);
            if ($parsed2) { $parsed1 = $parsed2; $finish1 = $j2['choices'][0]['finish_reason'] ?? ''; }
        }
    }

    if (!$parsed1) {
        app_log('error','smart_wizard','wizard_fail',[ 'step'=>$step,'status'=>$st1,'err'=>$err1, 'preview'=>mb_substr((string)$body1,0,300) ]);
        return ['ok'=>false,'error'=>'Не удалось получить ответ модели. Повторите попытку.'];
    }

    if ($isClarify) {
        $questions = $parsed1['questions'] ?? [];
        if (!is_array($questions)) $questions = [];
        // Санитизация вопросов (оставляем только текстовые вопросы)
        $clean = [];
        foreach ($questions as $q) {
            if (!is_array($q)) continue;
            $text = trim($q['question'] ?? ($q['q'] ?? ''));
            if ($text === '') continue;
            $clean[] = [ 'question'=>$text, 'type'=>'text', 'options'=>[] ];
        }
        $auto = $parsed1['auto_detected'] ?? ['languages'=>[], 'regions'=>[]];
        if (!is_array($auto)) $auto = ['languages'=>[], 'regions'=>[]];
        $recs = $parsed1['recommendations'] ?? [];
        if (!is_array($recs)) $recs = [];
        return [ 'ok'=>true, 'step'=>'clarify', 'questions'=>$clean, 'auto_detected'=>[
            'languages'=>array_values(array_unique(array_filter(array_map('strtolower', (array)$auto['languages'])))),
            'regions'=>array_values(array_unique(array_filter(array_map('strtoupper', (array)$auto['regions']))))
        ], 'recommendations'=>$recs ];
    }

    // generate
    $promptText = trim($parsed1['prompt'] ?? '');
    if ($promptText === '') return ['ok'=>false,'error'=>'ИИ вернул пустой prompt'];

    $langs = [];
    foreach ((array)($parsed1['languages'] ?? []) as $l) {
        $l = strtolower(trim($l));
        if (preg_match('~^[a-z]{2}$~',$l)) $langs[] = $l;
    }
    $langs = array_values(array_unique($langs));

    $regs = [];
    foreach ((array)($parsed1['regions'] ?? []) as $r) {
        $r = strtoupper(trim($r));
        if (preg_match('~^[A-Z]{2}$~',$r)) $regs[] = $r;
    }
    $regs = array_values(array_unique($regs));

    return [
        'ok'=>true,
        'step'=>'generate',
        'prompt'=>$promptText,
        'languages'=>$langs,
        'regions'=>$regs,
        'sources'=> (array)($parsed1['sources'] ?? [])
    ];
}