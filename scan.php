<?php
/**
 * Scanner v2 — scope-aware and multi-pass
 * - Reads base human prompt from settings ("search_prompt").
 * - Considers scope checkboxes from settings: scope_domains_enabled, scope_telegram_enabled(+telegram_mode), scope_forums_enabled.
 * - Runs ONE OpenAI Responses API call PER ENABLED SCOPE (domains / telegram / forums) using the hosted `web_search` tool.
 * - Each scope has its own system+user prompt tuned for maximal recall and freshness.
 * - Aggregates results locally, canonicalizes & de-dupes, and stores in DB.
 * - Minimal logging: request payload (masked key) + raw OpenAI response per scope, plus relaxed/bump tries.
 */

require_once __DIR__ . '/db.php';

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
    $host   = strtolower($p['host']);
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

// ----------------------- Period guard -----------------------
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

// ----------------------- Settings -----------------------
$apiKey = (string)get_setting('openai_api_key', '');
$model  = (string)get_setting('openai_model', 'gpt-5-mini');
$prompt = (string)get_setting('search_prompt', '');
if ($apiKey === '' || $prompt === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Missing OpenAI API key or search prompt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lastScanAt = (string)get_setting('last_scan_at', '');

// Scope flags
$scopeDomains  = (bool)get_setting('scope_domains_enabled', false);
$scopeTelegram = (bool)get_setting('scope_telegram_enabled', false);
$scopeForums   = (bool)get_setting('scope_forums_enabled', true);
$telegramMode  = (string)get_setting('telegram_mode', 'any'); // any|discuss

// Active domains list (for domain scope)
$activeHosts = [];
$allKnownHosts = [];
$pausedHosts = [];
try {
    $q = pdo()->query("SELECT host,is_active FROM sources ORDER BY host");
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $h = normalize_host((string)$row['host']);
        if ($h === '') continue;
        $allKnownHosts[] = $h;
        if ((int)$row['is_active'] === 1) {
            $activeHosts[] = $h;
        } else {
            $pausedHosts[] = $h;
        }
    }
} catch (Throwable $e) {}

// ----------------------- DB: start scan row -----------------------
$scanId = null;
try {
    $ins = pdo()->prepare("INSERT INTO scans (started_at, status, model, prompt) VALUES (NOW(), 'started', ?, ?)");
    $ins->execute([$model, $prompt]);
    $scanId = (int)pdo()->lastInsertId();
} catch (Throwable $e) {}

$scanState = [
    'scanId' => $scanId,
    'found' => 0,
    'new' => 0,
    'newLinks' => [],
    'allLinks' => [],
    'jobStats' => [],
    'bumpedAny' => false,
    'finalized' => false,
    'status' => 'started',
    'error' => null
];

register_shutdown_function(function () use (&$scanState) {
    if (!empty($scanState['finalized']) || empty($scanState['scanId'])) {
        return;
    }
    $err = error_get_last();
    $message = $scanState['error'] ?? null;
    if ($err) {
        $message = 'Shutdown: ' . ($err['message'] ?? 'unknown') . ' @ ' . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?');
    } elseif (!$message) {
        $message = 'Скан прерван (shutdown)';
    }
    finalize_scan($scanState, 'error', $message);
});

// ----------------------- OpenAI client helpers -----------------------
$MAX_OUTPUT_TOKENS   = (int)get_setting('openai_max_output_tokens', 4096);
$OPENAI_HTTP_TIMEOUT = (int)get_setting('openai_timeout_sec', 300);

$requestUrl = 'https://api.openai.com/v1/responses';
$requestHeaders = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'Expect:' // disable 100-continue
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
                ],
                'required' => ['url','title','domain'],
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

function extract_links_from_plain_list(string $body): array {
    $out = [];
    $text = trim($body);
    if ($text === '') return $out;
    // If it's JSON, skip (handled elsewhere)
    $first = ltrim($text)[0] ?? '';
    if ($first === '{' || $first === '[') return $out;

    $lines = preg_split('~\r?\n+~', $text);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // Expect format: <url>\t<title>
        $parts = explode("\t", $line, 2);
        $url = trim($parts[0] ?? '');
        if (!preg_match('~^https?://~i', $url)) continue;
        $title = trim($parts[1] ?? '');
        $domain = normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
        if ($domain === '') continue;
        $out[] = ['url' => $url, 'title' => $title, 'domain' => $domain];
    }
    return $out;
}

