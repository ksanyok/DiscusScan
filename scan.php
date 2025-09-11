<?php
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/db.php';

$UA = 'DiscusScan/' . (defined('APP_VERSION') ? APP_VERSION : 'dev');

// ----------------------- Helpers -----------------------
function normalize_host(string $host): string {
    $host = strtolower(trim($host));
    $host = preg_replace('~^https?://~i', '', $host);
    return preg_replace('~^www\.~i', '', $host);
}

/** Canonicalize URL: scheme+host+path + cleaned query (drop tracking), no trailing slash. */
function canonicalize_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    $p = @parse_url($url);
    if (!$p || empty($p['host'])) return $url;

    $scheme = $p['scheme'] ?? 'https';
    $host   = $p['host'] ?? '';
    $host   = strtolower($host);
    $path   = $p['path'] ?? '/';
    $path   = preg_replace('~//+~', '/', $path);

    // remove tracking params
    $qs = [];
    if (!empty($p['query'])) {
        parse_str($p['query'], $qs);
        $qs = array_filter(
            $qs,
            fn($k) => !preg_match('~^(utm_|ref|ref_|fbclid|gclid|yclid|_hs|mc_|aff|aff_id|source|from|igshid|si)~i', $k),
            ARRAY_FILTER_USE_KEY
        );
    }
    $query = http_build_query($qs);

    $canon = $scheme . '://' . $host . $path . ($query ? '?' . $query : '');
    return rtrim($canon, '/');
}

function arr_get(array $a, string $k, $d=null){ return $a[$k] ?? $d; }

function compute_since_datetime(array $settings, PDO $pdo): string {
    $base = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P' . max(1, (int)($settings['freshness_days'] ?? 7)) . 'D'));
    // Optional: use MAX(published_at) - 60 minutes buffer
    // $stmt = $pdo->query("SELECT MAX(published_at) AS maxp FROM links");
    // $row = $stmt->fetch(PDO::FETCH_ASSOC);
    // if (!empty($row['maxp'])) {
    //     try { $base = (new DateTimeImmutable($row['maxp'], new DateTimeZone('UTC')))->sub(new DateInterval('PT60M')); } catch (Throwable $e) {}
    // }
    return $base->format('Y-m-d\TH:i:s\Z');
}

function getPausedHosts(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT host FROM sources WHERE COALESCE(is_paused,0)=1 ORDER BY host LIMIT 400")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_unique(array_map('normalize_host', $rows)));
    } catch (Throwable $e) { return []; }
}
function getEnabledHosts(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT host FROM sources WHERE COALESCE(is_enabled,1)=1 AND COALESCE(is_paused,0)=0 ORDER BY host LIMIT 800")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_unique(array_map('normalize_host', $rows)));
    } catch (Throwable $e) { return []; }
}
function getSeenPathFingerprints(PDO $pdo, int $limit = 300): array {
    try {
        $st = $pdo->prepare("SELECT url FROM links ORDER BY id DESC LIMIT :lim");
        $st->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $st->execute();
        $fps = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $u = (string)$row['url'];
            $h = normalize_host(parse_url($u, PHP_URL_HOST) ?: '');
            $p = parse_url($u, PHP_URL_PATH) ?? '/';
            if ($p === '') $p = '/';
            if ($h === '') continue;
            $fps[$h . '|' . $p] = 1;
        }
        return array_values(array_keys($fps));
    } catch (Throwable $e) { return []; }
}

// ----------------------- Access guards -----------------------
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    if (isset($_GET['manual'])) {
        if (function_exists('require_login')) {
            require_login();
        }
    } else {
        $secret = $_GET['secret'] ?? '';
        if ($secret !== (string)get_setting('cron_secret', '')) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }
    }
}

// Mark manual runs to bypass the period guard
$isManual = (!$isCli && isset($_GET['manual']));

