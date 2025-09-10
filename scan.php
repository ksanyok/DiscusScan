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
try {
    $q = pdo()->query("SELECT host FROM sources WHERE is_active=1 ORDER BY host");
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $h = normalize_host((string)$row['host']);
        if ($h !== '') $activeHosts[] = $h;
    }
} catch (Throwable $e) {}

// ----------------------- DB: start scan row -----------------------
$scanId = null;
try {
    $ins = pdo()->prepare("INSERT INTO scans (started_at, status, model, prompt) VALUES (NOW(), 'started', ?, ?)");
    $ins->execute([$model, $prompt]);
    $scanId = (int)pdo()->lastInsertId();
} catch (Throwable $e) {}

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

/**
 * Call OpenAI Responses once with provided sys/user texts.
 * Returns [status,int_links,array links,bool bumped]
 */
function run_openai_job(string $jobName, string $sys, string $user, string $requestUrl, array $requestHeaders, array $schema, int $maxTokens, int $timeout, callable $log): array {
    $model = (string)get_setting('openai_model', 'gpt-5-mini');

    // Base payload: no explicit "reasoning" to avoid burning reasoning tokens.
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
        'tools' => [ ['type' => 'web_search'] ],
        'tool_choice' => 'auto'
    ];

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
                    . "\n\nFALLBACK MODE:\nReturn ONLY newline-separated lines in the format: <url>\\t<title>.\nNo prose, no JSON, no bullets. If nothing found, return an empty output."],
                ['role' => 'user', 'content' => $user . "\nReturn ONLY the list as specified."]
            ],
            'tools' => [ ['type' => 'web_search'] ],
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

// ----------------------- Build jobs per scope -----------------------
$jobs = [];
$nowPref = ($lastScanAt !== '')
    ? "Prefer pages created or updated AFTER {$lastScanAt} UTC; otherwise last 12 months."
    : "Prefer results from the last 30 days; if none, include older (up to 12 months).";

// DOMAINS SCOPE
if ($scopeDomains && !empty($activeHosts)) {
    $hostList = implode(', ', array_slice($activeHosts, 0, 300));

    $sys = "You are a web monitoring agent focused on a FIXED set of domains.\n"
         . "Return STRICT JSON only: {\"links\":[{\"url\":\"...\",\"title\":\"...\",\"domain\":\"...\"}]}. No prose.\n"
         . $nowPref . "\n"
         . "Only include results whose host is in this allowlist: " . $hostList . ".\n"
         . "EXCLUDE Telegram and social networks entirely (do not include t.me, vk.com, facebook.com, x.com, twitter.com, instagram.com, tiktok.com, youtube.com). Do not query them.\n"
         . "Use the web_search tool with diverse queries in Russian and English; aggressively use site:&lt;host&gt; operators.\n"
         . "Return canonical content URLs (not homepages). Return 10–100 unique URLs.\n"
         . "At the end, output ONLY the JSON per schema.";

    $user = "Targets:\n{$prompt}\n"
          . "Constraints:\n"
          . "- Allowed hosts only (active sources).\n"
          . "- Exclude any social/Telegram domains.\n"
          . "- Unique URLs.\n"
          . "- Prefer threads/discussions/comments/support pages if available; otherwise any page that genuinely mentions the targets.\n"
          . "Output fields: url, title, domain.";

    $jobs[] = ['name' => 'domains', 'sys' => $sys, 'user' => $user];
}

// TELEGRAM SCOPE
if ($scopeTelegram) {
    $modeLine = ($telegramMode === 'discuss')
        ? "Include only Telegram groups/channels that allow replies or comments; prefer URLs like t.me/c/&lt;id&gt;/&lt;msg&gt; or t.me/&lt;name&gt;/&lt;post&gt;. Avoid bare channel homepages without a post id."
        : "Include Telegram post URLs on t.me (with message ids). Avoid bare channel homepages without a post id.";

    $sys = "You are a web monitoring agent focused ONLY on Telegram posts on t.me.\n"
         . "Return STRICT JSON only: {\"links\":[{\"url\":\"...\",\"title\":\"...\",\"domain\":\"...\"}]}. No prose.\n"
         . $nowPref . "\n"
         . "Search via site:t.me queries (channels and groups). Do NOT include or query any other domains.\n"
         . $modeLine . "\n"
         . "Return 10–100 unique post URLs.\n"
         . "At the end, output ONLY the JSON per schema.";

    $user = "Targets:\n{$prompt}\n"
          . "Constraints:\n- Domain MUST be t.me\n- Unique canonical post URLs only\n- Output fields: url, title, domain.";

    $jobs[] = ['name' => 'telegram', 'sys' => $sys, 'user' => $user];
}