function normalize_datetime_guess(?string $value): ?string {
    if (!is_string($value)) return null;
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '') return null;
    // Replace "T" separator if needed and collapse whitespace
    $normalized = preg_replace('/\s+/u', ' ', str_replace('T', ' ', $value));
    $ts = strtotime($normalized);
    if ($ts === false) {
        $ts = strtotime($value);
    }
    if ($ts === false || $ts < strtotime('2000-01-01')) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function extract_jsonld_dates($data): array {
    $result = ['modified' => null, 'published' => null];
    $stack = [$data];
    $targetKeysModified = ['datemodified', 'modified', 'dateupdated', 'uploadDate'];
    $targetKeysPublished = ['datepublished', 'datecreated', 'dateposted', 'uploadDate', 'published'];

    while ($stack) {
        $current = array_pop($stack);
        if (is_object($current)) {
            $current = (array)$current;
        }
        if (!is_array($current)) {
            continue;
        }
        foreach ($current as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $stack[] = $value;
                continue;
            }
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            $lk = strtolower($key);
            if ($result['modified'] === null && in_array($lk, $targetKeysModified, true)) {
                $result['modified'] = $value;
            }
            if ($result['published'] === null && in_array($lk, $targetKeysPublished, true)) {
                $result['published'] = $value;
            }
            if ($result['modified'] !== null && $result['published'] !== null) {
                return $result;
            }
        }
    }
    return $result;
}