// ----------------------- Period guard -----------------------
if (!$isManual) {
    $periodMin = (int)get_setting('scan_period_min', 15);
    $lastScanRow = pdo()->query("SELECT finished_at FROM scans ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($lastScanRow) {
        $diff = time() - strtotime($lastScanRow);
        if ($diff < $periodMin * 60) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'period_guard', 'last_scan_at' => $lastScanRow, 'period_min' => $periodMin], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// ----------------------- Settings -----------------------
$apiKey = (string)get_setting('openai_api_key', '');
$model  = (string)get_setting('openai_model', 'gpt-5-mini');
$basePrompt = (string)get_setting('search_prompt', '');
if ($apiKey === '' || $basePrompt === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Missing OpenAI API key or search prompt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$settings = [
    'freshness_days' => (int)get_setting('freshness_days', 7),
    'enabled_sources_only' => (bool)get_setting('enabled_sources_only', true),
    'max_results_per_scan' => (int)get_setting('max_results_per_scan', 80),
    'return_schema_required' => (bool)get_setting('return_schema_required', true),
    'languages' => (array)get_setting('languages', []),
    'regions' => (array)get_setting('regions', []),
];

// NEW: HTTP and Discovery settings
$OPENAI_HTTP_TIMEOUT = (int)get_setting('openai_timeout_sec', 300);
$HTTP_TIMEOUT_SEC    = (int)get_setting('http_timeout_sec', 20);
$MAX_PAR_HTTP        = (int)get_setting('max_parallel_http', 12);
$DISCOVERY_ENABLED   = (bool)get_setting('discovery_enabled', true);
$DISCOVERY_N         = (int)get_setting('discovery_daily_candidates', 20);
$VERIFY_FRESH_DAYS   = (int)get_setting('verify_freshness_days_for_new_domain', 90);

// Scope toggles
$scopeDomains  = (bool)get_setting('scope_domains_enabled', false);
$scopeTelegram = (bool)get_setting('scope_telegram_enabled', false);
$scopeForums   = (bool)get_setting('scope_forums_enabled', true);
$telegramMode  = (string)get_setting('telegram_mode', 'any'); // any|discuss

$sinceIso = compute_since_datetime($settings, pdo());
$sinceDt = new DateTimeImmutable($sinceIso, new DateTimeZone('UTC'));

// ----------------------- DB: start scan row -----------------------
$scanId = null;
try {
    $ins = pdo()->prepare("INSERT INTO scans (started_at, status, model, prompt) VALUES (NOW(), 'started', ?, ?)");
    $ins->execute([$model, $basePrompt]);
    $scanId = (int)pdo()->lastInsertId();
} catch (Throwable $e) {}

// ----------------------- OpenAI client helpers -----------------------
$MAX_OUTPUT_TOKENS   = (int)get_setting('openai_max_output_tokens', 4096);
// $OPENAI_HTTP_TIMEOUT set above

$requestUrl = 'https://api.openai.com/v1/responses';
$requestHeaders = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
];

$schema = [
    'type' => 'object',
    'properties' => [
        'links' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'domain' => ['type' => 'string'],
                    'published_at' => ['type' => 'string'],
                    'why' => ['type' => 'string']
                ],
                'required' => ['url','title','domain','published_at','why'],
                'additionalProperties' => false
            ]
        ]
    ],
    'required' => ['links'],
    'additionalProperties' => false
];

// NEW: Discovery and Classifier schemas
$discoverySchema = [
    'type' => 'object',
    'properties' => [
        'sources' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'domain' => ['type' => 'string'],
                    'proof_url' => ['type' => 'string'],
                    'platform_guess' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                    'activity_hint' => ['type' => 'string']
                ],
                'required' => ['domain','proof_url','platform_guess','reason','activity_hint'],
                'additionalProperties' => false
            ]
        ]
    ],
    'required' => ['sources'],
    'additionalProperties' => false
];
$classifierSchema = [
    'type' => 'object',
    'properties' => [
        'is_discussion' => ['type' => 'boolean'],
        'score' => ['type' => 'number'],
        'reason' => ['type' => 'string']
    ],
    'required' => ['is_discussion','score','reason'],
    'additionalProperties' => false
];

// NEW: Prompt constants
if (!defined('DISCOVERY_SYSTEM_PROMPT')) {
    define('DISCOVERY_SYSTEM_PROMPT', "You are a source discovery agent. Return STRICT JSON only (no prose).\nGoal: propose EXACTLY N new discussion communities (forums/Q&A/support) for the given TOPIC.\nEach item MUST include:\n- domain (registrable)\n- proof_url (page showing threads/topics/categories)\n- platform_guess (discourse/phpbb/vbulletin/ips/vanilla/flarum/wp-forum/github/stackexchange/unknown)\n- reason (short)\n- activity_hint (short freshness hint)\nExclude ALL domains from EXCLUDED_DOMAINS. Prefer active communities with recent topics.\nNo marketing pages, no static blogs, no home pages without threads.");
}
if (!defined('CLASSIFIER_SYSTEM_PROMPT')) {
    define('CLASSIFIER_SYSTEM_PROMPT', "You are a content classifier. Return STRICT JSON only.\nDecide if the given page is a *discussion thread or listing of discussions* relevant to the topic.");
}

// Mask api key in logs
$maskKey = function (string $k): string {
    if ($k === '') return '';
    $len = strlen($k);
    if ($len <= 10) return substr($k, 0, 2) . '...' . substr($k, -2);
    return substr($k, 0, 6) . '...' . substr($k, -4);
};

