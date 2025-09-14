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
    $userInput = mb_substr($userInput, 0, 4000);
    $requestUrl = 'https://api.openai.com/v1/responses'; // Переход на responses API (используется как fallback)
    $requestHeaders = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Expect:'
    ];
    $result = null; // init to avoid undefined variable and allow later fallbacks to populate

    // --- Определяем схему и подсказки ---
    if ($step === 'clarify') {
        $schema = [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'options' => ['type' => 'array','items' => ['type' => 'string']],
                            'type' => ['type' => 'string','enum' => ['single','multiple','text']]
                        ],
                        'required' => ['question','type'],
                        'additionalProperties' => false
                    ]
                ],
                'auto_detected' => [
                    'type' => 'object',
                    'properties' => [
                        'languages' => ['type' => 'array','items' => ['type' => 'string']],
                        'regions' => ['type' => 'array','items' => ['type' => 'string']]
                    ],
                    'required' => ['languages','regions'],
                    'additionalProperties' => false
                ],
                'recommendations' => ['type' => 'array','items' => ['type' => 'string']]
            ],
            'required' => ['questions','auto_detected'],
            'additionalProperties' => false
        ];
        $systemPrompt = "Ты помощник по настройке мониторинга. Верни СТРОГО JSON по схеме. Не добавляй текст вне JSON. НЕ спрашивай про источники (forums, telegram, social, news, reviews). Вопросы только по отсутствующим аспектам (языки, регионы, период, цель, сущности, исключения, аспекты анализа). Если всё есть — questions=[]";
        $userPrompt = "Описание пользователя:\n\n" . $userInput . "\n\nПодготовь структуру clarifying вопросов и автоопределение языков/регионов.";
    } else {
        $schema = [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string'],
                'languages' => ['type' => 'array','items' => ['type' => 'string']],
                'regions' => ['type' => 'array','items' => ['type' => 'string']],
                'sources' => ['type' => 'array','items' => ['type' => 'string']],
                'reasoning' => ['type' => 'string']
            ],
            // reasoning больше НЕ в required чтобы не провоцировать обрезку
            'required' => ['prompt','languages','regions','sources'],
            'additionalProperties' => false
        ];
        $systemPrompt = "Сформируй финальный JSON. prompt: цель мониторинга, ключевые термины/синонимы, аспекты (отзывы, баги, сравнения и т.п.), временной фокус (если был), исключения. НЕ перечисляй источники внутри текста prompt. languages: ISO 639-1. regions: ISO 3166-1 alpha-2. sources: массив (если извлечены). Без текста вне JSON. reasoning (необязательно): краткое объяснение.";
        $userPrompt = $userInput;
    }

    // === ПРИОРИТЕТ: chat/completions (устраняет проблему empty reasoning) ===
    $chatUrl = 'https://api.openai.com/v1/chat/completions';
    $chatHeaders = [ 'Content-Type: application/json','Authorization: Bearer '.$apiKey,'Expect:' ];
    $jsonShape = $step==='clarify'
        ? '{"questions":[],"auto_detected":{"languages":[],"regions":[]},"recommendations":[]}'
        : '{"prompt":"...","languages":[],"regions":[],"sources":[]}';
    $chatSystem = $step==='clarify'
        ? 'Ты помощник. Верни СТРОГО один JSON без текста вне него по форме ' . $jsonShape . '. Вопросы только если реально нужны.'
        : 'Верни СТРОГО один JSON по форме ' . $jsonShape . ' без пояснений вне JSON. Не перечисляй источники внутри prompt.';
    $chatMessages = [
        ['role'=>'system','content'=>$chatSystem],
        ['role'=>'user','content'=>$userInput]
    ];
    $chatPayload = [
        'model'=>$model,
        // Используем max_tokens (а не max_completion_tokens) — пустые ответы ранее возникали из-за неподдерживаемого параметра
        'max_tokens'=>$step==='clarify'?400:900,
        'messages'=>$chatMessages,
        'temperature'=>0.1,
        'response_format'=>['type'=>'json_object']
    ];
    $chC = curl_init($chatUrl);
    curl_setopt_array($chC,[
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>$chatHeaders,
        CURLOPT_POSTFIELDS=>json_encode($chatPayload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=> ($step==='generate'?70:40),
        CURLOPT_CONNECTTIMEOUT=>10
    ]);
    $chatResp = curl_exec($chC);
    $chatStatus = (int)curl_getinfo($chC, CURLINFO_HTTP_CODE);
    $chatErr = curl_error($chC);
    curl_close($chC);
    if ($chatStatus===200 && $chatResp) {
        $chatData = json_decode($chatResp, true) ?: [];
        $chatContent = trim($chatData['choices'][0]['message']['content'] ?? '');
        if ($chatContent==='') {
            // regex попытка вытащить JSON
            if (preg_match('{\{(?:[^{}]|(?R))*\}}u',$chatResp,$mJSON)) $chatContent=$mJSON[0];
        }
        if ($chatContent!=='') {
            if (preg_match('~```(json)?\s*(.+?)```~is',$chatContent,$mBlock)) $chatContent=trim($mBlock[2]);
            $parsed = json_decode($chatContent,true);
            if (is_array($parsed)) {
                app_log('info','smart_wizard','chat_primary_success',[ 'step'=>$step,'len'=>strlen($chatContent) ]);
                if ($step==='clarify') {
                    $questions = $parsed['questions'] ?? [];
                    $auto = $parsed['auto_detected'] ?? ['languages'=>[], 'regions'=>[]];
                    $recs = $parsed['recommendations'] ?? [];
                    return ['ok'=>true,'step'=>'clarify','questions'=>$questions,'auto_detected'=>$auto,'recommendations'=>$recs];
                } else {
                    $promptText = trim($parsed['prompt'] ?? '');
                    if ($promptText==='') {
                        app_log('warning','smart_wizard','chat_primary_empty_prompt',[]);
                    } else {
                        $normLangs=[]; foreach (($parsed['languages']??[]) as $l){ $l=strtolower(trim($l)); if(preg_match('~^[a-z]{2}$~',$l)) $normLangs[]=$l; } $normLangs=array_values(array_unique($normLangs));
                        $normRegs=[]; foreach (($parsed['regions']??[]) as $r){ $r=strtoupper(trim($r)); if(preg_match('~^[A-Z]{2}$~',$r)) $normRegs[]=$r; } $normRegs=array_values(array_unique($normRegs));
                        return [ 'ok'=>true,'step'=>'generate','prompt'=>$promptText,'languages'=>$normLangs,'regions'=>$normRegs,'sources'=>$parsed['sources']??[], 'reasoning'=>$parsed['reasoning']??'' ];
                    }
                }
            } else {
                app_log('warning','smart_wizard','chat_primary_non_json',[ 'preview'=>mb_substr($chatContent,0,120) ]);
            }
        } else {
            app_log('warning','smart_wizard','chat_primary_empty_content',[ 'step'=>$step ]);
        }
    } else {
        app_log('warning','smart_wizard','chat_primary_fail',[ 'status'=>$chatStatus,'error'=>$chatErr ]);
    }

    // === Ниже остаётся предыдущая responses-реализация как резервный путь ===
    $initialTokens = $step === 'clarify' ? 300 : 800;
    $timeout = $step === 'generate' ? 90 : 45;

    // Всегда strict=false (избегаем требований указать все поля при strict=true у модели)
    $initialStrict = false;

    $buildPayload = function(int $maxTokens, bool $strict, string $sys, string $usr, array $schema) use ($model) {
        return [
            'model' => $model,
            'max_output_tokens' => $maxTokens,
            'input' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $usr]
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'wizard_response',
                    'schema' => $schema,
                    'strict' => $strict
                ],
                'verbosity' => 'low'
            ]
            // NOTE: temperature убран — модель не поддерживает
        ];
    };

    $payload = $buildPayload($initialTokens, $initialStrict, $systemPrompt, $userPrompt, $schema);

    $doRequest = function(array $payload) use ($requestUrl,$requestHeaders,$timeout) {
        $ch = curl_init($requestUrl);
        curl_setopt_array($ch,[
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => true
        ]);
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $status = (int)($info['http_code'] ?? 0);
        $headerSize = (int)($info['header_size'] ?? 0);
        $body = substr((string)$resp, $headerSize);
        $curlErr = curl_error($ch);
        curl_close($ch);
        return [$status,$body,$curlErr];
    };

    [$status,$body,$curlErr] = $doRequest($payload);
    app_log('info','smart_wizard','Primary wizard request',[ 'step'=>$step,'status'=>$status,'len'=>strlen($body),'strict'=>$initialStrict,'tokens'=>$initialTokens ]);

    if ($status !== 200) {
        app_log('error','smart_wizard','Wizard request failed',[ 'status'=>$status, 'body_preview'=>mb_substr($body,0,400), 'curl_error'=>$curlErr ]);
        return ['ok'=>false,'error'=>'Ошибка запроса к OpenAI ('.$status.').'];
    }

    // --- Парсер ответа (универсальный для responses/chat стиля) ---
    $extractContent = function(array $data, string $rawBody) {
        $content = '';
        $finishReason = '';
        if (isset($data['choices'][0])) { // chat-like
            $finishReason = $data['choices'][0]['finish_reason'] ?? '';
            $msg = $data['choices'][0]['message'] ?? [];
            if (is_array($msg)) {
                if (isset($msg['content']) && is_string($msg['content'])) $content = $msg['content'];
                elseif (isset($msg['content']) && is_array($msg['content'])) {
                    foreach ($msg['content'] as $part) {
                        if (is_array($part) && isset($part['text'])) $content .= $part['text'];
                        elseif (is_string($part)) $content .= $part; }
                }
                if ($content === '' && isset($msg['parsed'])) {
                    $p = $msg['parsed']; if (is_array($p) || is_object($p)) $content = json_encode($p, JSON_UNESCAPED_UNICODE);
                }
            }
        } elseif (isset($data['output']) && is_array($data['output'])) { // responses style
            foreach ($data['output'] as $segment) {
                if (!is_array($segment)) continue;
                if (($segment['type'] ?? '') === 'message' && isset($segment['content']) && is_array($segment['content'])) {
                    foreach ($segment['content'] as $p) { if (isset($p['text'])) $content .= $p['text']; }
                } elseif (($segment['type'] ?? '') === 'output_text' && isset($segment['content'][0]['text'])) {
                    $content .= $segment['content'][0]['text'];
                }
            }
            $finishReason = $data['finish_reason'] ?? ($data['status'] ?? '');
        }
        if ($content === '' && preg_match('~\{[^\n]*?"~u', $rawBody)) {
            // Пытаемся вытащить первый JSON-объект
            if (preg_match('{\{(?:[^{}]|(?R))*\}}u', $rawBody, $m)) {
                $test = $m[0];
                if (json_decode($test,true)) $content = $test;
            }
        }
        if ($content !== '' && preg_match('~```(json)?\s*(.+?)```~is',$content,$mm)) {
            $content = trim($mm[2]);
        }
        return [$content,$finishReason];
    };

    $responseData = json_decode($body, true) ?: [];
    [$content,$finishReason] = $extractContent($responseData, $body);

    $needsCompactRetry = ($content === '' || $finishReason === 'length' || stripos($finishReason,'incomplete')!==false);

    if ($needsCompactRetry) {
        $compactSystem = $step==='clarify'
            ? 'Верни СРАЗУ КОРОТКИЙ JSON {"questions":[],"auto_detected":{"languages":[],"regions":[]},"recommendations":[]} без рассуждений. НИКАКОГО текста вне JSON.'
            : 'Выведи СРАЗУ только JSON {"prompt":...,"languages":[],"regions":[],"sources":[]} без пояснений.';
        $compactPayload = $buildPayload(min($initialTokens*2, $step==='clarify'?600:1000), false, $compactSystem, $userPrompt, $schema);
        [$status2,$body2,$err2] = $doRequest($compactPayload);
        app_log('warning','smart_wizard','Compact retry',[ 'step'=>$step,'status'=>$status2,'len'=>strlen($body2) ]);
        if ($status2===200) {
            $responseData2 = json_decode($body2,true) ?: [];
            [$content2,$finishReason2] = $extractContent($responseData2,$body2);
            if ($content2 === '' && $step==='generate') {
                // Попытка извлечь JSON с ключом "prompt"
                if (preg_match('{\{[^{}]*"prompt"[^{}]*\}}u',$body2,$mP)) { $test=$mP[0]; if (json_decode($test,true)) $content2=$test; }
                elseif (preg_match('{\{(?:[^{}]|(?R))*"prompt"(?:[^{}]|(?R))*\}}u',$body2,$mDeep)) { $test=$mDeep[0]; if (json_decode($test,true)) $content2=$test; }
            }
            if ($content2 !== '') { $content = $content2; $finishReason = $finishReason2; $responseData = $responseData2; }
        }
    }

    // Третий (финальный) сверх-минимальный fallback при пустоте
    if ($content === '') {
        $miniSystem = $step==='clarify'
            ? 'Только JSON {"questions":[],"auto_detected":{"languages":[],"regions":[]},"recommendations":[]}.'
            : 'Только JSON {"prompt":...,"languages":[],"regions":[],"sources":[]}.';
        $miniPayload = $buildPayload(200,false,$miniSystem,$userPrompt,$schema);
        [$st3,$b3,$e3] = $doRequest($miniPayload);
        if ($st3===200) {
            $rd3 = json_decode($b3,true) ?: [];
            [$c3,$fr3] = $extractContent($rd3,$b3);
            if ($c3==='' && $step==='generate') {
                if (preg_match('{\{[^{}]*"prompt"[^{}]*\}}u',$b3,$mP2)) { $t2=$mP2[0]; if (json_decode($t2,true)) $c3=$t2; }
                elseif (preg_match('{\{(?:[^{}]|(?R))*"prompt"(?:[^{}]|(?R))*\}}u',$b3,$mDeep2)) { $t2=$mDeep2[0]; if (json_decode($t2,true)) $c3=$t2; }
            }
            if ($c3!=='') { $content = $c3; $finishReason = $fr3; $responseData = $rd3; app_log('info','smart_wizard','Mini fallback success',[ 'step'=>$step,'len'=>strlen($c3) ]); }
        }
    }

    if (!$result) {
        if ($step === 'clarify') {
            app_log('error','smart_wizard','Clarify model empty, using heuristic fallback',[ 'body_preview'=>mb_substr($body,0,500) ]);
        } else {
            // Попытка доп. regex извлечения из исходного body (responseData уже парсили)
            if (preg_match('{\{[^{}]*"prompt"[^{}]*\}}u',$body,$mmP)) { $cand=$mmP[0]; $tmp=json_decode($cand,true); if(is_array($tmp)) { $result=$tmp; } }
            if (!$result && preg_match('{\{(?:[^{}]|(?R))*"prompt"(?:[^{}]|(?R))*\}}u',$body,$mmDP)) { $cand=$mmDP[0]; $tmp=json_decode($cand,true); if(is_array($tmp)) { $result=$tmp; } }
            if (!$result) {
                // Fallback на chat/completions (многие мини модели всё ещё там стабильно возвращают content)
                $chatUrl='https://api.openai.com/v1/chat/completions';
                $chatPayload=[
                    'model'=>$model,
                    'messages'=>[
                        ['role'=>'system','content'=>'Верни строго JSON {"prompt":string,"languages":[...],"regions":[...],"sources":[...]} без пояснений. Источники не перечислять внутри prompt.'],
                        ['role'=>'user','content'=>$userPrompt]
                    ],
                    'max_completion_tokens'=>600
                ];
                $chF=curl_init($chatUrl);
                curl_setopt_array($chF,[
                    CURLOPT_POST=>true,
                    CURLOPT_HTTPHEADER=>$requestHeaders,
                    CURLOPT_POSTFIELDS=>json_encode($chatPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_TIMEOUT=>60,
                    CURLOPT_HEADER=>true
                ]);
                $respF=curl_exec($chF); $infoF=curl_getinfo($chF); $statusF=(int)($infoF['http_code']??0); $headerSizeF=(int)($infoF['header_size']??0); $bodyF=substr((string)$respF,$headerSizeF); curl_close($chF);
                if($statusF===200){ $dF=json_decode($bodyF,true); $cF=$dF['choices'][0]['message']['content']??''; if(preg_match('~```(json)?\s*(.+?)```~is',$cF,$mmm)) $cF=$mmm[2]; $cF=trim($cF); $jF=json_decode($cF,true); if(is_array($jF)) { $result=$jF; app_log('info','smart_wizard','Chat fallback success',[ 'len'=>strlen($cF) ]); } else { app_log('warning','smart_wizard','Chat fallback empty',[ 'body_preview'=>mb_substr($bodyF,0,400) ]); } } else { app_log('error','smart_wizard','Chat fallback failed',[ 'status'=>$statusF,'body_preview'=>mb_substr($bodyF,0,400) ]); }
            }
            if (!$result) {
                app_log('error','smart_wizard','Generate failed empty content',[ 'finish_reason'=>$finishReason,'body_preview'=>mb_substr($body,0,500) ]);
                return ['ok'=>false,'error'=>'Модель вернула пустой ответ (включая fallback). Повторите позже.'];
            }
        }
    }

    // === Разбор JSON ===
    if ($result === null && $content !== '') { $result = json_decode(trim($content), true); }

    if (!$result) {
        if ($step === 'clarify') {
            app_log('error','smart_wizard','Clarify model empty, using heuristic fallback',[]);
            // Пойдём в эвристический fallback ниже (как будто пустой результат)
        } else {
            app_log('error','smart_wizard','Generate failed empty content',[ 'finish_reason'=>$finishReason ]);
            return ['ok'=>false,'error'=>'Модель вернула пустой ответ. Повторите попытку.'];
        }
    }

    // === Clarify: эвристический fallback, если нет/пусто ===
    if ($step==='clarify') {
        if (!is_array($result)) { $result = []; }
        $questions = $result['questions'] ?? [];
        $auto = $result['auto_detected'] ?? ['languages'=>[], 'regions'=>[]];

        // Языки / регионы auto-detect эвристикой
        $lowerInput = mb_strtolower($userInput);
        $langNameMap = [ 'русск'=>'ru','российск'=>'ru','russian'=>'ru','англ'=>'en','english'=>'en','английск'=>'en','украин'=>'uk','ukrain'=>'uk','польш'=>'pl','polish'=>'pl','немец'=>'de','german'=>'de','франц'=>'fr','french'=>'fr','испан'=>'es','spanish'=>'es' ];
        $regionNameMap = [ 'росси'=>'RU','russia'=>'RU','украин'=>'UA','ukrain'=>'UA','польш'=>'PL','poland'=>'PL','герман'=>'DE','german'=>'DE','франц'=>'FR','france'=>'FR','испан'=>'ES','spain'=>'ES','итал'=>'IT','italy'=>'IT','сша'=>'US','usa'=>'US','америк'=>'US','united states'=>'US','великобрит'=>'GB','united kingdom'=>'GB','uk '=>'GB','англи'=>'GB' ];
        foreach ($langNameMap as $frag=>$code) if (mb_strpos($lowerInput,$frag)!==false) $auto['languages'][]=$code;
        foreach ($regionNameMap as $frag=>$code) if (mb_strpos($lowerInput,$frag)!==false) $auto['regions'][]=$code;
        $auto['languages'] = array_values(array_unique(array_filter($auto['languages'],fn($l)=>preg_match('~^[a-z]{2}$~',$l))));
        $auto['regions'] = array_values(array_unique(array_filter($auto['regions'],fn($r)=>preg_match('~^[A-Z]{2}$~',$r))));

        $hasLangCode = preg_match('~\b(ru|en|uk|pl|de|fr|es)\b~i',$userInput);
        $hasRegionCode = preg_match('~\b(UA|RU|PL|DE|US|GB|FR|ES|IT)\b~i',$userInput);
        $needsLang = !$hasLangCode && empty($auto['languages']);
        $needsRegion = !$hasRegionCode && empty($auto['regions']);
        $needsTime = !preg_match('~(последн|дней|недел|месяц|месяцев|год|202[0-9]|20[1-2][0-9])~ui',$userInput);

        if (empty($questions) && ($needsLang || $needsRegion || $needsTime)) {
            $questions = [];
            if ($needsLang) $questions[] = ['question'=>'Какие языки мониторить? (коды)','type'=>'multiple','options'=>['ru','en','uk','pl','de','fr','es']];
            if ($needsRegion) $questions[] = ['question'=>'Какие регионы / страны важны? (коды)','type'=>'multiple','options'=>['RU','UA','PL','DE','US','GB']];
            if ($needsTime) $questions[] = ['question'=>'Какой временной диапазон анализировать?','type'=>'single','options'=>['30d','90d','6m','12m']];
            $recs = $result['recommendations'] ?? [];
            $recs[] = 'Добавлены базовые вопросы (языки, регионы, период)';
            app_log('info','smart_wizard','Injected heuristic questions',[ 'needsLang'=>$needsLang,'needsRegion'=>$needsRegion,'needsTime'=>$needsTime ]);
            return [ 'ok'=>true, 'step'=>'clarify', 'questions'=>$questions, 'auto_detected'=>$auto, 'recommendations'=>$recs ];
        }
        // Санитизация вопросов (убрать источники)
        $srcPattern = '~(источн|source|форум|forums?|telegram|соц|social|news|review)~iu';
        $cleanQ=[]; foreach ($questions as $q){ if(!is_array($q)||empty($q['question'])) continue; if (preg_match($srcPattern,$q['question'])) continue; if(isset($q['options'])&&is_array($q['options'])) $q['options']=array_values(array_filter($q['options'],fn($o)=>!preg_match($srcPattern,$o))); $cleanQ[]=$q; }
        $recs = array_values(array_filter(($result['recommendations'] ?? []),fn($r)=>is_string($r)&&!preg_match($srcPattern,$r)));
        return [ 'ok'=>true,'step'=>'clarify','questions'=>$cleanQ,'auto_detected'=>$auto,'recommendations'=>$recs ];
    }

    // === Generate ===
    if (!is_array($result)) {
        return ['ok'=>false,'error'=>'Не удалось разобрать JSON финального шага'];
    }
    $normLangs=[]; foreach (($result['languages']??[]) as $l){ $l=strtolower(trim($l)); if(preg_match('~^[a-z]{2}$~',$l)) $normLangs[]=$l; } $normLangs=array_values(array_unique($normLangs));
    $normRegs=[]; foreach (($result['regions']??[]) as $r){ $r=strtoupper(trim($r)); if(preg_match('~^[A-Z]{2}$~',$r)) $normRegs[]=$r; } $normRegs=array_values(array_unique($normRegs));
    $promptText = trim($result['prompt'] ?? '');
    if ($promptText==='') {
        // попытка найти глубже
        $stack=[$result];
        while($stack && $promptText===''){ $node=array_pop($stack); if(is_array($node)) foreach($node as $k=>$v){ if(is_string($k)&&strtolower($k)==='prompt'&&is_string($v)&&trim($v)!==''){ $promptText=trim($v); break; } if(is_array($v)) $stack[]=$v; }}
    }
    if ($promptText==='') {
        app_log('error','smart_wizard','Empty prompt after generate',[]);
        return ['ok'=>false,'error'=>'ИИ вернул пустой prompt'];
    }
    // Удаляем перечисления источников если просочились
    $originalPrompt=$promptText;
    $promptText = preg_replace('~\((?:[^()]*?(?:forums?|telegram|социальн(?:ые|ых)|social|news|reviews?)[^()]*)\)~iu','',$promptText);
    $promptText = preg_replace('~(?:источники|sources)\s*:\s*[^;,.]+~iu','',$promptText);
    $promptText = preg_replace('~\b(forums?|telegram|social media|social networks?|news sites?|review sites?|reviews)\b~iu','',$promptText);
    $promptText = preg_replace('~\s{2,}~u',' ',trim($promptText));
    if ($originalPrompt!==$promptText) app_log('info','smart_wizard','Stripped sources from prompt',[ 'before'=>$originalPrompt,'after'=>$promptText ]);

    return [
        'ok'=>true,
        'step'=>'generate',
        'prompt'=>$promptText,
        'languages'=>$normLangs,
        'regions'=>$normRegs,
        'sources'=>$result['sources'] ?? [],
        'reasoning'=>$result['reasoning'] ?? ''
    ];
}