// FORUMS SCOPE
if ($scopeForums) {
    $sys = "You are a web monitoring agent focused ONLY on forums and discussion platforms (not social networks).\n"
         . "Return STRICT JSON only: {\"links\":[{\"url\":\"...\",\"title\":\"...\",\"domain\":\"...\"}]}. No prose.\n"
         . $nowPref . "\n"
         . "Include only real discussions: URL patterns like /forum, /forums, /topic, /thread, /discussion, /comments, /support, /r/, question pages on Stack Overflow/StackExchange, GitHub Issues/Discussions, and product community portals.\n"
         . "EXCLUDE Telegram and social networks entirely (do not include or query t.me, vk.com, facebook.com, x.com, twitter.com, instagram.com, tiktok.com, youtube.com). Never use site:t.me in this scope.\n"
         . "Avoid homepages, marketing/landing pages, pricing, docs, blogs.\n"
         . "Use at least 12–16 diverse queries in RU and EN, combining target keywords with operators (forum, topic, thread, discussion, support, comments, reddit, stackoverflow, review).\n"
         . "Return 10–100 unique discussion URLs.\n"
         . "At the end, output ONLY the JSON per schema.";

    $user = "Targets:\n{$prompt}\nOutput fields: url, title, domain. Unique URLs only.";

    $jobs[] = ['name' => 'forums', 'sys' => $sys, 'user' => $user];
}

// FALLBACK generic job if user disabled everything accidentally
if (empty($jobs)) {
    $sys = "You are a web monitoring agent. Use the web_search tool to find RECENT web pages that mention the targets described by the user.\n"
         . "Return STRICT JSON only: {\"links\":[{\"url\":\"...\",\"title\":\"...\",\"domain\":\"...\"}]}. No prose.\n"
         . $nowPref . "\n"
         . "Search broadly across the web (forums, social, blogs, news, Q&A, Telegram, Reddit, support portals).\n"
         . "Return 10–100 unique URLs.";
    $user = "Task:\n{$prompt}\nOutput format:\n- JSON with fields: url, title, domain for each mention.\n- Only unique URLs (no duplicates).";
    $jobs[] = ['name' => 'generic', 'sys' => $sys, 'user' => $user];
}

// ----------------------- Execute jobs & aggregate -----------------------
$allLinks = [];
$bumpedAny = false;
$jobStats = [];

foreach ($jobs as $job) {
    [$status, $cnt, $links, $bumped] = run_openai_job(
        $job['name'], $job['sys'], $job['user'],
        $requestUrl, $requestHeaders, $schema,
        $MAX_OUTPUT_TOKENS, $OPENAI_HTTP_TIMEOUT, $appLog
    );
    $bumpedAny = $bumpedAny || $bumped;
    $jobStats[$job['name']] = ['status' => $status, 'count' => $cnt];

    foreach ($links as $it) { $allLinks[] = $it; }
}

// ----------------------- Local post-processing -----------------------
$seen = [];
$links = [];
foreach ($allLinks as $it) {
    $url = canonicalize_url(arr_get($it, 'url', ''));
    if ($url === '') continue;

    $domain = normalize_host(arr_get($it, 'domain', ''));
    if ($domain === '') {
        $domain = normalize_host(parse_url($url, PHP_URL_HOST) ?: '');
    }
    if ($domain === '') continue;

    if (isset($seen[$url])) continue;
    $seen[$url] = 1;

    $title = trim((string)arr_get($it, 'title', ''));
    $links[] = ['url' => $url, 'title' => $title, 'domain' => $domain];
}

// ----------------------- Save results -----------------------
$found = 0; $new = 0;
foreach ($links as $it) {
    $url = $it['url'];
    $title = $it['title'];
    $domain = $it['domain'];

    // Ensure source exists (we honor is_active only on search time; saving is always allowed)
    $stmt = pdo()->prepare("SELECT id FROM sources WHERE host=? LIMIT 1");
    $stmt->execute([$domain]);
    $srcId = $stmt->fetchColumn();
    if (!$srcId) {
        $ins = pdo()->prepare("INSERT INTO sources (host, url, is_active, note) VALUES (?,?,1,'discovered')");
        $ins->execute([$domain, 'https://' . $domain]);
        $srcId = (int)pdo()->lastInsertId();
    }

    // Upsert link
    $found++;
    $q = pdo()->prepare("SELECT id, times_seen FROM links WHERE url=? LIMIT 1");
    $q->execute([$url]);
    $row = $q->fetch();
    if ($row) {
        $times = (int)$row['times_seen'] + 1;
        $u = pdo()->prepare("UPDATE links SET title=?, last_seen=NOW(), times_seen=? WHERE id=?");
        $u->execute([$title, $times, $row['id']]);
    } else {
        $ins = pdo()->prepare("INSERT INTO links (source_id, url, title, first_found, last_seen, times_seen, status) VALUES (?,?,?,NOW(),NOW(),1,'new')");
        $ins->execute([$srcId, $url, $title]);
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
    foreach ($jobStats as $name => $st) {
        $lines[] = "— {$name}: статус {$st['status']}, найдено примерно {$st['count']}";
    }
    $lines[] = "Итого ссылок: {$found}";
    $lines[] = "Новых: {$new}";
    $lines[] = "Время: " . date('Y-m-d H:i');

    $txt = implode("\n", $lines);
    $tgUrl = "https://api.telegram.org/bot{$tgToken}/sendMessage";
    $chT = curl_init($tgUrl);
    curl_setopt_array($chT, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => [ 'chat_id' => $tgChat, 'text' => $txt, 'disable_web_page_preview' => 1 ],
        CURLOPT_TIMEOUT => 15
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
    'bumped_any' => $bumpedAny,
    'job_stats' => $jobStats,
], JSON_UNESCAPED_UNICODE);