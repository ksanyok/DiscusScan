<?php
require_once __DIR__ . '/db.php';

// Проверка доступа
$isCli = php_sapi_name() === 'cli';
$isManual = isset($_GET['manual']) && $_GET['manual'] === '1';

if (!$isCli && !$isManual) {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== (string)get_setting('cron_secret', '')) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}

if ($isManual) {
    require_login();
}

// Определяем действие
$action = $_GET['action'] ?? 'scan';

// Логирование функция
function orchLog(string $msg, array $ctx = []): void {
    app_log('info', 'orchestration', $msg, $ctx);
}

/**
 * Семплинг доменов - первая волна для обнаружения новых площадок
 */
function run_seed_domains(): array {
    $settings = [
        'topic' => (string)get_setting('orchestration_topic', ''),
        'sources' => json_decode((string)get_setting('orchestration_sources', '["forums"]'), true) ?: ['forums'],
        'languages' => json_decode((string)get_setting('orchestration_languages', '["ru"]'), true) ?: ['ru'],
        'regions' => json_decode((string)get_setting('orchestration_regions', '["UA"]'), true) ?: ['UA'],
        'exclude_domains' => json_decode((string)get_setting('orchestration_exclude_domains', '[]'), true) ?: []
    ];
    
    if (empty($settings['topic'])) {
        return ['ok' => false, 'error' => 'Topic not configured'];
    }
    
    orchLog('Starting domain seeding', $settings);
    
    // Используем существующий модуль генерации запросов из scan.php
    $apiKey = (string)get_setting('openai_api_key', '');
    $model = (string)get_setting('openai_model', 'gpt-5-mini');
    
    if (empty($apiKey)) {
        return ['ok' => false, 'error' => 'OpenAI API key not configured'];
    }
    
    // Генерируем форумные "рыболовные" запросы
    $seedPrompts = [];
    foreach ($settings['languages'] as $lang) {
        $langName = ['ru' => 'русском', 'en' => 'английском', 'uk' => 'украинском', 'pl' => 'польском'][$lang] ?? $lang;
        
        $seedPrompts[] = "site:forum.* \"{$settings['topic']}\" на {$langName}";
        $seedPrompts[] = "inurl:forum \"{$settings['topic']}\" {$lang}";
        $seedPrompts[] = "inurl:topic \"{$settings['topic']}\" {$lang}";
        $seedPrompts[] = "inurl:thread \"{$settings['topic']}\" {$lang}";
        $seedPrompts[] = "\"{$settings['topic']}\" форум обсуждение {$lang}";
        $seedPrompts[] = "\"{$settings['topic']}\" community discussion {$lang}";
        
        // Региональные подсказки
        foreach ($settings['regions'] as $region) {
            $tld = strtolower($region);
            $seedPrompts[] = "site:.{$tld} \"{$settings['topic']}\" форум";
            $seedPrompts[] = "site:.{$tld} \"{$settings['topic']}\" обсуждение";
        }
    }
    
    // Ограничиваем до 60 запросов для экономии
    $seedPrompts = array_slice(array_unique($seedPrompts), 0, 60);
    
    // Выполняем поиск через OpenAI
    $requestUrl = 'https://api.openai.com/v1/responses';
    $requestHeaders = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Expect:'
    ];
    
    $schema = [
        'type' => 'object',
        'properties' => [
            'domains' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string'],
                        'score' => ['type' => 'number'],
                        'lang_hint' => ['type' => 'string'],
                        'sample_urls' => ['type' => 'array', 'items' => ['type' => 'string']]
                    ],
                    'required' => ['domain', 'score'],
                    'additionalProperties' => false
                ]
            ]
        ],
        'required' => ['domains'],
        'additionalProperties' => false
    ];
    
    $sysPrompt = "Ты агент поиска доменов форумов. Используй web_search чтобы найти домены форумов по запросам.\n"
               . "Возвращай ТОЛЬКО JSON: {\"domains\":[{\"domain\":\"example.com\",\"score\":5.2,\"lang_hint\":\"ru\",\"sample_urls\":[\"...\"]}]}.\n"
               . "Score = количество свежих результатов + форумные паттерны в URL (/forum/, /topic/, /thread/, etc).\n"
               . "Исключи домены: " . implode(', ', $settings['exclude_domains']) . "\n"
               . "Верни топ 20-30 доменов с наибольшим score.";
    
    $userPrompt = "Найди домены форумов по этим запросам:\n" . implode("\n", $seedPrompts) . "\n\nВерни уникальные домены с score.";
    
    $payload = [
        'model' => $model,
        'max_output_tokens' => 4096,
        'input' => [
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'domain_seeding',
                'schema' => $schema,
                'strict' => true
            ]
        ],
        'tools' => [['type' => 'web_search']],
        'tool_choice' => 'auto'
    ];
    
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HEADER => true
    ]);
    
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $status = (int)($info['http_code'] ?? 0);
    $body = substr((string)$resp, (int)($info['header_size'] ?? 0));
    curl_close($ch);
    
    orchLog('Domain seeding OpenAI response', ['status' => $status, 'body_length' => strlen($body)]);
    
    if ($status !== 200) {
        return ['ok' => false, 'error' => 'OpenAI request failed', 'status' => $status];
    }
    
    // Парсим результат
    $domains = [];
    $responseData = json_decode($body, true);
    
    if (is_array($responseData)) {
        // Извлекаем из responses format
        $text = '';
        if (isset($responseData['output_text'])) {
            $text = $responseData['output_text'];
        } elseif (isset($responseData['output']) && is_array($responseData['output'])) {
            foreach ($responseData['output'] as $out) {
                if (($out['type'] ?? '') === 'message' && isset($out['content'])) {
                    foreach ($out['content'] as $c) {
                        if (($c['type'] ?? '') === 'output_text') {
                            $text .= $c['text'] ?? '';
                        }
                    }
                }
            }
        }
        
        $domainsData = json_decode($text, true);
        if (isset($domainsData['domains']) && is_array($domainsData['domains'])) {
            $domains = $domainsData['domains'];
        }
    }
    
    // Сохраняем в БД
    $saved = 0;
    foreach ($domains as $domainData) {
        $domain = trim($domainData['domain'] ?? '');
        if (empty($domain)) continue;
        
        $domain = preg_replace('~^https?://~i', '', $domain);
        $domain = preg_replace('~^www\.~i', '', $domain);
        $domain = strtolower($domain);
        
        if (in_array($domain, $settings['exclude_domains'])) continue;
        
        $score = (float)($domainData['score'] ?? 0);
        $langHint = trim($domainData['lang_hint'] ?? '');
        $region = '';
        
        // Определяем регион по TLD
        if (preg_match('/\.([a-z]{2})$/i', $domain, $m)) {
            $region = strtoupper($m[1]);
        }
        
        try {
            $stmt = pdo()->prepare("
                INSERT INTO domains (domain, lang_hint, region, score, is_paused, created_at) 
                VALUES (?, ?, ?, ?, 0, NOW())
                ON DUPLICATE KEY UPDATE 
                    score = GREATEST(score, VALUES(score)),
                    lang_hint = COALESCE(NULLIF(lang_hint, ''), VALUES(lang_hint)),
                    region = COALESCE(NULLIF(region, ''), VALUES(region))
            ");
            $stmt->execute([$domain, $langHint, $region, $score]);
            $saved++;
        } catch (Throwable $e) {
            orchLog('Failed to save domain', ['domain' => $domain, 'error' => $e->getMessage()]);
        }
    }
    
    orchLog('Domain seeding completed', ['found' => count($domains), 'saved' => $saved]);
    
    return ['ok' => true, 'found' => count($domains), 'saved' => $saved];
}