function detect_content_updated_at(string $url, int $timeout = 12): ?string {
    static $cache = [];
    if (isset($cache[$url])) {
        return $cache[$url];
    }

    $headers = [];
    $ch = curl_init($url);
    if (!$ch) {
        $cache[$url] = null;
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'DiscusScan/1.0 (+https://github.com/oleksandr/DiscusScan)',
        CURLOPT_ACCEPT_ENCODING => '',
        CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$headers) {
            $len = strlen($header);
            $header = trim($header);
            if ($header === '') {
                return $len;
            }
            if (strpos($header, ':') !== false) {
                [$key, $val] = array_map('trim', explode(':', $header, 2));
                if ($key !== '') {
                    $headers[strtolower($key)] = $val;
                }
            }
            return $len;
        }
    ]);

    $html = curl_exec($ch);
    if ($html === false) {
        curl_close($ch);
        $cache[$url] = null;
        return null;
    }
    if (strlen($html) > 600000) {
        $html = substr($html, 0, 600000);
    }
    $lastModifiedHeader = $headers['last-modified'] ?? null;
    curl_close($ch);

    if (!mb_detect_encoding($html, 'UTF-8', true)) {
        $html = @mb_convert_encoding($html, 'UTF-8', 'auto');
    }

    $candidates = [];
    $addCandidate = function (?string $value, int $score) use (&$candidates) {
        $dt = normalize_datetime_guess($value);
        if ($dt !== null) {
            $candidates[] = ['date' => $dt, 'score' => $score];
        }
    };

    $metaPatterns = [
        ['/<meta[^>]+property=["\']article:modified_time["\'][^>]*content=["\']([^"\']+)["\']/i', 1],
        ['/<meta[^>]+property=["\']og:updated_time["\'][^>]*content=["\']([^"\']+)["\']/i', 2],
        ['/<meta[^>]+name=["\']last-modified["\'][^>]*content=["\']([^"\']+)["\']/i', 3],
        ['/<meta[^>]+itemprop=["\']dateModified["\'][^>]*content=["\']([^"\']+)["\']/i', 4],
        ['/<meta[^>]+itemprop=["\']dateUpdated["\'][^>]*content=["\']([^"\']+)["\']/i', 5],
        ['/<meta[^>]+property=["\']article:published_time["\'][^>]*content=["\']([^"\']+)["\']/i', 25],
        ['/<meta[^>]+itemprop=["\']datePublished["\'][^>]*content=["\']([^"\']+)["\']/i', 26],
        ['/<meta[^>]+property=["\']og:published_time["\'][^>]*content=["\']([^"\']+)["\']/i', 27],
    ];

    foreach ($metaPatterns as [$pattern, $score]) {
        if (preg_match($pattern, $html, $m)) {
            $addCandidate($m[1] ?? null, $score);
        }
    }

    if (preg_match('/<time[^>]+itemprop=["\']dateModified["\'][^>]*datetime=["\']([^"\']+)["\']/i', $html, $m)) {
        $addCandidate($m[1] ?? null, 6);
    }
    if (preg_match('/<time[^>]+class=["\'][^"\']*(updated|modified)[^"\']*["\'][^>]*datetime=["\']([^"\']+)["\']/i', $html, $m)) {
        $addCandidate($m[2] ?? null, 7);
    }

    if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $scripts)) {
        foreach ($scripts[1] as $block) {
            $json = trim(html_entity_decode($block, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $data = json_decode($json, true);
            if ($data === null) {
                continue;
            }
            $dates = extract_jsonld_dates($data);
            if (!empty($dates['modified'])) {
                $addCandidate($dates['modified'], 8);
            }
            if (!empty($dates['published'])) {
                $addCandidate($dates['published'], 28);
            }
        }
    }

    if ($lastModifiedHeader) {
        $addCandidate($lastModifiedHeader, 40);
    }

    if (empty($candidates)) {
        $cache[$url] = null;
        return null;
    }

    usort($candidates, function ($a, $b) {
        if ($a['score'] === $b['score']) {
            return strcmp($b['date'], $a['date']);
        }
        return $a['score'] <=> $b['score'];
    });

    $best = $candidates[0]['date'];
    $cache[$url] = $best;
    return $best;
}

/**
 * Call OpenAI Responses once with provided sys/user texts.
 * Returns [status,int_links,array links,bool bumped]
 */
function run_openai_job(string $jobName, string $sys, string $user, string $requestUrl, array $requestHeaders, array $schema, int $maxTokens, int $timeout, callable $log, ?string $country = null, ?int $maxToolCalls = null): array {
    $model = (string)get_setting('openai_model', 'gpt-5-mini');
    $toolObj = ['type' => 'web_search'];
    if ($country) {
        $toolObj['user_location'] = [ 'type' => 'approximate', 'country' => strtoupper(substr($country,0,8)) ];
    }
    $payload = [
        'model' => $model,
        'max_output_tokens' => $maxTokens,
        'input' => [
            ['role' => 'system', 'content' => $sys . "\n\nMANDATORY: When finished, output ONLY the JSON that matches the schema (even if empty)."],
            ['role' => 'user',   'content' => $user],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'monitoring_output',
                'schema' => $schema,
                'strict' => true
            ],
            'verbosity' => 'low'
        ],
        'tools' => [ $toolObj ],
        'tool_choice' => 'auto'
    ];

    if ($maxToolCalls !== null && $maxToolCalls > 0) {
        $payload['max_tool_calls'] = $maxToolCalls;
    }

    // --- HTTP (primary) ---
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADER => true
    ]);
    $resp = curl_exec($ch);
    $info  = curl_getinfo($ch);
    $status = (int)($info['http_code'] ?? 0);
    $body   = substr((string)$resp, (int)($info['header_size'] ?? 0));
    curl_close($ch);

    $log("OpenAI REQUEST [{$jobName}]", [ 'url' => $requestUrl, 'model' => $model, 'max_output_tokens' => $maxTokens ], json_encode($payload, JSON_UNESCAPED_UNICODE));
    $log("OpenAI RESPONSE [{$jobName}]", [ 'status' => $status, 'content_type' => $info['content_type'] ?? null ], (string)$body);

    $bumped = false;

    // If incomplete due to max tokens — bump once
    $parsed = json_decode((string)$body, true);
    if ($status === 200 && is_array($parsed)
        && (arr_get($parsed, 'status') === 'incomplete')
        && strtolower((string)arr_get($parsed, 'incomplete_details')['reason'] ?? '') === 'max_output_tokens') {

        $payload['max_output_tokens'] = min(8192, max(2048, $maxTokens * 2));
        $bumped = true;

        $ch2 = curl_init($requestUrl);
        curl_setopt_array($ch2, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => true
        ]);
        $resp2 = curl_exec($ch2);
        $info2 = curl_getinfo($ch2);
        $status2 = (int)($info2['http_code'] ?? 0);
        $body2   = substr((string)$resp2, (int)($info2['header_size'] ?? 0));
        curl_close($ch2);

        $log("OpenAI SECOND TRY [{$jobName}]", [ 'status' => $status2, 'content_type' => $info2['content_type'] ?? null ], (string)$body2);

        $status = $status2;
        $body   = $body2;
    }

    // Try strict/relaxed JSON extraction
    $links = [];
    if ($status === 200) {
        $links = extract_json_links_from_responses((string)$body);
        if (empty($links)) {
            // RELAX schema and retry once
            $payload['text']['format']['strict'] = false;
            $ch3 = curl_init($requestUrl);
            curl_setopt_array($ch3, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HEADER => true
            ]);
            $resp3 = curl_exec($ch3);
            $info3 = curl_getinfo($ch3);
            $status3 = (int)($info3['http_code'] ?? 0);
            $body3   = substr((string)$resp3, (int)($info3['header_size'] ?? 0));
            curl_close($ch3);

            $log("OpenAI RELAXED RESPONSE [{$jobName}]", [ 'status' => $status3, 'content_type' => $info3['content_type'] ?? null ], (string)$body3);

            if ($status3 === 200) {
                $links = extract_json_links_from_responses((string)$body3);
                $body = $body3;
            }
        }
    }

    // FINAL FALLBACK: ask for newline-separated "<url>\t<title>" and parse locally
    if (empty($links)) {
        $fallbackPayload = [
            'model' => $model,
            'max_output_tokens' => $maxTokens,
            'input' => [
                ['role' => 'system', 'content' =>
                    $sys
                    . "\n\nFALLBACK MODE:\nReturn ONLY newline-separated lines in the format: <url>\t<title>.\nNo prose, no JSON, no bullets. If nothing found, return an empty output."],
                ['role' => 'user', 'content' => $user . "\nReturn ONLY the list as specified."]
            ],
            'tools' => [ $toolObj ],
            'tool_choice' => 'auto'
        ];

        $ch4 = curl_init($requestUrl);
        curl_setopt_array($ch4, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADER => true
        ]);
        $resp4 = curl_exec($ch4);
        $info4 = curl_getinfo($ch4);
        $status4 = (int)($info4['http_code'] ?? 0);
        $body4   = substr((string)$resp4, (int)($info4['header_size'] ?? 0));
        curl_close($ch4);

        $log("OpenAI FALLBACK URLS [{$jobName}]", [ 'status' => $status4, 'content_type' => $info4['content_type'] ?? null ], (string)$body4);

        if ($status4 === 200) {
            $fallbackLinks = extract_links_from_plain_list((string)$body4);
            if (!empty($fallbackLinks)) {
                $links = $fallbackLinks;
                $status = $status4;
            }
        }
    }

    return [$status, count($links), $links, $bumped];
}

