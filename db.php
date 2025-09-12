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
    
    $message = "🎯 Новые результаты мониторинга\n\n";
    $message .= "📊 Найдено тем: $totalNew\n";
    $message .= "🌐 Доменов затронуто: $domainsCount\n\n";
    
    // Показываем топ-5 результатов
    $topFindings = array_slice($findings, 0, 5);
    foreach ($topFindings as $finding) {
        $title = mb_substr($finding['title'] ?? '', 0, 60);
        $domain = $finding['domain'] ?? '';
        $message .= "• $title\n  $domain\n\n";
    }
    
    if ($totalNew > 5) {
        $message .= "... и ещё " . ($totalNew - 5) . " результатов\n\n";
    }
    
    $message .= "⏰ " . date('Y-m-d H:i');
    
    $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
    $ch = curl_init($tgUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $tgChat,
            'text' => $message,
            'disable_web_page_preview' => 1
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
    $userInput = mb_substr($userInput, 0, 4000); // защита от слишком длинного ввода
    $requestUrl = 'https://api.openai.com/v1/chat/completions';
    $requestHeaders = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Expect:'
    ];
    
    if ($step === 'clarify') {
        // Этап 1: Генерация уточняющих вопросов
        $schema = [
            'type' => 'object',
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'options' => [
                                'type' => 'array',
                                'items' => ['type' => 'string']
                            ],
                            'type' => ['type' => 'string', 'enum' => ['single', 'multiple', 'text']]
                        ],
                        'required' => ['question', 'type'],
                        'additionalProperties' => false
                    ]
                ],
                'auto_detected' => [
                    'type' => 'object',
                    'properties' => [
                        'languages' => [
                            'type' => 'array',
                            'items' => ['type' => 'string']
                        ],
                        'regions' => [
                            'type' => 'array',
                            'items' => ['type' => 'string']
                        ],
                        'sources' => [
                            'type' => 'array',
                            'items' => ['type' => 'string']
                        ]
                    ],
                    'required' => ['languages', 'regions', 'sources'],
                    'additionalProperties' => false
                ],
                'recommendations' => [
                    'type' => 'array',
                    'items' => ['type' => 'string']
                ]
            ],
            'required' => ['questions', 'auto_detected'],
            'additionalProperties' => false
        ];
        $systemPrompt = "Ты помощник по настройке мониторинга. ВСЕГДА генерируй 2-4 уточняющих вопроса (questions) кроме крайне редкого случая, когда: 1) явно указана цель мониторинга, 2) перечислены целевые типы источников, 3) заданы КОДЫ языков (ISO 639-1: ru,en,uk,pl,de,fr,es) и КОДЫ стран (ISO 3166-1 alpha-2: UA,PL,DE,US,FR,RU,GB,ES,IT) и 4) понятны временные рамки и intent. Если хотя бы один из критериев не выполнен — задай вопросы.\nФормат вопросов: короткие, без вводных, без номера. Для выбора нескольких — type=multiple, для одного — single, свободный ввод — text. Не более 6 опций.\nДобавь recommendations (до 3 лаконичных подсказок по улучшению описания или уточнению нишевых тем).\nАвтоопредели languages (только ISO коды в lower-case), regions (только ISO country codes upper-case), sources (forums, telegram, social, news, reviews).\nВерни СТРОГО JSON по схеме без пояснений.";
        $userPrompt = "Описание пользователя:\n\n" . $userInput . "\n\nОпредели, что не хватает и задай вопросы.";
    } else {
        // Этап 2: Генерация финального промпта (упрощили схему, reasoning не обязателен)
        $schema = [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string'],
                'languages' => ['type' => 'array','items' => ['type' => 'string']],
                'regions' => ['type' => 'array','items' => ['type' => 'string']],
                'sources' => ['type' => 'array','items' => ['type' => 'string']],
                'reasoning' => ['type' => 'string']
            ],
            'required' => ['prompt','languages','regions','sources'],
            'additionalProperties' => false
        ];
        $systemPrompt = "Сформируй финальный JSON. prompt: сжатый, конкретный, содержит ключевые слова/синонимы, контекст цели мониторинга и фильтры (если есть). languages: ISO 639-1 lowercase, regions: ISO 3166-1 alpha-2 uppercase. Только коды, без названий. sources из ограниченного списка [forums,telegram,social,news,reviews]. Никакого текста вне JSON.";
        $userPrompt = $userInput;
    }
    
    // Динамический лимит токенов (уменьшаем для clarify чтобы снизить риск лимита)
    $outTokens = $step === 'clarify' ? 700 : 1200; 
    // Автоопределение поддерживаемого параметра — пробуем сначала max_tokens, при 400 с Unsupported переключаемся
    $tokenParamName = 'max_tokens';
    $altTokenParamName = 'max_completion_tokens';
    
    $buildPayload = function($paramName,$limit) use ($model,$systemPrompt,$userPrompt,$schema){
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'wizard_response',
                    'schema' => $schema,
                    'strict' => true
                ]
            ],
            $paramName => $limit
        ];
    };
    $payload = $buildPayload($tokenParamName,$outTokens);
    
    $timeout = ($step === 'generate') ? 90 : 45;
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADER => true
    ]);
    
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $status = (int)($info['http_code'] ?? 0);
    $headerSize = (int)($info['header_size'] ?? 0);
    $body = substr((string)$resp, $headerSize);
    $curlErr = curl_error($ch);
    curl_close($ch);
    
    // Переключение параметра ограничения токенов при ошибке
    if ($status === 400 && strpos($body,'Unsupported parameter') !== false) {
        if (strpos($body, $tokenParamName) !== false) {
            $prev = $tokenParamName;
            $tokenParamName = ($tokenParamName === 'max_tokens') ? $altTokenParamName : 'max_tokens';
            app_log('info','smart_wizard','Retry with alternate token param', ['from'=>$prev,'to'=>$tokenParamName]);
            $payload = $buildPayload($tokenParamName,$outTokens);
            $chA = curl_init($requestUrl);
            curl_setopt_array($chA,[
                CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>$requestHeaders,
                CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_HEADER=>true
            ]);
            $respA = curl_exec($chA);
            $infoA = curl_getinfo($chA);
            $status = (int)($infoA['http_code'] ?? 0);
            $headerSize = (int)($infoA['header_size'] ?? 0);
            $body = substr((string)$respA, $headerSize);
            $curlErr = curl_error($chA);
            curl_close($chA);
        }
    }
    
    // Адаптация под сообщение о необходимости увеличить лимит для второго варианта параметра
    if ($status === 400 && strpos($body, $tokenParamName) !== false && strpos($body,'higher') !== false) {
        if ($outTokens < 3500) {
            $outTokens += 800;
            $payload = $buildPayload($tokenParamName,$outTokens);
            app_log('info','smart_wizard','Retry with higher token limit', ['step'=>$step,'param'=>$tokenParamName,'new_limit'=>$outTokens]);
            $ch2 = curl_init($requestUrl);
            curl_setopt_array($ch2,[
                CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>$requestHeaders,
                CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>$timeout,
                CURLOPT_HEADER=>true
            ]);
            $resp2 = curl_exec($ch2);
            $info2 = curl_getinfo($ch2);
            $status2 = (int)($info2['http_code'] ?? 0);
            $headerSize2 = (int)($info2['header_size'] ?? 0);
            $body2 = substr((string)$resp2, $headerSize2);
            $curlErr2 = curl_error($ch2);
            curl_close($ch2);
            if ($status2 === 200) { $body = $body2; $status = 200; } else { app_log('error','smart_wizard','Retry failed',['status'=>$status2,'curl_error'=>$curlErr2,'body_preview'=>substr($body2,0,300)]); }
        }
    }
    
    if ($status === 400 && strpos($body, 'max_tokens') !== false && strpos($body, 'not supported') !== false) {
        // Модель не поддерживает старый параметр; уже используем новый — просто лог
        app_log('error', 'smart_wizard', 'Model rejected token param', ['used_param' => $tokenParamName, 'body_preview' => substr($body,0,200)]);
        return ['ok'=>false,'error'=>'Модель не принимает параметр ограничения токенов. Попробуйте другую модель.'];
    }
    if ($status === 400 && strpos($body, 'max_completion_tokens') !== false && strpos($body, 'higher') !== false) {
        if ($outTokens < 3500) {
            $payload[$tokenParamName] = $outTokens + 800;
            app_log('info', 'smart_wizard', 'Retry with higher token limit', ['step'=>$step,'new_limit'=>$payload[$tokenParamName]]);
            $ch2 = curl_init($requestUrl);
            curl_setopt_array($ch2, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HEADER => true
            ]);
            $resp2 = curl_exec($ch2);
            $info2 = curl_getinfo($ch2);
            $status2 = (int)($info2['http_code'] ?? 0);
            $headerSize2 = (int)($info2['header_size'] ?? 0);
            $body2 = substr((string)$resp2, $headerSize2);
            $curlErr2 = curl_error($ch2);
            curl_close($ch2);
            if ($status2 === 200) { $body = $body2; $status = 200; } else { app_log('error','smart_wizard','Retry failed',['status'=>$status2,'curl_error'=>$curlErr2,'body_preview'=>substr($body2,0,300)]); }
        }
    }
    
    if ($status === 400 && (strpos($body, 'Invalid schema for response_format') !== false || strpos($body,'response_format') !== false)) {
        // Повторяем без response_format
        unset($payload['response_format']);
        app_log('info','smart_wizard','Retry without response_format', ['step'=>$step]);
        $chR = curl_init($requestUrl);
        curl_setopt_array($chR,[
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>$requestHeaders,
            CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>$timeout,
            CURLOPT_HEADER=>true
        ]);
        $respR = curl_exec($chR);
        $infoR = curl_getinfo($chR);
        $statusR = (int)($infoR['http_code'] ?? 0);
        $headerSizeR = (int)($infoR['header_size'] ?? 0);
        $bodyR = substr((string)$respR, $headerSizeR);
        $curlErrR = curl_error($chR);
        curl_close($chR);
        if ($statusR === 200) { $status = 200; $body = $bodyR; $curlErr = $curlErrR; }
        else { app_log('error','smart_wizard','Retry without response_format failed',['status'=>$statusR,'body_preview'=>substr($bodyR,0,300)]); }
    }
    
    // Второй fallback: если всё ещё не 200 и step=clarify — пробуем без json_schema
    if ($status !== 200 && $step === 'clarify') {
        $fallbackSystem = "Верни кратчайший возможный валидный JSON вида {\"questions\":[],\"auto_detected\":{\"languages\":[],\"regions\":[],\"sources\":[]}}. Не добавляй текст вне JSON. Нужно 0 вопросов если достаточно данных или 2-4 вопроса (single/multiple/text). Опций максимум 6.";
        $fallbackPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $fallbackSystem],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            $tokenParamName => 500,
            'temperature' => 0.1
        ];
        $ch3 = curl_init($requestUrl);
        curl_setopt_array($ch3, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADER => true
        ]);
        $resp3 = curl_exec($ch3);
        $info3 = curl_getinfo($ch3);
        $status3 = (int)($info3['http_code'] ?? 0);
        $headerSize3 = (int)($info3['header_size'] ?? 0);
        $body3 = substr((string)$resp3, $headerSize3);
        $curlErr3 = curl_error($ch3);
        curl_close($ch3);
        app_log('info', 'smart_wizard', 'Fallback clarify no-schema request', ['status' => $status3, 'len' => strlen($body3)]);
        if ($status3 === 200) {
            $status = 200; $body = $body3; $curlErr = $curlErr3; // перезаписываем для дальнейшего парсинга (ниже общий парсер)
            // Парсер ниже не ожидает schema, просто найдём JSON
        } else {
            app_log('error', 'smart_wizard', 'Fallback clarify failed', ['status' => $status3, 'curl_error' => $curlErr3, 'body_preview' => substr($body3,0,300)]);
        }
    }
    
    app_log('info', 'smart_wizard', 'OpenAI request', [
        'step' => $step,
        'status' => $status,
        'user_input_length' => strlen($userInput),
        'response_length' => strlen($body)
    ]);
    
    if ($status !== 200) {
        $errorDetails = [
            'status' => $status,
            'curl_error' => $curlErr,
            'body_preview' => substr($body, 0, 500)
        ];
        app_log('error', 'smart_wizard', 'OpenAI request failed', $errorDetails);
        
        $hint = '';
        if (strpos($body, 'Invalid schema') !== false) {
            $hint = 'Похоже, что OpenAI ожидает все свойства в required при strict=true. Мы обновили схему.';
        }
        
        return [
            'ok' => false,
            'error' => "Ошибка запроса к OpenAI (код $status). Проверьте API ключ и интернет-соединение.",
            'details' => $errorDetails,
            'hint' => $hint
        ];
    }
    
    $responseData = json_decode($body, true);
    if (!$responseData || !isset($responseData['choices'][0]['message']['content'])) {
        app_log('error', 'smart_wizard', 'Invalid OpenAI response format', ['body' => $body]);
        return ['ok' => false, 'error' => 'Некорректный формат ответа от OpenAI'];
    }
    $finishReason = $responseData['choices'][0]['finish_reason'] ?? '';
    $msgNode = $responseData['choices'][0]['message'] ?? null;
    $content = '';
    if (is_array($msgNode)) {
        if (isset($msgNode['content']) && is_string($msgNode['content'])) {
            $content = $msgNode['content'];
        } elseif (isset($msgNode['content']) && is_array($msgNode['content'])) {
            $parts = [];
            foreach ($msgNode['content'] as $part) {
                if (is_array($part) && isset($part['text'])) { $parts[] = $part['text']; }
                elseif (is_string($part)) { $parts[] = $part; }
            }
            $content = implode("\n", $parts);
        }
        if ($content === '' && isset($msgNode['parsed']) && is_array($msgNode['parsed'])) {
            $content = json_encode($msgNode['parsed'], JSON_UNESCAPED_UNICODE);
        }
    }
    
    $rawContentForLog = $content;
    if (preg_match('~```(json)?\s*(.+?)```~is', $content, $m)) {
        $content = $m[2];
    }
    $content = trim($content);
    
    // Fallback: для generate если контент пустой или finish_reason=length
    if ($step === 'generate' && (trim($content)==='' || $finishReason==='length')) {
        app_log('warning','smart_wizard','Empty or truncated content on generate, fallback retry',[
            'finish_reason'=>$finishReason,
            'resp_len'=>strlen($body)
        ]);
        // Повторяем без response_format и без reasoning поля в подсказке
        $fallbackSystem = "Верни строго JSON: {\"prompt\":string,\"languages\":[...],\"regions\":[...],\"sources\":[...]}. Никакого текста вне JSON.";
        $fallbackPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $fallbackSystem],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            $tokenParamName => 1600
        ];
        $chG = curl_init($requestUrl);
        curl_setopt_array($chG, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HEADER => true
        ]);
        $respG = curl_exec($chG);
        $infoG = curl_getinfo($chG);
        $statusG = (int)($infoG['http_code'] ?? 0);
        $headerSizeG = (int)($infoG['header_size'] ?? 0);
        $bodyG = substr((string)$respG, $headerSizeG);
        curl_close($chG);
        if ($statusG === 200) {
            $responseData = json_decode($bodyG, true) ?: $responseData; // перезапись
            if (isset($responseData['choices'][0]['message']['content'])) {
                $content = $responseData['choices'][0]['message']['content'];
                if (preg_match('~```(json)?\s*(.+?)```~is', $content, $mm)) { $content = $mm[2]; }
                $content = trim($content);
            }
            $finishReason = $responseData['choices'][0]['finish_reason'] ?? $finishReason;
            app_log('info','smart_wizard','Fallback generate retry success',['finish_reason'=>$finishReason,'len'=>strlen($content)]);
        } else {
            app_log('error','smart_wizard','Fallback generate retry failed',['status'=>$statusG,'body_preview'=>substr($bodyG,0,300)]);
        }
        // Дополнительный попытка если всё ещё пусто
        if (trim($content)==='') {
            $thirdPayload = [
                'model' => $model,
                'messages' => [
                    ['role'=>'system','content'=>'Верни строго JSON с ключами prompt,languages,regions,sources.'],
                    ['role'=>'user','content'=>$userPrompt]
                ],
                $tokenParamName => min($outTokens+400, 2000)
            ];
            $chT = curl_init($requestUrl);
            curl_setopt_array($chT,[
                CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>$requestHeaders,
                CURLOPT_POSTFIELDS=>json_encode($thirdPayload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>90,
                CURLOPT_HEADER=>true
            ]);
            $respT = curl_exec($chT);
            $infoT = curl_getinfo($chT);
            $statusT = (int)($infoT['http_code']??0);
            $headerSizeT = (int)($infoT['header_size']??0);
            $bodyT = substr((string)$respT,$headerSizeT);
            curl_close($chT);
            if ($statusT===200) {
                $dataT = json_decode($bodyT,true);
                $cT = $dataT['choices'][0]['message']['content'] ?? '';
                if (preg_match('~```(json)?\s*(.+?)```~is', $cT, $mmm)) { $cT = $mmm[2]; }
                $cT = trim($cT);
                if ($cT!=='') { $content = $cT; app_log('info','smart_wizard','Third attempt success',['len'=>strlen($cT)]); }
                else { app_log('warning','smart_wizard','Third attempt still empty',[]); }
            } else {
                app_log('error','smart_wizard','Third attempt failed',['status'=>$statusT,'body_preview'=>substr($bodyT,0,200)]);
            }
        }
    }
    
    $result = $content !== '' ? json_decode($content, true) : null;
    
    if (!$result) {
        $extracted = null;
        if (preg_match('{\{(?:[^{}]|(?R))*\}}u', $body, $mm)) {
            $candidate = $mm[0];
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) { $extracted = $decoded; $result = $decoded; $content = $candidate; }
        }
        if (!$result && $rawContentForLog !== '' && $rawContentForLog !== $content) {
            $decoded = json_decode($rawContentForLog, true);
            if (is_array($decoded)) { $result = $decoded; }
        }
        if (!$result) {
            app_log('error', 'smart_wizard', 'Failed to parse JSON content', [
                'content_preview' => mb_substr($content,0,400),
                'raw_message_node' => $msgNode,
                'body_preview' => mb_substr($body,0,800)
            ]);
            return ['ok' => false, 'error' => 'Не удалось разобрать ответ ИИ (пустой или не-JSON). Повторите еще раз.'];
        }
    }
    
    if ($step === 'clarify') {
        return [
            'ok' => true,
            'step' => 'clarify',
            'questions' => $result['questions'] ?? [],
            'auto_detected' => $result['auto_detected'] ?? [],
            'recommendations' => $result['recommendations'] ?? []
        ];
    } else {
        // Нормализация кодов
        $normLangs = [];
        foreach (($result['languages'] ?? []) as $l) {
            $l = strtolower(trim($l));
            if (preg_match('~^[a-z]{2}$~', $l)) $normLangs[] = $l;
        }
        $normLangs = array_values(array_unique($normLangs));
        $normRegs = [];
        foreach (($result['regions'] ?? []) as $r) {
            $r = strtoupper(trim($r));
            if (preg_match('~^[A-Z]{2}$~', $r)) $normRegs[] = $r;
        }
        $normRegs = array_values(array_unique($normRegs));
        $promptText = trim($result['prompt'] ?? '');
        if ($promptText === '') {
            // Поиск prompt рекурсивно
            $stack = [$result];
            while ($stack) { $node = array_pop($stack); if (is_array($node)) { foreach ($node as $k=>$v){ if (is_string($k)&&strtolower($k)==='prompt'&&is_string($v)&&trim($v)!==''){ $promptText = trim($v); break 2;} if (is_array($v)) $stack[]=$v; } } }
        }
        if ($promptText === '') {
            app_log('error','smart_wizard','Empty prompt extracted after normalization',[ 'keys'=>array_keys($result)]);
            return ['ok'=>false,'error'=>'ИИ вернул пустой промпт. Повторите еще раз.'];
        }
        return [
            'ok' => true,
            'step' => 'generate',
            'prompt' => $promptText,
            'languages' => $normLangs,
            'regions' => $normRegs,
            'sources' => $result['sources'] ?? [],
            'reasoning' => $result['reasoning'] ?? ''
        ];
    }
}