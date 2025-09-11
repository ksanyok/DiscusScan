<?php
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/db.php';
require_login();

$models = [
    'gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o'
];

$ok = '';
$err = '';
$apiTestMsg = '';
$apiTestOk = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $err = 'Неверный токен безопасности';
        app_log('warning', 'settings', 'CSRF token mismatch', []);
    } else if ($action === 'test_openai') {
        // Проверка ключа OpenAI через Responses API
        $apiKeyToTest = (string)get_setting('openai_api_key', '');
        $modelToTest  = (string)get_setting('openai_model', 'gpt-5-mini');
        if ($apiKeyToTest === '') {
            $apiTestOk = false;
            $apiTestMsg = 'Ключ OpenAI не задан в настройках.';
        } else {
            $UA = 'DiscusScan/' . (defined('APP_VERSION') ? APP_VERSION : 'dev');
            $payload = [
                'model' => $modelToTest,
                'input' => [
                    [ 'role' => 'system', 'content' => [[ 'type' => 'input_text', 'text' => 'You are a checker. Output strict JSON with schema {"ok": boolean} only.' ]] ],
                    [ 'role' => 'user',   'content' => [[ 'type' => 'input_text', 'text' => 'Return {"ok": true} only.' ]] ]
                ],
                'max_output_tokens' => 16,
                'temperature' => 0.0,
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'ping',
                        'json_schema' => [
                            'name' => 'ping',
                            'schema' => [
                                'type' => 'object',
                                'properties' => [ 'ok' => [ 'type' => 'boolean' ] ],
                                'required' => ['ok'],
                                'additionalProperties' => false
                            ],
                            'strict' => true
                        ]
                    ]
                ]
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKeyToTest,
            ];
            $ch = curl_init('https://api.openai.com/v1/responses');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => $UA
            ]);
            $resp = curl_exec($ch);
            $info = curl_getinfo($ch);
            $status = (int)($info['http_code'] ?? 0);
            $body = substr((string)$resp, (int)($info['header_size'] ?? 0));
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($status === 200) {
                $apiTestOk = true;
                $apiTestMsg = 'Ключ рабочий (HTTP 200). Модель: ' . e($modelToTest);
            } else {
                $apiTestOk = false;
                $errDetail = '';
                $dec = json_decode($body, true);
                if (isset($dec['error']['message'])) { $errDetail = (string)$dec['error']['message']; }
                elseif ($cerr) { $errDetail = $cerr; }
                $apiTestMsg = 'Ошибка проверки ключа (HTTP ' . $status . '). ' . $errDetail;
            }
        }
    } else {
        // базовые настройки (сохранение)
        set_setting('openai_api_key', trim($_POST['openai_api_key'] ?? ''));
        set_setting('openai_model', in_array($_POST['openai_model'] ?? '', $models, true) ? $_POST['openai_model'] : 'gpt-5-mini');
        set_setting('scan_period_min', max(1, (int)($_POST['scan_period_min'] ?? 15)));
        set_setting('search_prompt', trim($_POST['search_prompt'] ?? ''));

        // telegram для уведомлений
        set_setting('telegram_token', trim($_POST['telegram_token'] ?? ''));
        set_setting('telegram_chat_id', trim($_POST['telegram_chat_id'] ?? ''));

        // НОВЫЕ ОБЛАСТИ ПОИСКА
        set_setting('scope_domains_enabled', isset($_POST['scope_domains_enabled']));
        set_setting('scope_telegram_enabled', isset($_POST['scope_telegram_enabled']));
        $telegram_mode = $_POST['telegram_mode'] ?? 'any';
        if (!in_array($telegram_mode, ['any','discuss'], true)) $telegram_mode = 'any';
        set_setting('telegram_mode', $telegram_mode);
        set_setting('scope_forums_enabled', isset($_POST['scope_forums_enabled']));

        // CRON секрет
        $cron_secret = trim($_POST['cron_secret'] ?? '');
        if ($cron_secret === '') $cron_secret = bin2hex(random_bytes(12));
        set_setting('cron_secret', $cron_secret);

        // FRESH-ONLY параметры
        $freshnessDays = max(1, (int)($_POST['freshness_days'] ?? 7));
        set_setting('freshness_days', $freshnessDays);
        set_setting('enabled_sources_only', isset($_POST['enabled_sources_only']));
        $maxRes = (int)($_POST['max_results_per_scan'] ?? 80);
        if ($maxRes < 1) $maxRes = 1; if ($maxRes > 200) $maxRes = 200;
        set_setting('max_results_per_scan', $maxRes);
        set_setting('return_schema_required', isset($_POST['return_schema_required']));

        // опциональные — массивы языков/регионов (через запятую)
        $langs = trim((string)($_POST['languages'] ?? ''));
        $regs  = trim((string)($_POST['regions'] ?? ''));
        set_setting('languages', $langs === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', $langs))))));
        set_setting('regions',  $regs  === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', $regs))))));

        $ok = 'Сохранено';
        app_log('info', 'settings', 'Settings updated', []);
    }
}

// текущие значения
$apiKey = (string)get_setting('openai_api_key', '');
$model = (string)get_setting('openai_model', 'gpt-5-mini');
$period = (int)get_setting('scan_period_min', 15);
$prompt = (string)get_setting('search_prompt', '');

$tgToken = (string)get_setting('telegram_token', '');
$tgChat = (string)get_setting('telegram_chat_id', '');

$scopeDomains  = (bool)get_setting('scope_domains_enabled', false);
$scopeTelegram = (bool)get_setting('scope_telegram_enabled', false);
$telegramMode  = (string)get_setting('telegram_mode', 'any'); // any|discuss
$scopeForums   = (bool)get_setting('scope_forums_enabled', true);

// Fresh-only
$freshnessDays       = (int)get_setting('freshness_days', 7);
$enabledSourcesOnly  = (bool)get_setting('enabled_sources_only', true);
$maxResultsPerScan   = (int)get_setting('max_results_per_scan', 80);
$returnSchemaRequired= (bool)get_setting('return_schema_required', true);
$languagesArr        = (array)get_setting('languages', []);
$regionsArr          = (array)get_setting('regions', []);
$languagesCsv        = $languagesArr ? implode(', ', $languagesArr) : '';
$regionsCsv          = $regionsArr ? implode(', ', $regionsArr) : '';

// вспомогательное
$cronSecret = (string)get_setting('cron_secret', '');
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cronUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/scan.php?secret=' . urlencode($cronSecret);

// количество активных доменов и ссылка на их управление
try {
    // активные = is_enabled=1 и is_paused=0 (если колонок нет — fallback к is_active)
    $activeDomainsCount = (int)pdo()->query("SELECT COUNT(*) FROM sources WHERE COALESCE(is_enabled,1)=1 AND COALESCE(is_paused,0)=0")->fetchColumn();
} catch (Throwable $e) {
    $activeDomainsCount = (int)get_setting('active_sources_count', 0); // fallback, если нет таблицы
}
$sourcesUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/sources.php';

// Предпросмотр SINCE (UTC)
try {
    $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P' . max(1, (int)$freshnessDays) . 'D'))
        ->format('Y-m-d\TH:i:s\Z');
} catch (Throwable $e) { $since = ''; }
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Настройки — Мониторинг</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
  <div class="card glass">
    <div class="card-title">Параметры</div>
    <?php if ($ok): ?><div class="alert success"><?=$ok?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert danger"><?=$err?></div><?php endif; ?>
    <?php if ($apiTestOk === true): ?><div class="alert success"><?=e($apiTestMsg)?></div><?php endif; ?>
    <?php if ($apiTestOk === false): ?><div class="alert danger"><?=e($apiTestMsg)?></div><?php endif; ?>
    <form method="post" class="stack settings-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      
      <label>OpenAI API Key
        <div class="row-gap">
          <input type="password" name="openai_api_key" value="<?=e($apiKey)?>" placeholder="sk-...">
          <button formaction="" formmethod="post" name="action" value="test_openai" class="btn">Проверить ключ</button>
        </div>
      </label>

      <label>Модель агента
        <select name="openai_model">
          <?php foreach ($models as $m): ?>
            <option value="<?=e($m)?>" <?=$m===$model?'selected':''?>><?=e($m)?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Промпт (что и где искать)
        <textarea name="search_prompt" rows="5" placeholder="Опиши задачу для агента..."><?=e($prompt)?></textarea>
      </label>

      <!-- НОВЫЙ блок: области поиска -->
      <hr>
      <div class="card-title">Где искать</div>

      <div class="scope-row">

        <label class="switch-card">
          <input class="switch" type="checkbox" name="scope_domains_enabled" <?=$scopeDomains?'checked':''?>>
          <div class="switch-title">По моим доменам</div>
          <div class="switch-sub">
            <span class="pill"><?= (int)$activeDomainsCount ?></span>
            <a class="btn-link" href="<?=e($sourcesUrl)?>" target="_blank">посмотреть</a>
          </div>
        </label>

        <label class="switch-card">
          <input class="switch" type="checkbox" name="scope_telegram_enabled" <?=$scopeTelegram?'checked':''?>>
          <div class="switch-title">В Telegram</div>
          <div class="switch-sub stack compact">
            <select name="telegram_mode" class="select-compact">
              <option value="any" <?=$telegramMode==='any'?'selected':''?>>Любые каналы и группы</option>
              <option value="discuss" <?=$telegramMode==='discuss'?'selected':''?>>Только где можно писать/отвечать</option>
            </select>
          </div>
        </label>

        <label class="switch-card">
          <input class="switch" type="checkbox" name="scope_forums_enabled" <?=$scopeForums?'checked':''?>>
          <div class="switch-title">Форумы и сообщества</div>
        </label>

      </div>

      <div class="grid-2">
        <label>Период проверки, минут
          <input type="number" name="scan_period_min" value="<?=$period?>" min="1">
        </label>
        <label>CRON Secret
          <input type="text" name="cron_secret" value="<?=e($cronSecret)?>">
        </label>
      </div>

      <div class="grid-2">
        <label>Telegram Bot Token
          <input type="text" name="telegram_token" value="<?=e($tgToken)?>" placeholder="123456:ABC-DEF...">
        </label>
        <label>Telegram Chat ID
          <input type="text" name="telegram_chat_id" value="<?=e($tgChat)?>" placeholder="@channel или ID">
        </label>
      </div>

      <hr>
      <div class="card-title">Fresh-only мониторинг</div>
      <div class="grid-2">
        <label>Окно свежести, дней
          <input type="number" name="freshness_days" value="<?= (int)$freshnessDays ?>" min="1" max="90">
        </label>
        <label>Лимит результатов за скан
          <input type="number" name="max_results_per_scan" value="<?= (int)$maxResultsPerScan ?>" min="1" max="200">
        </label>
      </div>
      <div class="grid-2">
        <label>
          <span>Только включённые источники</span>
          <input class="switch" type="checkbox" name="enabled_sources_only" <?=$enabledSourcesOnly?'checked':''?>>
        </label>
        <label>
          <span>Требовать строгий JSON от модели</span>
          <input class="switch" type="checkbox" name="return_schema_required" <?=$returnSchemaRequired?'checked':''?>>
        </label>
      </div>
      <div class="grid-2">
        <div class="hint">SINCE (UTC): <code><?=e($since)?></code></div>
      </div>

      <div class="grid-2">
        <label>Языки (опц.), через запятую
          <input type="text" name="languages" value="<?=e($languagesCsv)?>" placeholder="ru, uk, en">
        </label>
        <label>Регионы (опц.), через запятую
          <input type="text" name="regions" value="<?=e($regionsCsv)?>" placeholder="UA, RU, EU">
        </label>
      </div>

      <div class="hint">CRON URL: <code><?=e($cronUrl)?></code></div>
      <div class="hint">CLI: <code>php <?=e(__DIR__ . '/scan.php')?></code></div>

      <button class="btn primary">Сохранить</button>
    </form>
  </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>