function save_links_batch(array &$links): array {
    $found = 0;
    $new = 0;
    $newLinks = [];

    if (empty($links)) {
        return ['found' => 0, 'new' => 0, 'new_links' => []];
    }

    $pdo = pdo();
    $selectSource = $pdo->prepare("SELECT id FROM sources WHERE host=? LIMIT 1");
    $insertSource = $pdo->prepare("INSERT INTO sources (host, url, is_active, note) VALUES (?,?,?,?)");
    $selectLink = $pdo->prepare("SELECT id, times_seen, content_updated_at FROM links WHERE url=? LIMIT 1");
    $updateLink = $pdo->prepare("UPDATE links SET title=?, last_seen=NOW(), times_seen=? WHERE id=?");
    $updateLinkWithContent = $pdo->prepare("UPDATE links SET title=?, last_seen=NOW(), times_seen=?, content_updated_at=? WHERE id=?");
    $insertLink = $pdo->prepare("INSERT INTO links (source_id, url, title, first_found, last_seen, times_seen, status, content_updated_at) VALUES (?,?,?,NOW(),NOW(),1,'new',?)");

    foreach ($links as $idx => $it) {
        $url = $it['url'];
        $title = $it['title'];
        $domain = $it['domain'];
        $purpose = $it['__purpose'] ?? '';

        $selectSource->execute([$domain]);
        $srcId = $selectSource->fetchColumn();
        if (!$srcId) {
            $isDiscovery = ($purpose === 'discovery');
            $insertSource->execute([$domain, 'https://' . $domain, $isDiscovery ? 0 : 1, $isDiscovery ? 'candidate' : 'discovered']);
            $srcId = (int)$pdo->lastInsertId();
        }

        $found++;
        $selectLink->execute([$url]);
        $row = $selectLink->fetch(PDO::FETCH_ASSOC);

        $existingContentDate = is_array($row) ? ($row['content_updated_at'] ?? null) : null;
        $contentUpdatedAt = null;
        $shouldFetchMeta = !$row || !$existingContentDate;

        if ($shouldFetchMeta) {
            $contentUpdatedAt = detect_content_updated_at($url);
        }

        $finalContentDate = $existingContentDate;
        if ($contentUpdatedAt && (!$existingContentDate || strtotime($contentUpdatedAt) > strtotime((string)$existingContentDate))) {
            $finalContentDate = $contentUpdatedAt;
        }

        if ($row) {
            $times = (int)$row['times_seen'] + 1;
            if ($finalContentDate !== $existingContentDate && $finalContentDate !== null) {
                $updateLinkWithContent->execute([$title, $times, $finalContentDate, $row['id']]);
            } else {
                $updateLink->execute([$title, $times, $row['id']]);
            }
        } else {
            $insertLink->execute([$srcId, $url, $title, $finalContentDate]);
            $new++;
            $newLinks[] = ['url' => $url, 'title' => $title, 'domain' => $domain, 'content_updated_at' => $finalContentDate];
        }

        $links[$idx]['content_updated_at'] = $finalContentDate;
    }

    return ['found' => $found, 'new' => $new, 'new_links' => $newLinks];
}