/**
 * Периодический поиск по семплированным доменам
 */
function run_orchestrated_scan(): array {
    $settings = [
        'topic' => (string)get_setting('orchestration_topic', ''),
        'sources' => json_decode((string)get_setting('orchestration_sources', '["forums"]'), true) ?: ['forums'],
        'languages' => json_decode((string)get_setting('orchestration_languages', '["ru"]'), true) ?: ['ru'],
        'freshness_window_hours' => (int)get_setting('orchestration_freshness_window_hours', 72),
        'per_domain_limit' => (int)get_setting('orchestration_per_domain_limit', 5),
        'total_limit' => (int)get_setting('orchestration_total_limit', 50)
    ];
    
    if (empty($settings['topic'])) {
        return ['ok' => false, 'error' => 'Topic not configured'];
    }
    
    // Создаем запись о запуске
    $runStmt = pdo()->prepare("INSERT INTO runs (started_at, status) VALUES (NOW(), 'started')");
    $runStmt->execute();
    $runId = (int)pdo()->lastInsertId();
    
    $lastRun = (string)get_setting('orchestration_last_run', '');
    $windowFrom = $lastRun ? date('Y-m-d H:i:s', strtotime($lastRun)) : date('Y-m-d H:i:s', strtotime("-{$settings['freshness_window_hours']} hours"));
    $windowTo = date('Y-m-d H:i:s');
    
    // Обновляем окно в записи run
    $updateRunStmt = pdo()->prepare("UPDATE runs SET window_from = ?, window_to = ? WHERE id = ?");
    $updateRunStmt->execute([$windowFrom, $windowTo, $runId]);
    
    orchLog('Starting orchestrated scan', [
        'run_id' => $runId,
        'window_from' => $windowFrom,
        'window_to' => $windowTo,
        'settings' => $settings
    ]);
    
    // Получаем активные домены
    $activeDomains = pdo()->query("
        SELECT id, domain, lang_hint, region, score 
        FROM domains 
        WHERE is_paused = 0 
        ORDER BY score DESC, last_scan_at ASC 
        LIMIT 50
    ")->fetchAll();
    
    if (empty($activeDomains)) {
        pdo()->prepare("UPDATE runs SET status = 'completed', finished_at = NOW(), found_count = 0 WHERE id = ?")
            ->execute([$runId]);
        return ['ok' => false, 'error' => 'No active domains found'];
    }
    
    $apiKey = (string)get_setting('openai_api_key', '');
    $model = (string)get_setting('openai_model', 'gpt-5-mini');
    
    if (empty($apiKey)) {
        return ['ok' => false, 'error' => 'OpenAI API key not configured'];
    }
    
    $totalFound = 0;
    $newTopics = 0;
    
    // Обрабатываем домены порциями
    $processedDomains = 0;
    foreach ($activeDomains as $domain) {
        if ($totalFound >= $settings['total_limit']) break;
        
        $domainResults = scan_domain_for_topics($domain, $settings, $windowFrom, $windowTo, $apiKey, $model);
        
        if ($domainResults['ok']) {
            $found = $domainResults['found'];
            $new = $domainResults['new'];
            
            $totalFound += $found;
            $newTopics += $new;
            
            // Обновляем last_scan_at для домена
            pdo()->prepare("UPDATE domains SET last_scan_at = NOW() WHERE id = ?")
                ->execute([$domain['id']]);
            
            orchLog('Domain scanned', [
                'domain' => $domain['domain'],
                'found' => $found,
                'new' => $new
            ]);
        }
        
        $processedDomains++;
        
        // Небольшая пауза между доменами
        if ($processedDomains % 5 === 0) {
            sleep(2);
        }
    }
    
    // Завершаем запуск
    pdo()->prepare("UPDATE runs SET status = 'completed', finished_at = NOW(), found_count = ? WHERE id = ?")
        ->execute([$totalFound, $runId]);
    
    set_setting('orchestration_last_run', $windowTo);
    
    orchLog('Orchestrated scan completed', [
        'run_id' => $runId,
        'processed_domains' => $processedDomains,
        'total_found' => $totalFound,
        'new_topics' => $newTopics
    ]);
    
    // Отправляем уведомление если есть новые результаты
    if ($newTopics > 0) {
        send_orchestration_notification($newTopics, $totalFound, $processedDomains);
    }
    
    return [
        'ok' => true,
        'run_id' => $runId,
        'processed_domains' => $processedDomains,
        'total_found' => $totalFound,
        'new_topics' => $newTopics
    ];
}

/**
 * Сканирование одного домена для поиска тем
 */
function scan_domain_for_topics(array $domain, array $settings, string $windowFrom, string $windowTo, string $apiKey, string $model): array {
    $requestUrl = 'https://api.openai.com/v1/responses';
    $requestHeaders = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'Expect:'
    ];
    
    $schema = [
        'type' => 'object',
        'properties' => [
            'topics' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'published_at' => ['type' => 'string'],
                        'author' => ['type' => 'string'],
                        'snippet' => ['type' => 'string']
                    ],
                    'required' => ['url', 'title'],
                    'additionalProperties' => false
                ]
            ]
        ],
        'required' => ['topics'],
        'additionalProperties' => false
    ];
    
    $sysPrompt = "Ты агент поиска тем на форуме. Используй web_search для поиска ТОЛЬКО на домене {$domain['domain']}.\n"
               . "Возвращай ТОЛЬКО JSON: {\"topics\":[{\"url\":\"...\",\"title\":\"...\",\"published_at\":\"2024-12-01\",\"author\":\"...\",\"snippet\":\"...\"}]}.\n"
               . "Ищи темы/треды созданные после {$windowFrom}.\n"
               . "Максимум {$settings['per_domain_limit']} результатов.\n"
               . "Используй site:{$domain['domain']} в запросах.";
    
    $userPrompt = "Найди на {$domain['domain']} темы про: {$settings['topic']}\n"
                . "Период: после {$windowFrom}\n"
                . "Языки: " . implode(', ', $settings['languages']) . "\n"
                . "Возвращай уникальные темы/треды с полными URL.";
    
    $payload = [
        'model' => $model,
        'max_output_tokens' => 2048,
        'input' => [
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'domain_topics',
                'schema' => $schema,
                'strict' => false // более гибко для разных форматов
            ]
        ],
        'tools' => [['type' => 'web_search']],
        'tool_choice' => 'auto'
    ];
    
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HEADER => true
    ]);
    
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $status = (int)($info['http_code'] ?? 0);
    $body = substr((string)$resp, (int)($info['header_size'] ?? 0));
    curl_close($ch);
    
    if ($status !== 200) {
        return ['ok' => false, 'error' => 'API request failed', 'status' => $status];
    }
    
    // Парсим результат
    $topics = [];
    $responseData = json_decode($body, true);
    
    if (is_array($responseData)) {
        $text = '';
        if (isset($responseData['output_text'])) {
            $text = $responseData['output_text'];
        } elseif (isset($responseData['output']) && is_array($responseData['output'])) {
            foreach ($responseData['output'] as $out) {
                if (($out['type'] ?? '') === 'message' && isset($out['content'])) {
                    foreach ($out['content'] as $c) {
                        if (($c['type'] ?? '') === 'output_text') {
                            $text .= $c['text'] ?? '';
                        }
                    }
                }
            }
        }
        
        $topicsData = json_decode($text, true);
        if (isset($topicsData['topics']) && is_array($topicsData['topics'])) {
            $topics = $topicsData['topics'];
        }
    }
    
    // Сохраняем темы
    $found = 0;
    $new = 0;
    
    foreach ($topics as $topicData) {
        $url = trim($topicData['url'] ?? '');
        $title = trim($topicData['title'] ?? '');
        
        if (empty($url) || empty($title)) continue;
        
        $publishedAt = $topicData['published_at'] ?? null;
        if ($publishedAt && !preg_match('/^\d{4}-\d{2}-\d{2}/', $publishedAt)) {
            $publishedAt = null; // некорректная дата
        }
        
        $author = trim($topicData['author'] ?? '');
        $snippet = trim($topicData['snippet'] ?? '');
        
        // Создаем hash для дедупликации
        $normalizedUrl = preg_replace('/[?#].*$/', '', $url); // убираем параметры
        $titleStart = mb_substr($title, 0, 200);
        $seenHash = sha1($normalizedUrl . '|' . $titleStart);
        
        // Базовый скоринг
        $score = 1.0;
        if ($publishedAt && strtotime($publishedAt) > strtotime('-24 hours')) {
            $score += 2.0; // свежесть
        }
        if (stripos($title, $settings['topic']) !== false) {
            $score += 1.5; // точное совпадение в заголовке
        }
        if (stripos($url, 'forum') !== false || stripos($url, 'topic') !== false) {
            $score += 0.5; // форумные паттерны
        }
        
        try {
            $stmt = pdo()->prepare("
                INSERT INTO topics (domain_id, title, url, published_at, author, snippet, score, seen_hash, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title),
                    score = GREATEST(score, VALUES(score))
            ");
            $stmt->execute([
                $domain['id'],
                $title,
                $url,
                $publishedAt,
                $author ?: null,
                $snippet ?: null,
                $score,
                $seenHash
            ]);
            
            $found++;
            if ($stmt->rowCount() > 0) {
                $new++; // новая запись
            }
        } catch (Throwable $e) {
            // Дубликат или ошибка - пропускаем
        }
    }
    
    return ['ok' => true, 'found' => $found, 'new' => $new];
}