// Minimal logs: request + raw response body (pretty if JSON)
$appLog = function (string $title, array $kv = [], ?string $body = null) {
    $buf = "\n[" . date('Y-m-d H:i:s') . "] " . $title . "\n";
    foreach ($kv as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $buf .= $k . ': ' . ($v ?? 'null') . "\n";
        } else {
            $buf .= $k . ":\n" . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
    }
    if ($body !== null) {
        // pretty print if JSON
        $pretty = $body;
        $trim = ltrim($body);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $dec = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $pretty = json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }
        $buf .= $pretty . "\n";
    }
    $buf .= str_repeat('-', 80) . "\n";
    @file_put_contents(APP_LOG, $buf, FILE_APPEND);
};

function extract_json_links_from_chat_completion(string $body): array {
    $data = json_decode($body, true);
    if (!is_array($data)) return [];
    
    // Standard OpenAI Chat Completions response format
    if (isset($data['choices'][0]['message']['content'])) {
        $content = (string)$data['choices'][0]['message']['content'];
        
        // Try to parse as JSON directly
        $json = json_decode($content, true);
        if (is_array($json) && isset($json['links']) && is_array($json['links'])) {
            return $json['links'];
        }
        
        // Try to extract JSON from text if wrapped
        if (preg_match('~\{.*?\}~s', $content, $matches)) {
            $json = json_decode($matches[0], true);
            if (is_array($json) && isset($json['links']) && is_array($json['links'])) {
                return $json['links'];
            }
        }
    }
    
    // Fallback: if the response itself contains links array
    if (isset($data['links']) && is_array($data['links'])) {
        return $data['links'];
    }
    
    return [];
}

function extract_json_links_from_responses(string $body): array {
    $data = json_decode($body, true);
    $text = '';

    if (is_array($data)) {
        // New: prefer parsed JSON when Responses JSON schema is used
        if (isset($data['output_parsed'])) {
            $parsed = $data['output_parsed'];
            if (is_array($parsed) && isset($parsed['links']) && is_array($parsed['links'])) {
                return $parsed['links'];
            }
            if (is_array($parsed) && isset($parsed[0]['links']) && is_array($parsed[0]['links'])) {
                return $parsed[0]['links'];
            }
        }
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                if (isset($out['parsed'])) {
                    $p = $out['parsed'];
                    if (is_array($p) && isset($p['links']) && is_array($p['links'])) {
                        return $p['links'];
                    }
                }
            }
        }
        // 1) Newer responses format: top-level output_text
        if (isset($data['output_text']) && is_string($data['output_text'])) {
            $text .= (string)$data['output_text'];
        }
        // 2) Walk output array for assistant message content
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $out) {
                if (($out['type'] ?? '') === 'message' && isset($out['content']) && is_array($out['content'])) {
                    foreach ($out['content'] as $c) {
                        if (($c['type'] ?? '') === 'output_text' && isset($c['text'])) {
                            $text .= (string)$c['text'];
                        }
                    }
                }
            }
        }
        // 3) Some variants place text at content[0].text
        if ($text === '' && isset($data['content'][0]['text'])) {
            $text = (string)$data['content'][0]['text'];
        }
        // 4) As a last resort, maybe the body *is* the JSON we want
        if ($text === '' && isset($data['links']) && is_array($data['links'])) {
            return $data['links'];
        }
    }

    // Try to decode JSON from the assistant text
    $json = json_decode((string)$text, true);
    if (!is_array($json)) {
        if (preg_match('~\{.*\}~s', (string)$text, $m)) {
            $json = json_decode($m[0], true);
        }
    }
    if (!is_array($json) || !isset($json['links']) || !is_array($json['links'])) {
        return [];
    }
    return $json['links'];
}

// NEW: extractor for discovery
function extract_json_sources_from_responses(string $body): array {
    $data = json_decode($body, true);
    if (isset($data['output_parsed'][0]['sources']) && is_array($data['output_parsed'][0]['sources'])) {
        return $data['output_parsed'][0]['sources'];
    }
    if (isset($data['output_parsed']['sources']) && is_array($data['output_parsed']['sources'])) {
        return $data['output_parsed']['sources'];
    }
    if (isset($data['output'][0]['parsed']['sources']) && is_array($data['output'][0]['parsed']['sources'])) {
        return $data['output'][0]['parsed']['sources'];
    }
    // Try text
    $text = $data['output_text'] ?? '';
    if (is_string($text)) {
        $j = json_decode($text, true);
        if (isset($j['sources']) && is_array($j['sources'])) return $j['sources'];
    }
    return [];
}

