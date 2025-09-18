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
    $userInput = mb_substr($userInput, 0, 4000); // защита от слишком длинного ввода
    $requestUrl = 'https://api.openai.com/v1/chat/completions';
    $requestHeaders = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Expect:'
    ];
    
    if ($step === 'clarify') {
        // Этап 1: Генерация уточняющих вопросов (обновлённая логика)
        // Цели изменений:
        // 1. Вопросы формируются ТОЛЬКО по недостающей информации (языки, регионы, временной диапазон, цель, объекты мониторинга, негативные исключения, формат/точность).
        // 2. Не спрашивать то, что пользователь уже явно указал.
        // 3. НЕ спрашивать про источники: источники выбираются в настройках и НЕ входят в итоговый prompt.
        // 4. Модель должна извлекать languages (ISO 639-1 lower-case) и regions (ISO 3166-1 alpha-2 upper-case) из пользовательского ввода если они упомянуты (даже в тексте), без дублирования.
        // 5. recommendations: краткие улучшения (0-3), контекстные.
        // 6. questions: 2-5, если ВСЁ уже есть (цель, ключевые сущности, языки, регионы, период) — можно 0.
        // 7. Типы вопросов: single / multiple / text. Не более 6 опций. Формулировки короткие, без вводных.
        // 8. НЕ включать упоминания источников (forums, telegram, social, news, reviews) в prompt и в вопросы.
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
                        ]
                    ],
                    'required' => ['languages', 'regions'],
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
        $systemPrompt = "Ты помощник по настройке мониторинга. Анализируй исходный текст пользователя и определи: цель мониторинга, ключевые бренды/продукты/темы, временной горизонт (если указан), языки (ISO 639-1), регионы / страны (ISO 3166-1 alpha-2), дополнительные фильтры (например намерение конкурентов, отзывы, баги).\n\nЗадача: вернуть JSON по схеме.\n\nПравила генерации вопросов: \n- Генерируй вопросы ТОЛЬКО по недостающим аспектам. \n- Если явно присутствуют цель, сущности (бренд/продукт), языки, регионы И временной диапазон / свежесть — не задавай вопросов (questions = []). \n- Если чего-то не хватает — 2-5 вопросов. \n- Не спрашивай про источники (forums, telegram, social, news, reviews) — они задаются отдельно в настройках. \n- Формат короткий, без нумерации, без вводных. \n- Если предлагаешь options, максимум 6. Для свободного ответа используй type=text. \n- Не дублируй вопросы с одинаковым смыслом. \n\nАвтоопределение: \n- languages: только валидные 2-буквенные коды в lower-case. \n- regions: только 2-буквенные коды стран upper-case. \n- Если кодов нет — массивы пустые (НЕ угадывай). \n\nrecommendations (0-3): как улучшить формулировку или что стоит уточнить (если вопросов нет — могут быть пустыми). \n\nСтрого JSON. НИКАКОГО текста вне JSON. НЕ включай источники в вопросы или recommendations.";
        $userPrompt = "Описание пользователя:\n\n" . $userInput . "\n\nОпредели что отсутствует и подготовь вопросы по правилам.";
    } else {
        // Этап 2: Генерация финального промпта
        // Источники (forums, telegram, social, news, reviews) НЕ должны быть в prompt — они задаются отдельно.
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
        $systemPrompt = "Сформируй финальный JSON.\n".
            "prompt: сжатый, точный, включает: цель мониторинга, ключевые бренды/термины/синонимы, релевантные аспекты (например: отзывы, баги, сравнения, запросы пользователей), временной фокус (если был), исключения (если были). НЕ добавляй перечисление типов источников (forums, telegram, social, news, reviews) внутрь текста prompt. Не добавляй служебных пояснений.\n".
            "languages: ISO 639-1 lower-case (только упомянутые или подтверждённые).\n".
            "regions: ISO 3166-1 alpha-2 upper-case (только упомянутые или подтверждённые).\n".
            "sources: просто массив (если переданы или подразумеваются), НО НЕ включай их в сам prompt. Если нет данных — пустой массив.\n".
            "reasoning: кратко почему так структурирован prompt (может быть опущено моделью).\n".
            // Новое правило: язык вывода промпта
            "ВАЖНО: Текст prompt ДОЛЖЕН быть написан на языке, который лучше всего подходит для выбранных языков/регионов:\n".
            "— если массив languages не пуст — используй languages[0];\n".
            "— иначе определи по регионам: RU→ru, UA→uk, PL→pl, DE→de, FR→fr, ES→es, IT→it, US/GB→en; если определить нельзя — используй язык исходного ввода, иначе en.\n".
            "Строго JSON без текста вне.";
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

    // New: log request meta (no API key)
    app_log('info','smart_wizard','Request meta',[
        'step'=>$step,
        'model'=>$model,
        'token_param'=>$tokenParamName,
        'token_limit'=>$outTokens,
        'has_response_format'=>true,
        'user_input_len'=>strlen($userInput)
    ]);
    
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
    
    // New: log http response meta (raw)
    app_log('info','smart_wizard','HTTP response meta',[
        'status'=>$status,
        'header_size'=>$headerSize,
        'body_len'=>strlen($body)
    ]);
    
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
        $fallbackSystem = "Верни кратчайший возможный валидный JSON вида {\"questions\":[],\"auto_detected\":{\"languages\":[],\"regions\":[]}}. Не добавляй текст вне JSON. Нужно 0 вопросов если достаточно данных или 2-4 вопроса (single/multiple/text). Опций максимум 6.";
        $fallbackPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $fallbackSystem],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            $tokenParamName => 500
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
        // Если модель не принимает текущий параметр токенов — пробуем альтернативный
        if ($status3 === 400 && strpos($body3,'max_tokens') !== false && strpos($body3,'not supported') !== false) {
            $altName = ($tokenParamName === 'max_tokens') ? 'max_completion_tokens' : 'max_tokens';
            $fallbackPayload[$altName] = $fallbackPayload[$tokenParamName];
            unset($fallbackPayload[$tokenParamName]);
            $ch3b = curl_init($requestUrl);
            curl_setopt_array($ch3b, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_POSTFIELDS => json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HEADER => true
            ]);
            $resp3b = curl_exec($ch3b);
            $info3b = curl_getinfo($ch3b);
            $status3b = (int)($info3b['http_code'] ?? 0);
            $headerSize3b = (int)($info3b['header_size'] ?? 0);
            $body3b = substr((string)$resp3b, $headerSize3b);
            curl_close($ch3b);
            if ($status3b === 200) { $status = 200; $body = $body3b; $curlErr = ''; }
        } elseif ($status3 === 200) {
            $status = 200; $body = $body3; $curlErr = $curlErr3;
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
    if (!$responseData) {
        // Попытка спасти ответ: вырезаем от первой '{' до последней '}' и парсим снова
        $start = strpos($body, '{');
        $end   = strrpos($body, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($body, $start, $end - $start + 1);
            $responseData = json_decode($candidate, true);
            if ($responseData) {
                app_log('info','smart_wizard','Recovered JSON from body slice',[ 'slice_len' => strlen($candidate) ]);
                $body = $candidate; // на всякий случай
            }
        }
    }
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
    // New: log AI message meta
    app_log('info','smart_wizard','AI message meta',[
        'finish_reason'=>$finishReason,
        'content_len'=>strlen($content),
        'has_parsed'=> isset($msgNode['parsed']),
        'message_keys'=> is_array($msgNode)? array_values(array_keys($msgNode)) : null,
        'usage'=> $responseData['usage'] ?? null
    ]);
    
    $rawContentForLog = $content;
    if (preg_match('~```(json)?\s*(.+?)```~is', $content, $m)) {
        $content = $m[2];
    }
    $content = trim($content);
    
    // New: warn if content is empty despite 200
    if ($status === 200 && trim($content) === '') {
        app_log('warning','smart_wizard','Empty content despite 200', [ 
            'step'=>$step,
            'finish_reason'=>$finishReason,
            'usage'=>$responseData['usage'] ?? null,
            'body_preview'=> substr($body,0,800)
        ]);
        // Anti-reasoning fallback: если все токены ушли на reasoning и контент пустой
        $usage = $responseData['usage'] ?? [];
        $ct = (int)($usage['completion_tokens'] ?? 0);
        $rt = (int)($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
        if ($finishReason === 'length' && $ct > 0 && $rt >= max(600, (int)floor($ct * 0.8))) {
            app_log('info','smart_wizard','Anti-reasoning fallback start',[ 'ct'=>$ct, 'rt'=>$rt ]);
            // 1) Пытаемся попросить модель не рассуждать и вернуть JSON-объект
            $antiPayload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $step==='generate'
                        ? 'Не рассуждай вслух. Верни строго JSON-объект с ключами prompt,languages,regions,sources. Никакого текста вне JSON.'
                        : 'Не рассуждай вслух. Верни строго JSON-объект с ключами questions,auto_detected,recommendations. Никакого текста вне JSON.'
                    ],
                    ['role' => 'user', 'content' => $userPrompt]
                ],
                'response_format' => [ 'type' => 'json_object' ],
                $tokenParamName => min($outTokens+400, 2000),
                // Параметр может быть не поддержан, обработаем 400 ниже
                'reasoning' => [ 'effort' => 'low' ]
            ];
            $chAR = curl_init($requestUrl);
            curl_setopt_array($chAR,[
                CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>$requestHeaders,
                CURLOPT_POSTFIELDS=>json_encode($antiPayload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>90,
                CURLOPT_HEADER=>true
            ]);
            $respAR = curl_exec($chAR);
            $infoAR = curl_getinfo($chAR);
            $statusAR = (int)($infoAR['http_code'] ?? 0);
            $headerSizeAR = (int)($infoAR['header_size'] ?? 0);
            $bodyAR = substr((string)$respAR, $headerSizeAR);
            curl_close($chAR);
            if ($statusAR === 400 && (strpos($bodyAR,'reasoning') !== false || strpos($bodyAR,'Unknown') !== false)) {
                // Удаляем параметр reasoning и пробуем снова
                unset($antiPayload['reasoning']);
                app_log('info','smart_wizard','Anti-reasoning retry without reasoning param',[]);
                $chAR2 = curl_init($requestUrl);
                curl_setopt_array($chAR2,[
                    CURLOPT_POST=>true,
                    CURLOPT_HTTPHEADER=>$requestHeaders,
                    CURLOPT_POSTFIELDS=>json_encode($antiPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_TIMEOUT=>90,
                    CURLOPT_HEADER=>true
                ]);
                $respAR2 = curl_exec($chAR2);
                $infoAR2 = curl_getinfo($chAR2);
                $statusAR = (int)($infoAR2['http_code'] ?? 0);
                $headerSizeAR2 = (int)($infoAR2['header_size'] ?? 0);
                $bodyAR = substr((string)$respAR2, $headerSizeAR2);
                curl_close($chAR2);
            }
            if ($statusAR === 200) {
                $dataAR = json_decode($bodyAR,true);
                $msgAR = $dataAR['choices'][0]['message'] ?? [];
                $contAR = is_string($msgAR['content'] ?? null) ? $msgAR['content'] : '';
                if ($contAR === '' && isset($msgAR['parsed']) && is_array($msgAR['parsed'])) {
                    $contAR = json_encode($msgAR['parsed'], JSON_UNESCAPED_UNICODE);
                }
                $contAR = trim((string)$contAR);
                if ($contAR !== '') {
                    $content = $contAR;
                    app_log('info','smart_wizard','Anti-reasoning success',[ 'len'=>strlen($contAR) ]);
                } else {
                    app_log('warning','smart_wizard','Anti-reasoning empty',[ 'body_preview'=>substr($bodyAR,0,500) ]);
                }
            } else {
                app_log('error','smart_wizard','Anti-reasoning http fail',[ 'status'=>$statusAR, 'body_preview'=>substr($bodyAR,0,400) ]);
            }

            // 2) Если всё ещё пусто — меняем модель на gpt-4o-mini для стабильности ответа
            if (trim($content) === '') {
                $altModel = 'gpt-4o-mini';
                app_log('info','smart_wizard','Model fallback start',[ 'from'=>$model, 'to'=>$altModel ]);
                $altPayload = $antiPayload; $altPayload['model'] = $altModel;
                $chMF = curl_init($requestUrl);
                curl_setopt_array($chMF,[
                    CURLOPT_POST=>true,
                    CURLOPT_HTTPHEADER=>$requestHeaders,
                    CURLOPT_POSTFIELDS=>json_encode($altPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_TIMEOUT=>90,
                    CURLOPT_HEADER=>true
                ]);
                $respMF = curl_exec($chMF);
                $infoMF = curl_getinfo($chMF);
                $statusMF = (int)($infoMF['http_code'] ?? 0);
                $headerSizeMF = (int)($infoMF['header_size'] ?? 0);
                $bodyMF = substr((string)$respMF, $headerSizeMF);
                curl_close($chMF);
                if ($statusMF === 200) {
                    $dataMF = json_decode($bodyMF,true);
                    $msgMF = $dataMF['choices'][0]['message'] ?? [];
                    $contMF = is_string($msgMF['content'] ?? null) ? $msgMF['content'] : '';
                    if ($contMF === '' && isset($msgMF['parsed']) && is_array($msgMF['parsed'])) {
                        $contMF = json_encode($msgMF['parsed'], JSON_UNESCAPED_UNICODE);
                    }
                    $contMF = trim((string)$contMF);
                    if ($contMF !== '') { $content = $contMF; app_log('info','smart_wizard','Model fallback success',[ 'len'=>strlen($contMF) ]); }
                    else { app_log('warning','smart_wizard','Model fallback empty',[ 'body_preview'=>substr($bodyMF,0,500) ]); }
                } else {
                    app_log('error','smart_wizard','Model fallback http fail',[ 'status'=>$statusMF, 'body_preview'=>substr($bodyMF,0,400) ]);
                }
            }
        }
    }
    
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
            app_log('info','smart_wizard','Fallback generate retry success',[
                'finish_reason'=>$finishReason,
                'len'=>strlen($content),
                'body_preview'=> strlen($content)===0 ? substr($bodyG,0,600) : null
            ]);
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
                else { app_log('warning','smart_wizard','Third attempt still empty',['body_preview'=>substr($bodyT,0,600)]); }
            } else {
                app_log('error','smart_wizard','Third attempt failed',['status'=>$statusT,'body_preview'=>substr($bodyT,0,200)]);
            }
        }
    }
    
    // Дополнительный попытка если всё ещё пусто
    if (trim($content)==='') {
        // JSON-object response_format fallback
        app_log('info','smart_wizard','JSON-object fallback start',['step'=>$step]);
        $rfPayload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $step==='generate'
                    ? 'Верни строго JSON-объект с ключами prompt,languages,regions,sources. Никакого текста вне JSON.'
                    : 'Верни строго JSON-объект с ключами questions,auto_detected,recommendations. Никакого текста вне JSON.'
                ],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'response_format' => [ 'type' => 'json_object' ],
            $tokenParamName => min($outTokens+600, 2200)
        ];
        $chJ = curl_init($requestUrl);
        curl_setopt_array($chJ,[
            CURLOPT_POST=>true,
            CURLOPT_HTTPHEADER=>$requestHeaders,
            CURLOPT_POSTFIELDS=>json_encode($rfPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>90,
            CURLOPT_HEADER=>true
        ]);
        $respJ = curl_exec($chJ);
        $infoJ = curl_getinfo($chJ);
        $statusJ = (int)($infoJ['http_code'] ?? 0);
        $headerSizeJ = (int)($infoJ['header_size'] ?? 0);
        $bodyJ = substr((string)$respJ, $headerSizeJ);
        curl_close($chJ);
        if ($statusJ===200) {
            $dataJ = json_decode($bodyJ,true);
            $msgJ = $dataJ['choices'][0]['message'] ?? [];
            $contJ = is_string($msgJ['content'] ?? null) ? $msgJ['content'] : '';
            if ($contJ === '' && isset($msgJ['parsed']) && is_array($msgJ['parsed'])) {
                $contJ = json_encode($msgJ['parsed'], JSON_UNESCAPED_UNICODE);
            }
            $contJ = trim((string)$contJ);
            if ($contJ!=='') { $content = $contJ; app_log('info','smart_wizard','JSON-object fallback success',['len'=>strlen($contJ)]); }
            else { app_log('warning','smart_wizard','JSON-object fallback empty',['body_preview'=>substr($bodyJ,0,400)]); }
        } else {
            app_log('error','smart_wizard','JSON-object fallback http fail',['status'=>$statusJ,'body_preview'=>substr($bodyJ,0,300)]);
        }
    }
    
    $result = $content !== '' ? json_decode($content, true) : null;
    
    if (!$result) {
        $extracted = null;
        if (preg_match('{\{(?:[^{}]*?(?:forums?|telegram|социальн(?:ые|ых)|social|news|reviews?)[^{}]*)\}}u', $body, $mm)) {
            $candidate = $mm[0];
            $decoded = json_decode($candidate, true);
            // Принимаем только если есть доменные ключи (prompt/questions/languages/regions)
            if (is_array($decoded) && (isset($decoded['prompt']) || isset($decoded['questions']) || isset($decoded['auto_detected']) || isset($decoded['languages']) || isset($decoded['regions']))) {
                $extracted = $decoded; $result = $decoded; $content = $candidate;
            }
        }
        if (!$result && $rawContentForLog !== '' && $rawContentForLog !== $content) {
            $decoded = json_decode($rawContentForLog, true);
            if (is_array($decoded) && (isset($decoded['prompt']) || isset($decoded['questions']) || isset($decoded['auto_detected']) || isset($decoded['languages']) || isset($decoded['regions']))) {
                $result = $decoded;
            }
        }
        // Ещё один фолбэк: если generate и ничего не распарсили — запрашиваем только prompt
        if (!$result && $step === 'generate') {
            app_log('info','smart_wizard','Prompt-only fallback start', []);
            $promptOnlyPayload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Верни строго JSON вида {"prompt": string}. Никакого текста вне JSON. Не перечисляй источники (форумы/соцсети и т.д.) внутри prompt.'],
                    ['role' => 'user', 'content' => $userPrompt]
                ],
                $tokenParamName => 800
            ];
            $chP = curl_init($requestUrl);
            curl_setopt_array($chP,[
                CURLOPT_POST=>true,
                CURLOPT_HTTPHEADER=>$requestHeaders,
                CURLOPT_POSTFIELDS=>json_encode($promptOnlyPayload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_TIMEOUT=>60,
                CURLOPT_HEADER=>true
            ]);
            $respP = curl_exec($chP);
            $infoP = curl_getinfo($chP);
            $statusP = (int)($infoP['http_code'] ?? 0);
            $headerSizeP = (int)($infoP['header_size'] ?? 0);
            $bodyP = substr((string)$respP, $headerSizeP);
            // Если модель не поддерживает текущий параметр токенов — пробуем альтернативный
            if ($statusP === 400 && strpos($bodyP,'max_tokens') !== false && strpos($bodyP,'not supported') !== false) {
                $altName = ($tokenParamName === 'max_tokens') ? 'max_completion_tokens' : 'max_tokens';
                $promptOnlyPayload[$altName] = $promptOnlyPayload[$tokenParamName];
                unset($promptOnlyPayload[$tokenParamName]);
                $chPa = curl_init($requestUrl);
                curl_setopt_array($chPa,[
                    CURLOPT_POST=>true,
                    CURLOPT_HTTPHEADER=>$requestHeaders,
                    CURLOPT_POSTFIELDS=>json_encode($promptOnlyPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_RETURNTRANSFER=>true,
                    CURLOPT_TIMEOUT=>60,
                    CURLOPT_HEADER=>true
                ]);
                $respPa = curl_exec($chPa);
                $infoPa = curl_getinfo($chPa);
                $statusPa = (int)($infoPa['http_code'] ?? 0);
                $headerSizePa = (int)($infoPa['header_size'] ?? 0);
                $bodyPa = substr((string)$respPa, $headerSizePa);
                $statusP = $statusPa; $bodyP = $bodyPa;
            }
            if ($statusP === 200) {
                $dataP = json_decode($bodyP, true);
                $msgP = $dataP['choices'][0]['message'] ?? [];
                $contP = is_string($msgP['content'] ?? null) ? $msgP['content'] : '';
                if ($contP === '' && isset($msgP['parsed']) && is_array($msgP['parsed'])) {
                    $contP = json_encode($msgP['parsed'], JSON_UNESCAPED_UNICODE);
                }
                if (preg_match('~```(json)?\s*(.+?)```~is', (string)$contP, $mP)) { $contP = $mP[2]; }
                $decP = json_decode(trim((string)$contP), true);
                app_log('info','smart_wizard','Prompt-only response meta',[ 'status'=>$statusP, 'content_len'=>strlen((string)$contP), 'body_preview'=>substr($bodyP,0,600) ]);
                if (is_array($decP) && !empty($decP['prompt'])) {
                    $result = [
                        'prompt' => (string)$decP['prompt'],
                        'languages' => [],
                        'regions' => [],
                        'sources' => []
                    ];
                    app_log('info','smart_wizard','Prompt-only fallback success', ['len'=>strlen($result['prompt'])]);
                } else {
                    app_log('error','smart_wizard','Prompt-only fallback parse fail', ['body_preview'=>substr($bodyP,0,300)]);
                }
            } else {
                app_log('error','smart_wizard','Prompt-only fallback http fail', ['status'=>$statusP,'body_preview'=>substr($bodyP,0,300)]);
            }
        }
        
        if (!$result) {
            app_log('error', 'smart_wizard', 'Failed to parse JSON content', [
                'content_preview' => mb_substr($content,0,400),
                'raw_message_node' => $msgNode,
                'body_preview' => mb_substr($body,0,800),
                // New: include usage and finish_reason to explain truncation
                'finish_reason' => $finishReason,
                'usage' => $responseData['usage'] ?? null
            ]);
            return ['ok' => false, 'error' => 'Не удалось разобрать ответ ИИ (пустой или не-JSON). Повторите еще раз.'];
        }
    }
    
    // === Санитизация (clarify): удаляем вопросы про источники/каналы, даже если модель их вернула ===
    if ($step === 'clarify' && is_array($result)) {
        $srcPattern = '~(источн|source|форум|forums?|telegram|соц|social|news|review)~iu';
        if (isset($result['questions']) && is_array($result['questions'])) {
            $cleanQ = [];
            foreach ($result['questions'] as $q) {
                if (!is_array($q) || empty($q['question'])) continue;
                $questionText = trim((string)$q['question']);
                if ($questionText === '') continue;
                if (preg_match($srcPattern, $questionText)) {
                    continue; // пропускаем вопросы про источники
                }
                // Фильтруем options
                if (isset($q['options']) && is_array($q['options'])) {
                    $opts = [];
                    foreach ($q['options'] as $opt) {
                        if (is_string($opt) && !preg_match($srcPattern, $opt)) {
                            $opts[] = $opt;
                        }
                    }
                    $q['options'] = $opts;
                }
                $cleanQ[] = $q;
            }
            $result['questions'] = array_values($cleanQ);
        }
        if (isset($result['recommendations']) && is_array($result['recommendations'])) {
            $result['recommendations'] = array_values(array_filter($result['recommendations'], function($r) use ($srcPattern){
                return is_string($r) ? !preg_match($srcPattern,$r) : false;
            }));
        }
        return [
            'ok' => true,
            'step' => 'clarify',
            'questions' => $result['questions'],
            'auto_detected' => $result['auto_detected'],
            'recommendations' => $result['recommendations'] ?? []
        ];
    }
    
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
    if ($step === 'generate') {
        // Дополнительная фильтрация: удаляем из prompt перечисления источников, если модель их всё же вставила
        $originalPrompt = $promptText;
        // Удаляем скобочные блоки, содержащие только источники
        $promptText = preg_replace('~\((?:[^()]*?(?:forums?|telegram|социальн(?:ые|ых)|social|news|reviews?)[^()]*)\)~iu','',$promptText);
        // Удаляем явные фразы вида "источники: ..."
        $promptText = preg_replace('~(?:источники|sources)\s*:\s*[^;,.]+~iu','',$promptText);
        // Удаляем одиночные упоминания источников в конце
        $promptText = preg_replace('~\b(forums?|telegram|social media|social networks?|news sites?|review sites?|reviews)\b~iu','', $promptText);
        $promptText = preg_replace('~\s{2,}~u',' ', trim($promptText));
        if ($originalPrompt !== $promptText) {
            app_log('info','smart_wizard','Stripped sources from prompt',[ 'before'=>$originalPrompt, 'after'=>$promptText ]);
        }
    }
    $final = [
        'ok' => true,
        'step' => 'generate',
        'prompt' => $promptText,
        'languages' => $normLangs,
        'regions' => $normRegs,
        'sources' => $result['sources'] ?? [],
        'reasoning' => $result['reasoning'] ?? ''
    ];
    // New: log final output meta
    app_log('info','smart_wizard','Final output meta',[
        'prompt_len'=>strlen($promptText),
        'languages'=>$normLangs,
        'regions'=>$normRegs,
        'sources_count'=>is_array($final['sources'])? count($final['sources']) : 0
    ]);
    return $final;
}