function notify_telegram_scan(array $state, string $status, ?string $errorMessage): void {
    $tgToken = (string)get_setting('telegram_token', '');
    $tgChat  = (string)get_setting('telegram_chat_id', '');
    if ($tgToken === '' || $tgChat === '') {
        return;
    }

    $found = (int)($state['found'] ?? 0);
    $new = (int)($state['new'] ?? 0);
    $newLinks = $state['newLinks'] ?? [];
    $allLinks = $state['allLinks'] ?? [];
    $jobStats = $state['jobStats'] ?? [];

    $domainsTotal = count(array_unique(array_map(fn($l)=>$l['domain'], $allLinks)));
    $domainsNew   = count(array_unique(array_map(fn($l)=>$l['domain'], $newLinks)));

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
        . ($_SERVER['HTTP_HOST'] ?? 'localhost')
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $panelUrl = $baseUrl . '/index.php';
    $esc = function(string $s): string { return htmlspecialchars(mb_substr($s,0,160), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

    if ($status === 'done') {
        $message  = $new > 0
            ? "🚀 <b>Мониторинг: найдено {$new} новых ссылок</b>\n"
            : "📡 <b>Мониторинг завершён</b>\n";
        $message .= "🗂 Всего найдено за проход: <b>{$found}</b>\n";
        $message .= "🌐 Домены (все/новые): <b>{$domainsTotal}</b> / <b>{$domainsNew}</b>\n";
    } else {
        $message  = "⚠️ <b>Мониторинг прерван</b>\n";
        $message .= "🗂 Успели сохранить: <b>{$found}</b> ссылок\n";
        $message .= "🌐 Домены (все/новые): <b>{$domainsTotal}</b> / <b>{$domainsNew}</b>\n";
        if ($errorMessage) {
            $msg = mb_substr($errorMessage, 0, 400);
            $message .= "\n❗️ <b>Ошибка:</b> " . $esc($msg) . "\n";
        }
        $message .= "\n➡️ Зайдите в панель и нажмите «Продолжить», чтобы перезапустить.\n";
    }

    if ($new > 0) {
        $sample = array_slice($newLinks, 0, 3);
        $message .= "\n🔥 <b>Новые примеры:</b>\n";
        foreach ($sample as $s) {
            $u = $s['url'];
            $t = $s['title'] ?: $s['domain'];
            $d = $s['domain'];
            $updated = '';
            if (!empty($s['content_updated_at'])) {
                $updated = ' · ' . $esc(date('d.m H:i', strtotime($s['content_updated_at'])));
            }
            $message .= "• <a href=\"".$esc($u)."\">".$esc($t)."</a> <code>".$esc($d)."</code>" . $updated . "\n";
        }
        if ($new > 3) {
            $rest = $new - 3;
            $message .= "… и ещё {$rest} на панели\n";
        }
    } elseif ($status === 'done') {
        $message .= "\nНовых ссылок нет за этот проход.\n";
    }

    if (!empty($jobStats)) {
        $message .= "\n📊 <b>Скоупы:</b>\n";
        foreach ($jobStats as $jn => $st) {
            $saved = (int)($st['saved'] ?? 0);
            $raw = (int)($st['count'] ?? 0);
            $http = (int)($st['status'] ?? 0);
            $message .= "· " . $esc($jn) . ": " . $saved . "/" . $raw . " (HTTP " . $http . ")\n";
        }
    }

    $message .= "\n🕒 " . date('Y-m-d H:i');
    $replyMarkup = json_encode([
        'inline_keyboard' => [ [ ['text' => '📊 Открыть панель', 'url' => $panelUrl] ] ]
    ], JSON_UNESCAPED_UNICODE);

    $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
    $chT = curl_init($tgUrl);
    curl_setopt_array($chT, [
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
    curl_exec($chT);
    curl_close($chT);
}

function finalize_scan(array &$state, string $status, ?string $errorMessage = null): void {
    if (!empty($state['finalized'])) {
        return;
    }

    $scanId = $state['scanId'] ?? null;
    if ($scanId) {
        try {
            $stmt = pdo()->prepare("UPDATE scans SET finished_at=NOW(), status=?, found_links=?, new_links=?, error=? WHERE id=?");
            $stmt->execute([
                $status,
                (int)($state['found'] ?? 0),
                (int)($state['new'] ?? 0),
                $errorMessage,
                (int)$scanId
            ]);
        } catch (Throwable $e) {}
    }

    if ($status === 'done') {
        set_setting('last_scan_at', date('Y-m-d H:i:s'));
    }

    notify_telegram_scan($state, $status, $errorMessage);
    $state['finalized'] = true;
    $state['status'] = $status;
    $state['error'] = $errorMessage;
}

// --- NEW: fetch languages & regions settings ---
$searchLangs = get_setting('search_languages', []);
if (is_string($searchLangs)) { $tmp = json_decode($searchLangs, true); if (is_array($tmp)) $searchLangs = $tmp; }
if (!is_array($searchLangs)) $searchLangs = [];
$searchLangs = array_values(array_unique(array_filter(array_map(fn($x)=> strtolower(preg_replace('~[^a-zA-Z0-9_-]~','', trim($x))), $searchLangs))));

$searchRegions = get_setting('search_regions', []);
if (is_string($searchRegions)) { $tmp = json_decode($searchRegions, true); if (is_array($tmp)) $searchRegions = $tmp; }
if (!is_array($searchRegions)) $searchRegions = [];
$searchRegions = array_values(array_unique(array_filter(array_map(fn($x)=> strtoupper(preg_replace('~[^A-Z0-9]~','', trim($x))), $searchRegions))));

$MAX_REGION_JOBS = 5; // safeguard
if (count($searchRegions) > $MAX_REGION_JOBS) {
    app_log('info','scan','Region list truncated',[ 'total' => count($searchRegions), 'used' => $MAX_REGION_JOBS ]);
    $searchRegions = array_slice($searchRegions,0,$MAX_REGION_JOBS);
}
if (empty($searchRegions)) { $searchRegions = [null]; } // single pass without explicit location

$langLine = '';
if ($searchLangs) { $langLine = "Target languages (prioritize when forming queries): " . implode(', ', $searchLangs) . ".\n"; }

$regionLineTpl = function($code){ return $code ? ("Focus on content relevant to country code " . $code . ".\n") : ''; };

// Tool-call limits per scope to cap web_search expansion
$toolLimitDiscovery = 12;
$toolLimitPerDomain = 8;
$toolLimitForums = 12;
$toolLimitTelegram = 10;

// ----------------------- Build jobs per scope (region-aware) -----------------------
$jobs = [];
$nowPref = ($lastScanAt !== '')
    ? "Prefer pages created or updated AFTER {$lastScanAt} UTC; otherwise last 12 months."
    : "Prefer results from the last 30 days; if none, include older (up to 12 months).";

// NEW: Discovery job (find new discussion/forum domains) — runs once (region-independent)
if ($scopeForums) {
    $excludeList = $allKnownHosts ? implode(', ', array_slice($allKnownHosts,0,400)) : '';
    $sys = "You are a discovery agent. Find NEW relevant forums / discussion / community / Q&A / review sites where the target topic is discussed.\n"
         . $langLine
         . "Output STRICT JSON only: {\n  \"links\": [ { \"url\": \"...\", \"title\": \"...\", \"domain\": \"...\" } ]\n}. No prose.\n"
         . "Return 5–20 unique discussion URLs EACH from a DISTINCT domain NOT in this exclusion list: " . $excludeList . " .\n"
         . "Each URL must clearly be a thread / topic / discussion (contains forum/thread/topic/discussion/comments/support or is a Q&A/issue page).\n"
         . "If you find multiple good domains, include 1 representative URL per domain (not the homepage).\n"
         . "Do NOT invent domains. Skip social networks (t.me, vk.com, facebook.com, x.com, twitter.com, instagram.com, tiktok.com, youtube.com).\n"
         . "Use at most {$toolLimitDiscovery} web_search tool calls; avoid redundant queries.\n"
         . "At the end output ONLY the JSON.";
    $user = "Targets / theme:\n{$prompt}\nGoal: discover new domains hosting relevant discussions. Exclude already known domains. Output representative discussion URLs (one per domain). Fields: url,title,domain.";
    $jobs[] = ['name'=>'discover_forums','sys'=>$sys,'user'=>$user,'country'=>null,'purpose'=>'discovery','max_tool_calls'=>$toolLimitDiscovery];
}

$MAX_DOMAIN_JOBS = 25; // limit per scan for cost control
$domainJobHosts = array_slice($activeHosts,0,$MAX_DOMAIN_JOBS);

foreach ($searchRegions as $regCode) {
    $regionLine = $regionLineTpl($regCode);

    // LEGACY multi-domain scope disabled (replaced by per-domain jobs)
    // Per-domain targeted jobs (only active, skip paused)
    if ($scopeDomains && !empty($domainJobHosts)) {
        foreach ($domainJobHosts as $h) {
            $sys = "You are a site-focused monitoring agent. Extract recent discussion/forum/Q&A/support THREAD URLs from this SINGLE domain: {$h}.\n"
                 . $langLine . $regionLine
                 . $nowPref . "\n"
                 . "Domain: {$h}. Use web_search with diverse queries (site:{$h} plus topical keywords, forum/thread/topic/discussion/support/comments/review/issue).\n"
                 . "Return ONLY unique canonical URLs from {$h}. 5–25 URLs if available. Exclude homepages, tag indexes, pure landing/marketing pages.\n"
                 . "Use at most {$toolLimitPerDomain} web_search tool calls focused on this domain.\n"
                 . "STRICT JSON only at end.";
            $user = "Targets / theme:\n{$prompt}\nConstraints:\n- Only domain {$h}\n- Real threads (discussion / topic / question / issue / review)\n- Output fields: url,title,domain";
            $jobs[] = ['name'=>'domain:'.$h.($regCode?":$regCode":''),'sys'=>$sys,'user'=>$user,'country'=>$regCode,'purpose'=>'per_domain','max_tool_calls'=>$toolLimitPerDomain];
        }
    }

    // TELEGRAM SCOPE (unchanged)
    if ($scopeTelegram) {
        $modeLine = ($telegramMode === 'discuss')
            ? "Include only Telegram groups/channels that allow replies or comments; prefer URLs like t.me/c/<id>/<msg> or t.me/<name>/<post>. Avoid bare channel homepages without a post id."
            : "Include Telegram post URLs on t.me (with message ids). Avoid bare channel homepages without a post id.";
        $sys = "You are a web monitoring agent focused ONLY on Telegram posts on t.me.\n"
             . $langLine . $regionLine
             . "Return STRICT JSON only: {\"links\":[{\"url\":\"...\",\"title\":\"...\",\"domain\":\"...\"}]}. No prose.\n"
             . $nowPref . "\n"
             . "Search via site:t.me queries (channels and groups). Do NOT include or query any other domains.\n"
             . $modeLine . "\n"
             . "Return 10–40 unique post URLs.\n"
             . "Use at most {$toolLimitTelegram} web_search tool calls (site:t.me queries only).\n"
             . "At the end, output ONLY the JSON per schema.";
        $user = "Targets:\n{$prompt}\nConstraints:\n- Domain MUST be t.me\n- Unique canonical post URLs only\n- Output fields: url, title, domain.";
        $jobs[] = ['name' => 'telegram' . ($regCode?":$regCode":''), 'sys' => $sys, 'user' => $user, 'country'=>$regCode,'purpose'=>'telegram','max_tool_calls'=>$toolLimitTelegram];
    }

    // FORUMS SCOPE (keep as broad multi-forum aggregator) — only if we still want a broad sweep
    if ($scopeForums) {
        $sys = "You are a web monitoring agent focused ONLY on forums and discussion platforms (not social networks).\n"
             . $langLine . $regionLine
             . "Return STRICT JSON only: {\"links\":[{\"url\":\"...\",\"title\":\"...\",\"domain\":\"...\"}]}. No prose.\n"
             . $nowPref . "\n"
             . "Include only real discussions: URL patterns like /forum, /forums, /topic, /thread, /discussion, /comments, /support, /r/, question pages on Stack Overflow/StackExchange, GitHub Issues/Discussions, and product community portals.\n"
             . "EXCLUDE Telegram and social networks entirely (do not include or query t.me, vk.com, facebook.com, x.com, twitter.com, instagram.com, tiktok.com, youtube.com). Never use site:t.me in this scope.\n"
             . "Avoid homepages, marketing/landing pages, pricing, docs, blogs.\n"
             . "Use up to 12 diverse queries mixing target keywords with operators (forum, topic, thread, discussion, support, comments, reddit, stackoverflow, review) and language/geo variants if provided.\n"
             . "Return 10–40 unique discussion URLs.\n"
             . "Use at most {$toolLimitForums} web_search tool calls overall; prioritize high-signal queries.\n"
             . "At the end, output ONLY the JSON per schema.";
        $user = "Targets:\n{$prompt}\nOutput fields: url, title, domain. Unique URLs only.";
        $jobs[] = ['name' => 'forums' . ($regCode?":$regCode":''), 'sys' => $sys, 'user' => $user, 'country'=>$regCode,'purpose'=>'forums','max_tool_calls'=>$toolLimitForums];
    }
}

// ----------------------- Execute jobs & persist incrementally -----------------------
$seenUrls = [];
$allLinks = [];
$bumpedAny = false;
$jobStats = [];
$found = 0;
$new = 0;
$newLinks = [];
$scanProgressStmt = null;
if ($scanId) {
    try {
        $scanProgressStmt = pdo()->prepare("UPDATE scans SET status='running', found_links=?, new_links=? WHERE id=?");
    } catch (Throwable $e) {
        $scanProgressStmt = null;
    }
}

$scanState['status'] = 'running';
$hasError = false;
$errorMessage = null;

try {
    foreach ($jobs as $job) {
        [$status, $cnt, $rawLinks, $bumped] = run_openai_job(
            $job['name'], $job['sys'], $job['user'],
            $requestUrl, $requestHeaders, $schema,
            $MAX_OUTPUT_TOKENS, $OPENAI_HTTP_TIMEOUT, $appLog, $job['country'] ?? null, $job['max_tool_calls'] ?? null
        );
        $bumpedAny = $bumpedAny || $bumped;
        $scanState['bumpedAny'] = $bumpedAny;

        $jobLinks = [];
        foreach ($rawLinks as $it) {
            $url = canonicalize_url(arr_get($it, 'url', ''));
            if ($url === '' || isset($seenUrls[$url])) {
                continue;
            }

            $domain = normalize_host(arr_get($it, 'domain', ''));
            if ($domain === '') {
                $domain = normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
            }
            if ($domain === '') {
                continue;
            }

            $seenUrls[$url] = 1;
            $title = trim((string)arr_get($it, 'title', ''));
            $jobLinks[] = [
                'url' => $url,
                'title' => $title,
                'domain' => $domain,
                'content_updated_at' => null,
                '__job' => $job['name'],
                '__purpose' => $job['purpose'] ?? null
            ];
        }

        $jobStats[$job['name']] = ['status' => $status, 'count' => $cnt, 'saved' => count($jobLinks)];
        $scanState['jobStats'] = $jobStats;

        if ($status !== 200) {
            $hasError = true;
            $errorMessage = "HTTP {$status} на шаге {$job['name']}";
            $jobStats[$job['name']]['error'] = $errorMessage;
            $scanState['jobStats'] = $jobStats;
            $scanState['error'] = $errorMessage;
            app_log('error', 'scan', 'Job failed', ['job' => $job['name'], 'status' => $status]);
            break;
        }

        if (empty($jobLinks)) {
            continue;
        }

        $persisted = save_links_batch($jobLinks);
        $found += $persisted['found'];
        $new += $persisted['new'];
        if (!empty($persisted['new_links'])) {
            $newLinks = array_merge($newLinks, $persisted['new_links']);
        }
        $allLinks = array_merge($allLinks, $jobLinks);

        $scanState['found'] = $found;
        $scanState['new'] = $new;
        $scanState['newLinks'] = $newLinks;
        $scanState['allLinks'] = $allLinks;
        $scanState['jobStats'] = $jobStats;
        $scanState['bumpedAny'] = $bumpedAny;

        if ($scanProgressStmt) {
            try {
                $scanProgressStmt->execute([$found, $new, $scanId]);
            } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) {
    $hasError = true;
    $errorMessage = 'Unhandled exception: ' . $e->getMessage();
    $scanState['error'] = $errorMessage;
    app_log('error', 'scan', 'Unhandled exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}

$scanState['found'] = $found;
$scanState['new'] = $new;
$scanState['newLinks'] = $newLinks;
$scanState['allLinks'] = $allLinks;
$scanState['jobStats'] = $jobStats;
$scanState['bumpedAny'] = $bumpedAny;
if ($errorMessage) {
    $scanState['error'] = $errorMessage;
}

$finalStatus = $hasError ? 'error' : 'done';
finalize_scan($scanState, $finalStatus, $errorMessage);

// ----------------------- Response to caller -----------------------
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => !$hasError,
    'scan_id' => $scanId,
    'found' => $found,
    'new' => $new,
    'bumped_any' => $bumpedAny,
    'job_stats' => $jobStats,
    'status' => $scanState['status'] ?? $finalStatus,
    'error' => $scanState['error'] ?? $errorMessage,
], JSON_UNESCAPED_UNICODE);