// ----------------------- OpenAI call with retry logic -----------------------
function run_openai_job(string $jobName, string $sys, string $user, string $requestUrl, array $requestHeaders, array $schema, int $maxTokens, int $timeout, callable $log): array {
    global $UA;
    $model = (string)get_setting('openai_model', 'gpt-5-mini');
    $strictRequired = (bool)get_setting('return_schema_required', true);
    $enableWeb = (bool)get_setting('openai_enable_web_search', false);

    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [ ['type' => 'input_text', 'text' => $sys] ]
            ],
            [
                'role' => 'user',
                'content' => [ ['type' => 'input_text', 'text' => $user] ]
            ]
        ],
        'max_output_tokens' => $maxTokens,
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'monitoring_output',
                'schema' => $schema,
                'strict' => $strictRequired
            ]
        ]
    ];

    // Enable OpenAI web search tool only if allowed
    if ($enableWeb) {
        $payload['tools'] = [ ['type' => 'web_search'] ];
        $payload['tool_choice'] = 'auto';
    }

    // Retry логика для обработки временных сбоев API
    $maxRetries = 3;
    $baseDelay = 1; // seconds
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $ch = curl_init($requestUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $UA
        ]);
        
        $resp = curl_exec($ch);
        $info = curl_getinfo($ch);
        $status = (int)($info['http_code'] ?? 0);
        $headerSize = (int)($info['header_size'] ?? 0);
        $headersRaw = substr((string)$resp, 0, $headerSize);
        $body = substr((string)$resp, $headerSize);
        $curlError = curl_error($ch);
        curl_close($ch);

        $log("OpenAI REQUEST [{$jobName}] (attempt {$attempt})", [ 
            'url' => $requestUrl, 
            'model' => $model, 
            'max_output_tokens' => $maxTokens 
        ], json_encode($payload, JSON_UNESCAPED_UNICODE));
        
        $log("OpenAI RESPONSE [{$jobName}] (attempt {$attempt})", [ 
            'status' => $status, 
            'content_type' => $info['content_type'] ?? null, 
            'curl_error' => $curlError 
        ], (string)$body);

        // Проверяем на временные ошибки (rate limit, server errors)
        $isRetryable = in_array($status, [429, 500, 502, 503, 504]) || !empty($curlError);

        // Разбор Retry-After
        $retryAfterSecs = null;
        if ($isRetryable && $headersRaw !== '') {
            if (preg_match('~^Retry-After:\s*(.+)$~im', $headersRaw, $m)) {
                $val = trim($m[1]);
                if (ctype_digit($val)) {
                    $retryAfterSecs = (int)$val;
                } else {
                    $ts = strtotime($val);
                    if ($ts) { $retryAfterSecs = max(0, $ts - time()); }
                }
            }
        }
        
        if ($status === 200) {
            // Success - return raw body for caller parsing; also try to infer links here if schema matches
            $links = extract_json_links_from_responses((string)$body);
            return [$status, count($links), $links, (string)$body];
        }
        
        if (!$isRetryable || $attempt === $maxRetries) {
            // Не ретраимся или это последняя попытка
            $log("OpenAI ERROR [{$jobName}] - Final attempt", [
                'status' => $status,
                'curl_error' => $curlError,
                'attempt' => $attempt,
                'retry_after' => $retryAfterSecs
            ]);
            return [$status, 0, [], ''];
        }
        
        // Экспоненциальная задержка перед повторной попыткой + учет Retry-After + джиттер
        $delay = $baseDelay * pow(2, $attempt - 1);
        if ($retryAfterSecs !== null) {
            $delay = max($delay, (float)$retryAfterSecs);
        }
        $jitter = random_int(50, 250) / 1000; // 50..250 ms
        $delayWithJitter = $delay + $jitter;

        $log("OpenAI RETRY [{$jobName}]", [
            'status' => $status,
            'attempt' => $attempt,
            'retry_after' => $retryAfterSecs,
            'base_delay' => $delay,
            'jitter' => $jitter,
            'sleep' => $delayWithJitter
        ]);

        $sec = (int)floor($delayWithJitter);
        $usec = (int)round(($delayWithJitter - $sec) * 1_000_000);
        if ($sec > 0) sleep($sec);
        if ($usec > 0) usleep($usec);
    }

    return [$status ?? 0, 0, [], ''];
}

// ===================== [FETCH] =========================
function http_multi_get(array $urls, int $timeoutSec, int $concurrency = 8): array {
    global $UA;
    $urls = array_values(array_unique(array_filter($urls)));
    $res = [];
    if (!$urls) return $res;
    $queue = $urls;
    $active = null; $mh = curl_multi_init();
    $handles = [];
    $startReq = function(string $u) use (&$handles, $mh, $timeoutSec) {
        global $UA;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $u,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => $UA,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = $u;
    };
    // Prime
    for ($i=0; $i<min($concurrency, count($queue)); $i++) { $startReq(array_shift($queue)); }
    do {
        curl_multi_exec($mh, $active);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $u = $handles[(int)$ch] ?? '';
            $content = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headersRaw = substr((string)$content, 0, (int)$hsz);
            $body = substr((string)$content, (int)$hsz);
            $res[$u] = [
                'status' => (int)$code,
                'headers' => $headersRaw,
                'body' => (string)$body,
                'content_type' => curl_getinfo($ch, CURLINFO_CONTENT_TYPE)
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[(int)$ch]);
            if ($queue) { $startReq(array_shift($queue)); }
        }
        if ($active) curl_multi_select($mh, 1.0);
    } while ($active || $handles);
    curl_multi_close($mh);
    return $res;
}