/**
 * Отправка уведомления о новых результатах
 */
function send_orchestration_notification(int $newTopics, int $totalFound, int $processedDomains): void {
    $tgToken = (string)get_setting('telegram_token', '');
    $tgChat = (string)get_setting('telegram_chat_id', '');
    
    if (empty($tgToken) || empty($tgChat)) {
        return;
    }
    
    $topic = (string)get_setting('orchestration_topic', '');
    
    $message = "🎯 Оркестрация: новые результаты\n\n";
    $message .= "📋 Тема: " . mb_substr($topic, 0, 100) . "\n";
    $message .= "🆕 Новых тем: {$newTopics}\n";
    $message .= "📊 Всего найдено: {$totalFound}\n";
    $message .= "🌐 Обработано доменов: {$processedDomains}\n";
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
    curl_exec($ch);
    curl_close($ch);
}

// Выполнение действия
$result = ['ok' => false, 'error' => 'Unknown action'];

if ($action === 'seed_domains') {
    $result = run_seed_domains();
} elseif ($action === 'scan') {
    $result = run_orchestrated_scan();
}

// Ответ
if ($isManual) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<h3>Результат: {$action}</h3>";
    echo "<pre>" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "</pre>";
    echo "<br><a href='monitoring_dashboard.php'>← Вернуться к панели</a>";
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}
?>