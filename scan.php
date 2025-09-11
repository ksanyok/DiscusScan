<?php
require_once __DIR__ . '/version.php';
/**
 * Scanner — Fresh-only monitoring
 * - Single OpenAI call using web_search, with strict JSON schema including published_at.
 * - Prompt-level filtering: paused/enabled hosts, freshness window, seen path fingerprints.
 * - Saves only valid fresh items; stores published_at (UTC) in DB.
 */

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
        $paths = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $u = (string)$row['url'];
            $p = parse_url($u, PHP_URL_PATH) ?? '/';
            if ($p === '') $p = '/';
            $paths[$p] = 1;
        }
        return array_values(array_keys($paths));
    } catch (Throwable $e) { return []; }
}

// ----------------------- Access guards -----------------------
$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    if (isset($_GET['manual'])) {
        require_login();
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
$OPENAI_HTTP_TIMEOUT = (int)get_setting('openai_timeout_sec', 300);

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

// ----------------------- OpenAI call with retry logic -----------------------
function run_openai_job(string $jobName, string $sys, string $user, string $requestUrl, array $requestHeaders, array $schema, int $maxTokens, int $timeout, callable $log): array {
    global $UA;
    $model = (string)get_setting('openai_model', 'gpt-5-mini');
    $strictRequired = (bool)get_setting('return_schema_required', true);
    $enableWeb = (bool)get_setting('openai_enable_web_search', true);

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

    // Enable OpenAI web search tool if allowed (for models that support it)
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
        $body = substr((string)$resp, (int)($info['header_size'] ?? 0));
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
        
        if ($status === 200) {
            // Success - parse response (Responses API)
            $links = extract_json_links_from_responses((string)$body);
            
            // If strict required — do not relax. Otherwise try relaxed schema once.
            if (empty($links) && !$strictRequired) {
                $payload['text']['format']['strict'] = false;
                $ch2 = curl_init($requestUrl);
                curl_setopt_array($ch2, [
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
                $resp2 = curl_exec($ch2);
                $info2 = curl_getinfo($ch2);
                $status2 = (int)($info2['http_code'] ?? 0);
                $body2 = substr((string)$resp2, (int)($info2['header_size'] ?? 0));
                curl_close($ch2);

                $log("OpenAI RELAXED RESPONSE [{$jobName}]", [ 
                    'status' => $status2, 
                    'content_type' => $info2['content_type'] ?? null 
                ], (string)$body2);
                
                if ($status2 === 200) {
                    $links = extract_json_links_from_responses((string)$body2);
                }
            }
            
            return [$status, count($links), $links];
        }
        
        if (!$isRetryable || $attempt === $maxRetries) {
            // Не ретраимся или это последняя попытка
            $log("OpenAI ERROR [{$jobName}] - Final attempt", [
                'status' => $status,
                'curl_error' => $curlError,
                'attempt' => $attempt
            ]);
            return [$status, 0, []];
        }
        
        // Экспоненциальная задержка перед повторной попыткой
        $delay = $baseDelay * pow(2, $attempt - 1);
        sleep($delay);
        
        $log("OpenAI RETRY [{$jobName}]", [
            'status' => $status,
            'attempt' => $attempt,
            'next_delay' => $delay * 2
        ]);
    }

    return [$status, 0, []];
}

// ----------------------- Prompt assembly -----------------------
$pausedHosts = array_slice(getPausedHosts(pdo()), 0, 150);
$enabledHostsAll = getEnabledHosts(pdo());
$enabledHosts = $settings['enabled_sources_only'] ? array_slice($enabledHostsAll, 0, 300) : [];
$seenPaths = array_slice(getSeenPathFingerprints(pdo(), 400), 0, 400);
$maxResults = max(1, min(200, (int)$settings['max_results_per_scan'])) ;

// Определяем реально активные скоупы
$scopesAvail = [];
if ($scopeDomains && !empty($enabledHosts)) $scopesAvail[] = 'domains';
if ($scopeTelegram) $scopesAvail[] = 'telegram';
if ($scopeForums) $scopesAvail[] = 'forums';
if (empty($scopesAvail)) $scopesAvail = ['forums'];
$perScopeMax = max(1, (int)ceil($maxResults / count($scopesAvail)));

$langsTxt = '';
if (!empty($settings['languages'])) {
    $langsTxt = 'Languages: ' . implode(', ', $settings['languages']) . '.';
}
$regionsTxt = '';
if (!empty($settings['regions'])) {
    $regionsTxt = 'Regions: ' . implode(', ', $settings['regions']) . '.';
}

$sysCommon = <<<SYS
You are a web monitor. Return STRICT JSON ONLY with this exact schema:
{
  "links": [
    {
      "url": "string (absolute URL)",
      "title": "string",
      "domain": "string (registrable domain, e.g., example.com)",
      "published_at": "YYYY-MM-DDTHH:MM:SSZ (UTC, publication or last-activity time)",
      "why": "one-sentence reason"
    }
  ]
}

HARD RULES:
- Include ONLY real discussion pages: forums, threads, Q&A, support/community topics, GitHub Issues/Discussions, StackOverflow/StackExchange, vendor communities.
- Exclude marketing/home pages and docs without comments.
- Exclude ANY paused/blocked sources (see PAUSED_HOSTS).
- Include ONLY items STRICTLY NEWER than SINCE_DATETIME.
- If you cannot extract a reliable publication/last-activity datetime, DO NOT include the item.
- Exclude duplicates and anything already seen (see SEEN_URL_FINGERPRINTS as path patterns).
- Return at most MAX_RESULTS items.
- Output must be valid JSON and nothing else.
SYS;

$jobs = [];
foreach ($scopesAvail as $scopeName) {
    if ($scopeName === 'domains') {
        $sys = $sysCommon . "\nAdditional rules for this scope:\n- Include ONLY hosts in ENABLED_HOSTS.\n- Do NOT include or query Telegram or social networks.\n";
        $user = "TASK:\nFind NEW or updated discussion pages strictly newer than SINCE_DATETIME = {$sinceIso}.\nCONTEXT:\nPAUSED_HOSTS = "
              . json_encode($pausedHosts, JSON_UNESCAPED_UNICODE)
              . "\nENABLED_HOSTS = " . json_encode($enabledHosts, JSON_UNESCAPED_UNICODE)
              . "\nSEEN_URL_FINGERPRINTS = " . json_encode($seenPaths, JSON_UNESCAPED_UNICODE)
              . "\n\nFocus on forums/Q&A/support/community subpaths of these hosts.\n{$langsTxt}\n{$regionsTxt}\nMAX_RESULTS = {$perScopeMax}\n\nTargets:\n" . $basePrompt;
        $jobs[] = ['name' => 'domains', 'sys' => $sys, 'user' => $user];
    }
    if ($scopeName === 'telegram') {
        $modeLine = ($telegramMode === 'discuss')
            ? "Prefer posts that allow replies/comments; avoid bare channel homepages."
            : "Include Telegram post URLs with message IDs; avoid bare channel homepages.";
        $sys = $sysCommon . "\nAdditional rules for this scope:\n- Include ONLY t.me (Telegram) posts.\n- {$modeLine}\n- Do NOT include any other domains.\n";
        $user = "TASK:\nFind NEW Telegram posts strictly newer than SINCE_DATETIME = {$sinceIso}.\nCONTEXT:\nPAUSED_HOSTS = "
              . json_encode($pausedHosts, JSON_UNESCAPED_UNICODE)
              . "\nSEEN_URL_FINGERPRINTS = " . json_encode($seenPaths, JSON_UNESCAPED_UNICODE)
              . "\n\nDomain MUST be t.me.\n{$langsTxt}\n{$regionsTxt}\nMAX_RESULTS = {$perScopeMax}\n\nTargets:\n" . $basePrompt;
        $jobs[] = ['name' => 'telegram', 'sys' => $sys, 'user' => $user];
    }
    if ($scopeName === 'forums') {
        $sys = $sysCommon . "\nAdditional rules for this scope:\n- Include only real discussions (forum/thread/topic/discussion/comments/support/issue).\n- EXCLUDE Telegram and social networks entirely (do not include or query t.me, vk.com, facebook.com, x.com, twitter.com, instagram.com, tiktok.com, youtube.com).\n";
        $user = "TASK:\nFind NEW or updated discussions strictly newer than SINCE_DATETIME = {$sinceIso}.\nCONTEXT:\nPAUSED_HOSTS = "
              . json_encode($pausedHosts, JSON_UNESCAPED_UNICODE)
              . "\nSEEN_URL_FINGERPRINTS = " . json_encode($seenPaths, JSON_UNESCAPED_UNICODE)
              . "\n\nFocus on forums / Q&A / support communities / GitHub Issues / StackOverflow.\n{$langsTxt}\n{$regionsTxt}\nMAX_RESULTS = {$perScopeMax}\n\nTargets:\n" . $basePrompt;
        $jobs[] = ['name' => 'forums', 'sys' => $sys, 'user' => $user];
    }
}

// Execute all jobs and aggregate
$allReturned = [];
$jobStats = [];
foreach ($jobs as $jb) {
    [$st, $c, $lnks] = run_openai_job($jb['name'], $jb['sys'], $jb['user'], $requestUrl, $requestHeaders, $schema, $MAX_OUTPUT_TOKENS, $OPENAI_HTTP_TIMEOUT, $appLog);
    $jobStats[] = ['scope' => $jb['name'], 'status' => $st, 'returned' => $c];
    foreach ((array)$lnks as $x) { $allReturned[] = $x; }
}
$totalReturned = array_sum(array_map(fn($j)=> (int)$j['returned'], $jobStats));
$lastStatus = $jobStats ? end($jobStats)['status'] : 0;
// replace returnedLinks for downstream pipeline
$returnedLinks = $allReturned;

// ----------------------- Parse, validate, filter -----------------------
$valid = [];
$pausedSet = array_flip($pausedHosts);
$enabledSet = array_flip($enabledHostsAll);
$seenPathSet = array_flip($seenPaths);

foreach ((array)$returnedLinks as $item) {
    $url = canonicalize_url((string)arr_get($item, 'url', ''));
    $title = trim((string)arr_get($item, 'title', ''));
    $domain = normalize_host((string)arr_get($item, 'domain', ''));
    $pub = trim((string)arr_get($item, 'published_at', ''));

    if ($url === '' || $title === '' || $domain === '' || $pub === '') continue;
    // Extract from URL if domain missing or mismatch
    if ($domain === '') { $domain = normalize_host(parse_url($url, PHP_URL_HOST) ?: ''); }
    if ($domain === '') continue;

    // Date parse and filter
    try {
        $pubDt = new DateTimeImmutable($pub, new DateTimeZone('UTC'));
    } catch (Throwable $e) { continue; }
    if ($pubDt < $sinceDt) continue;

    // Host filters
    if (isset($pausedSet[$domain])) continue;
    if ($settings['enabled_sources_only'] && !isset($enabledSet[$domain])) {
        // Allow Telegram even if not whitelisted
        if (!in_array($domain, ['t.me', 'telegram.me'], true)) continue;
    }

    // Seen path dedupe
    $path = parse_url($url, PHP_URL_PATH) ?? '/';
    if (isset($seenPathSet[$path])) continue;

    $valid[] = [
        'url' => $url,
        'title' => $title,
        'domain' => $domain,
        'published_at' => $pubDt->format('Y-m-d H:i:s'), // store as SQL UTC
        'why' => (string)arr_get($item, 'why', '')
    ];
    if (count($valid) >= $maxResults) break;
}

// ----------------------- Save results -----------------------
$found = 0; $new = 0;
foreach ($valid as $it) {
    $url = $it['url'];
    $title = $it['title'];
    $domain = $it['domain'];
    $publishedAtSql = $it['published_at'];

    // Ensure source exists
    $stmt = pdo()->prepare("SELECT id FROM sources WHERE host=? LIMIT 1");
    $stmt->execute([$domain]);
    $srcId = $stmt->fetchColumn();
    if (!$srcId) {
        $ins = pdo()->prepare("INSERT INTO sources (host, url, is_active, is_enabled, is_paused, note) VALUES (?,?,1,1,0,'discovered')");
        $ins->execute([$domain, 'https://' . $domain]);
        $srcId = (int)pdo()->lastInsertId();
    }

    // Upsert link
    $found++;
    $q = pdo()->prepare("SELECT id, times_seen, published_at FROM links WHERE url=? LIMIT 1");
    $q->execute([$url]);
    $row = $q->fetch();
    if ($row) {
        $times = (int)$row['times_seen'] + 1;
        $u = pdo()->prepare("UPDATE links SET title=?, last_seen=NOW(), times_seen=?, published_at=COALESCE(published_at, ?) WHERE id=?");
        $u->execute([$title, $times, $publishedAtSql, $row['id']]);
    } else {
        $ins = pdo()->prepare("INSERT INTO links (source_id, url, title, first_found, last_seen, times_seen, status, published_at) VALUES (?,?,?,NOW(),NOW(),1,'new',?)");
        $ins->execute([$srcId, $url, $title, $publishedAtSql]);
        $new++;
    }
}

// Update scans row + last_scan_at setting
try {
    $stmt = pdo()->prepare("UPDATE scans SET finished_at=NOW(), status='done', found_links=?, new_links=? WHERE id=?");
    $stmt->execute([ (int)$found, (int)$new, (int)$scanId ]);
} catch (Throwable $e) {}
set_setting('last_scan_at', date('Y-m-d H:i:s'));

// ----------------------- Telegram notification (optional) -----------------------
$tgToken = (string)get_setting('telegram_token', '');
$tgChat  = (string)get_setting('telegram_chat_id', '');
if ($tgToken !== '' && $tgChat !== '') {
    $lines = [];
    $lines[] = "🔎 Сканирование завершено";
    $lines[] = "Модель: {$model}";
    $lines[] = "SINCE (UTC): {$sinceIso}";
    $lines[] = "Скоупы: " . implode(', ', array_map(fn($j)=> $j['scope'] . "=" . $j['returned'], $jobStats));
    $lines[] = "Всего возвращено: {$totalReturned}";
    $lines[] = "Итого валидных ссылок: {$found}";
    $lines[] = "Новых: {$new}";
    $lines[] = "Время: " . date('Y-m-d H:i');

    $txt = implode("\n", $lines);
    $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
    $chT = curl_init($tgUrl);
    curl_setopt_array($chT, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => [ 'chat_id' => $tgChat, 'text' => $txt, 'disable_web_page_preview' => 1 ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => $UA
    ]);
    curl_exec($chT);
    curl_close($chT);
}

// ----------------------- Response to caller -----------------------
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'scan_id' => $scanId,
    'found' => $found,
    'new' => $new,
    'since' => $sinceIso,
    'total_returned' => $totalReturned,
    'job_stats' => $jobStats,
    'status' => $lastStatus
], JSON_UNESCAPED_UNICODE);