function parse_date_to_utc_sql(?string $s): ?string {
    if (!$s) return null;
    $s = trim($s);
    // Try DateTimeImmutable first
    try {
        $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {}
    $ts = strtotime($s);
    if ($ts) return gmdate('Y-m-d H:i:s', $ts);
    return null;
}

function parse_feed_items(string $baseUrl, string $body, ?string $ctype): array {
    $items = [];
    $low = strtolower((string)$ctype);
    // JSON: Discourse / Flarum
    if (str_contains($low, 'application/json') || str_ends_with(strtolower(parse_url($baseUrl, PHP_URL_PATH) ?? ''), '.json')) {
        $j = json_decode($body, true);
        if (isset($j['topic_list']['topics'])) { // Discourse latest.json
            foreach ($j['topic_list']['topics'] as $t) {
                $slug = $t['slug'] ?? '';
                $id = $t['id'] ?? null;
                $ts = $t['bumped_at'] ?? ($t['created_at'] ?? null);
                if ($id && $slug && $ts) {
                    $host = parse_url($baseUrl, PHP_URL_HOST);
                    $items[] = [
                        'url' => 'https://' . $host . '/t/' . $slug . '/' . $id,
                        'title' => (string)($t['title'] ?? ''),
                        'published_at' => parse_date_to_utc_sql($ts)
                    ];
                }
            }
        } elseif (isset($j['data'][0]['attributes'])) { // Flarum
            foreach ($j['data'] as $d) {
                $attrs = $d['attributes'] ?? [];
                $ts = $attrs['lastPostedAt'] ?? ($attrs['createdAt'] ?? null);
                $title = $attrs['title'] ?? '';
                $id = $d['id'] ?? null;
                if ($id && $ts) {
                    $host = parse_url($baseUrl, PHP_URL_HOST);
                    $items[] = [
                        'url' => 'https://' . $host . '/d/' . $id,
                        'title' => (string)$title,
                        'published_at' => parse_date_to_utc_sql($ts)
                    ];
                }
            }
        }
        return array_values(array_filter($items, fn($x)=>!empty($x['published_at'])));
    }
    // RSS/Atom (simple)
    if (preg_match('~<rss|<feed~i', $body)) {
        // naive item extraction to avoid heavy XML libs
        if (preg_match_all('~<item[\s>].*?</item>~is', $body, $m)) {
            foreach ($m[0] as $it) {
                preg_match('~<title>(.*?)</title>~is', $it, $mt);
                preg_match('~<link>(.*?)</link>~is', $it, $ml);
                preg_match('~<pubDate>(.*?)</pubDate>~is', $it, $md);
                $items[] = [
                    'url' => trim(html_entity_decode($ml[1] ?? '')),
                    'title' => trim(html_entity_decode($mt[1] ?? '')),
                    'published_at' => parse_date_to_utc_sql($md[1] ?? '')
                ];
            }
        } else if (preg_match_all('~<entry[\s>].*?</entry>~is', $body, $m2)) { // Atom
            foreach ($m2[0] as $it) {
                preg_match('~<title[^>]*>(.*?)</title>~is', $it, $mt);
                preg_match('~<link[^>]+href=\"([^\"]+)\"~is', $it, $ml);
                preg_match('~<(updated|published)[^>]*>(.*?)</\1>~is', $it, $md);
                $items[] = [
                    'url' => trim(html_entity_decode($ml[1] ?? '')),
                    'title' => trim(html_entity_decode($mt[1] ?? '')),
                    'published_at' => parse_date_to_utc_sql($md[2] ?? '')
                ];
            }
        }
        return array_values(array_filter($items, fn($x)=>!empty($x['published_at']) && !empty($x['url'])));
    }
    // StackExchange/HTML (very light)
    if (str_contains($low, 'text/html')) {
        if (preg_match_all('~<a[^>]+href=\"([^\"]+)\"[^>]*class=\"question-hyperlink\"[^>]*>(.*?)</a>~is', $body, $mm)) {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            foreach ($mm[1] as $i => $href) {
                $title = strip_tags($mm[2][$i] ?? '');
                // time
                preg_match_all('~<time[^>]+datetime=\"([^\"]+)\"~is', $body, $mt);
                $dt = $mt[1][0] ?? '';
                $items[] = [
                    'url' => (str_starts_with($href, 'http') ? $href : ('https://' . $host . $href)),
                    'title' => $title,
                    'published_at' => parse_date_to_utc_sql($dt)
                ];
            }
            return array_values(array_filter($items, fn($x)=>!empty($x['published_at'])));
        }
    }
    return [];
}

// ===================== [VERIFY] ========================
function detect_platform(string $host, ?string $proofUrl = null): string {
    $h = strtolower($host);
    $pu = strtolower((string)$proofUrl);
    if (str_contains($pu, '/latest') || str_contains($pu, 'discourse')) return 'discourse';
    if (str_contains($pu, 'feed.php') || str_contains($pu, 'ucp.php') || str_contains($pu, 'viewtopic.php')) return 'phpbb';
    if (str_contains($pu, 'external.php?type=rss') || str_contains($pu, 'vbulletin')) return 'vbulletin';
    if (str_contains($pu, 'invisioncommunity') || str_contains($pu, 'ips')) return 'ips';
    if (str_contains($pu, '/discussions')) return 'vanilla';
    if (str_contains($pu, '/api/discussions')) return 'flarum';
    if (preg_match('~github\.com$~', $h)) return 'github';
    if (preg_match('~stackexchange\.com$|stackoverflow\.com$~', $h)) return 'stackexchange';
    if (str_contains($pu, 'support') || str_contains($pu, 'forum')) return 'wp-forum';
    return 'unknown';
}

function discover_feeds_for_platform(string $host, string $platform): array {
    $host = normalize_host($host);
    $base = 'https://' . $host;
    switch ($platform) {
        case 'discourse': return [$base . '/latest.json', $base . '/latest.rss'];
        case 'phpbb': return [$base . '/feed.php'];
        case 'vbulletin': return [$base . '/external.php?type=RSS2'];
        case 'ips': return [$base . '/discover/all.xml', $base . '/rss'];// heuristic
        case 'vanilla': return [$base . '/discussions/feed.rss'];
        case 'flarum': return [$base . '/api/discussions'];
        case 'wp-forum': return [$base . '/feed/', $base . '/support/feed/', $base . '/forum/feed/'];
        case 'github': return [$base . '/issues.atom', $base . '/discussions.atom'];
        case 'stackexchange': return [$base . '/?tab=Newest', $base . '/?tab=Active'];
        default:
            return [$base . '/feed.php', $base . '/latest.rss', $base . '/rss', $base . '/feed/'];
    }
}

// ===================== [CLASSIFY] ======================
function maybe_classify(array $item, array $keywords): bool {
    // placeholder: accept by default; can be expanded to call LLM with classifierSchema
    return true;
}

// ===================== [SCHEDULER] =====================
$preFound = 0; $preNew = 0; $verifiedDomains = 0; $scannedDomains = 0; $discoveredCount = 0;

// ----- Discovery -----
if ($DISCOVERY_ENABLED) {
    $excluded = array_values(array_unique(array_map('normalize_host', db_sources_existing_domains())));
    $basePromptShort = mb_substr($basePrompt, 0, 500);
    $langsTxt = $settings['languages'] ? implode(', ', $settings['languages']) : 'any';
    $regsTxt  = $settings['regions'] ? implode(', ', $settings['regions']) : 'any';
    $user = "TOPIC: {$basePromptShort}\nLANGUAGES: {$langsTxt}\nREGIONS: {$regsTxt}\nN: {$DISCOVERY_N}\nEXCLUDED_DOMAINS: " . json_encode($excluded, JSON_UNESCAPED_UNICODE) . "\nReturn valid JSON strictly matching the schema. No explanations.";

    // Dynamic token budget: avg ~180 tokens per item + overhead
    $avgItemTokens = max(120, (int)get_setting('discovery_avg_item_tokens', 180));
    $overhead = 256;
    $desired = $DISCOVERY_N * $avgItemTokens + $overhead;
    $globalCap = max(1024, (int)get_setting('openai_max_output_tokens', 4096));
    $discoveryMaxTokens = min(8192, max(1024, $desired, $globalCap));

    [$st, $_c, $_, $raw] = run_openai_job('discovery', DISCOVERY_SYSTEM_PROMPT, $user, $requestUrl, $requestHeaders, $discoverySchema, $discoveryMaxTokens, $OPENAI_HTTP_TIMEOUT, $appLog);
    if ($st === 200 && $raw) {
        $srcs = extract_json_sources_from_responses($raw);
        if (!$srcs) {
            try {
                $j = json_decode($raw, true);
                $statusTxt = $j['status'] ?? null;
                $incompleteReason = $j['incomplete_details']['reason'] ?? null;
                app_log('warning', 'discovery', 'No sources parsed from response', [ 'status' => $statusTxt, 'incomplete_reason' => $incompleteReason, 'n' => $DISCOVERY_N, 'max_output_tokens' => $discoveryMaxTokens ]);
            } catch (Throwable $e) {}
        }
        foreach ($srcs as $s) {
            $domain = normalize_host((string)arr_get($s, 'domain', ''));
            $proof = (string)arr_get($s, 'proof_url', '');
            if ($domain === '' || $proof === '') continue;
            if (in_array($domain, $excluded, true)) continue;
            db_upsert_discovered([
                'domain' => $domain,
                'proof_url' => $proof,
                'platform_guess' => (string)arr_get($s, 'platform_guess', 'unknown'),
                'reason' => (string)arr_get($s, 'reason', ''),
                'activity_hint' => (string)arr_get($s, 'activity_hint', ''),
            ]);
            $discoveredCount++;
        }
    }
}

// ----- Verification for newly discovered -----
try {
    $sinceVerifyIso = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->sub(new DateInterval('P' . max(1,$VERIFY_FRESH_DAYS) . 'D'))->format('Y-m-d\TH:i:s\Z');
    $sinceVerifyDt = new DateTimeImmutable($sinceVerifyIso, new DateTimeZone('UTC'));
} catch (Throwable $e) { $sinceVerifyDt = $sinceDt; }
$discRows = pdo()->query("SELECT domain, proof_url, platform_guess FROM discovered_sources WHERE status='new' ORDER BY first_seen_at DESC LIMIT 100")->fetchAll() ?: [];
if ($discRows) {
    $reqs = [];
    foreach ($discRows as $r) {
        $host = normalize_host($r['domain']);
        $plat = detect_platform($host, $r['proof_url'] ?? null);
        $feeds = discover_feeds_for_platform($host, $plat);
        foreach ($feeds as $u) { $reqs[$host][] = $u; }
    }
    $flat = array_values(array_unique(array_merge(...array_values($reqs) ?: [[]])));
    $resp = http_multi_get($flat, $HTTP_TIMEOUT_SEC, $MAX_PAR_HTTP);
    foreach ($reqs as $host => $urls) {
        $fresh = false; $bestPlat = 'unknown';
        foreach ($urls as $u) {
            if (!isset($resp[$u])) continue;
            $r = $resp[$u];
            if ($r['status'] >= 200 && $r['status'] < 300) {
                $items = parse_feed_items($u, (string)$r['body'], (string)$r['content_type']);
                foreach ($items as $it) {
                    $pub = arr_get($it, 'published_at');
                    if ($pub) {
                        try { $dt = new DateTimeImmutable($pub, new DateTimeZone('UTC')); } catch (Throwable $e) { $dt = null; }
                        if ($dt && $dt >= $sinceVerifyDt) { $fresh = true; break; }
                    }
                }
            }
            if ($fresh) break;
        }
        if ($fresh) {
            $verifiedDomains++;
            // upsert into sources
            $st = pdo()->prepare("INSERT INTO sources (host, url, is_active, is_enabled, is_paused, note, platform, discovered_via) VALUES (?,?,?,?,?,?,?,?)
                                  ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled), platform=VALUES(platform), discovered_via=VALUES(discovered_via)");
            $st->execute([$host, 'https://' . $host, 1, 1, 0, 'discovered', detect_platform($host, null), 'llm_discovery']);
            db_mark_discovered_status($host, 'verified', 5);
        } else {
            db_mark_discovered_status($host, 'failed', 0);
        }
    }
}

// ----- Scan feeds for all enabled sources -----
$enabledHostsAll = getEnabledHosts(pdo());
$pausedHosts = array_slice(getPausedHosts(pdo()), 0, 150);
$seenPaths = array_slice(getSeenPathFingerprints(pdo(), 400), 0, 400);
$seenPathSet = array_flip($seenPaths);
$enabledSet = array_flip($enabledHostsAll);

$scanFeeds = [];
foreach ($enabledHostsAll as $h) {
    if (in_array($h, $pausedHosts, true)) continue;
    // prefer platform from DB
    $plat = null;
    try {
        $stp = pdo()->prepare("SELECT platform FROM sources WHERE host=? LIMIT 1");
        $stp->execute([$h]);
        $plat = $stp->fetchColumn() ?: null;
    } catch (Throwable $e) {}
    $plat = $plat ?: detect_platform($h, null);
    foreach (discover_feeds_for_platform($h, $plat) as $u) { $scanFeeds[$h][] = $u; }
}
$fetchList = array_values(array_unique(array_merge(...array_values($scanFeeds) ?: [[]])));
$resp2 = $fetchList ? http_multi_get($fetchList, $HTTP_TIMEOUT_SEC, $MAX_PAR_HTTP) : [];

$maxResults = max(1, min(200, (int)$settings['max_results_per_scan'])) ;
$feedValid = [];
foreach ($scanFeeds as $host => $urls) {
    $scannedDomains++;
    foreach ($urls as $u) {
        $r = $resp2[$u] ?? null; if (!$r) continue;
        if (($r['status'] ?? 0) < 200 || ($r['status'] ?? 0) >= 300) continue;
        $items = parse_feed_items($u, (string)$r['body'], (string)$r['content_type']);
        foreach ($items as $it) {
            $url = canonicalize_url((string)arr_get($it, 'url', ''));
            $title = trim((string)arr_get($it, 'title', ''));
            $pubSql = (string)arr_get($it, 'published_at', '');
            if ($url === '' || $title === '' || $pubSql === '') continue;
            $domain = normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
            if ($domain === '' || isset(array_flip($pausedHosts)[$domain])) continue;
            if (!isset($enabledSet[$domain])) continue; // only enabled sources
            try { $pubDt = new DateTimeImmutable($pubSql, new DateTimeZone('UTC')); } catch (Throwable $e) { continue; }
            if ($pubDt < $sinceDt) continue;

            // Deduplicate by domain+path fingerprint
            $path = parse_url($url, PHP_URL_PATH) ?? '/';
            if ($path === '') $path = '/';
            $fp = $domain . '|' . $path;
            if (isset($seenPathSet[$fp])) continue;
            $seenPathSet[$fp] = 1;

            // Optional classification (placeholder always true for now)
            if (!maybe_classify(['url' => $url, 'title' => $title], [])) continue;

            // Upsert into links table
            try {
                $sidStmt = pdo()->prepare("SELECT id FROM sources WHERE host=? LIMIT 1");
                $sidStmt->execute([$domain]);
                $sourceId = (int)$sidStmt->fetchColumn();
                if ($sourceId > 0) {
                    $nowSql = gmdate('Y-m-d H:i:s');
                    $ins = pdo()->prepare("INSERT INTO links (source_id, url, title, first_found, last_seen, times_seen, status, published_at)
                                           VALUES (?,?,?,?,?,?,?,?)
                                           ON DUPLICATE KEY UPDATE last_seen=VALUES(last_seen), times_seen=times_seen+1, title=VALUES(title), published_at=COALESCE(VALUES(published_at), published_at)");
                    $ins->execute([$sourceId, $url, $title, $nowSql, $nowSql, 1, 'new', $pubDt->format('Y-m-d H:i:s')]);
                    $preFound++;
                    if ($ins->rowCount() === 1) { $preNew++; }
                }
            } catch (Throwable $e) {
                app_log('error', 'scan', 'Insert link failed', ['url' => $url, 'err' => $e->getMessage()]);
            }

            // Enforce per-scan limit
            if ($preNew >= $maxResults) break 3;
        }
    }
}

// ----------------------- Finish scan -----------------------
function send_telegram_message(string $token, string $chatId, string $text): void {
    if ($token === '' || $chatId === '' || $text === '') return;
    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ];
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($status < 200 || $status >= 300) {
            app_log('warning', 'telegram', 'sendMessage failed', ['status' => $status, 'err' => $err, 'resp' => $resp]);
        }
    } catch (Throwable $e) {
        app_log('error', 'telegram', 'sendMessage exception', ['err' => $e->getMessage()]);
    }
}

try {
    if ($scanId) {
        $upd = pdo()->prepare("UPDATE scans SET finished_at=NOW(), status='finished', found_links=?, new_links=? WHERE id=?");
        $upd->execute([$preFound, $preNew, $scanId]);
    }
} catch (Throwable $e) {
    app_log('error', 'scan', 'Finalize scan failed', ['err' => $e->getMessage()]);
}

// Telegram notify about new links
try {
    if ($preNew > 0) {
        $token = (string)get_setting('telegram_token', '');
        $chat  = (string)get_setting('telegram_chat_id', '');
        if ($token !== '' && $chat !== '') {
            $lines = [];
            $lines[] = 'Новые обсуждения: ' . $preNew . ' (всего найдено: ' . $preFound . ')';
            $lines[] = 'Окно: с ' . $sinceIso;
            // Pick latest 5 new links
            try {
                $st = pdo()->prepare("SELECT url, title FROM links WHERE status='new' ORDER BY id DESC LIMIT 5");
                $st->execute();
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                    $t = trim((string)($r['title'] ?? ''));
                    $u = (string)($r['url'] ?? '');
                    if ($t === '') $t = $u;
                    $lines[] = '• ' . mb_substr($t, 0, 120) . "\n" . $u;
                }
            } catch (Throwable $e) {}
            $msg = implode("\n\n", $lines);
            send_telegram_message($token, $chat, $msg);
        }
    }
} catch (Throwable $e) {}

// Save last run time
try { set_setting('last_scan_at', gmdate('Y-m-d\TH:i:s\Z')); } catch (Throwable $e) {}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'scan_id' => $scanId,
    'found' => $preFound,
    'new' => $preNew,
    'discovered' => $discoveredCount,
    'verified_domains' => $verifiedDomains,
    'scanned_domains' => $scannedDomains,
    'since' => $sinceIso
], JSON_UNESCAPED_UNICODE);
exit;
?>