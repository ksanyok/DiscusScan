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
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
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
    } else if ($action === 'clear_domains') {
        try {
            pdo()->exec("DELETE FROM sources");
        } catch (Throwable $e) {}
        try { pdo()->exec("DELETE FROM discovered_sources"); } catch (Throwable $e) {}
        app_log('info', 'settings', 'All domains cleared', []);
        $ok = 'Все домены (и кандидаты) очищены';
    } else if ($action === 'clear_links') {
        try { pdo()->exec("DELETE FROM links"); } catch (Throwable $e) {}
        app_log('info', 'settings', 'All links cleared', []);
        $ok = 'Все найденные ссылки очищены';
    } else {
        set_setting('openai_api_key', trim($_POST['openai_api_key'] ?? ''));
        set_setting('openai_model', in_array($_POST['openai_model'] ?? '', $models, true) ? $_POST['openai_model'] : 'gpt-5-mini');
        set_setting('scan_period_min', max(1, (int)($_POST['scan_period_min'] ?? 15)));
        set_setting('search_prompt', trim($_POST['search_prompt'] ?? ''));

        set_setting('telegram_token', trim($_POST['telegram_token'] ?? ''));
        set_setting('telegram_chat_id', trim($_POST['telegram_chat_id'] ?? ''));

        set_setting('scope_domains_enabled', isset($_POST['scope_domains_enabled']));
        set_setting('scope_telegram_enabled', isset($_POST['scope_telegram_enabled']));
        $telegram_mode = $_POST['telegram_mode'] ?? 'any';
        if (!in_array($telegram_mode, ['any','discuss'], true)) $telegram_mode = 'any';
        set_setting('telegram_mode', $telegram_mode);
        set_setting('scope_forums_enabled', isset($_POST['scope_forums_enabled']));

        $cron_secret = trim($_POST['cron_secret'] ?? '');
        if ($cron_secret === '') $cron_secret = bin2hex(random_bytes(12));
        set_setting('cron_secret', $cron_secret);

        $freshnessDays = max(1, (int)($_POST['freshness_days'] ?? 7));
        set_setting('freshness_days', $freshnessDays);

        $langs = trim((string)($_POST['languages'] ?? ''));
        $regs  = trim((string)($_POST['regions'] ?? ''));
        set_setting('languages', $langs === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', $langs))))));
        set_setting('regions',  $regs  === '' ? [] : array_values(array_unique(array_filter(array_map('trim', explode(',', $regs))))));

        $ok = 'Сохранено';
        app_log('info', 'settings', 'Settings updated', []);
    }
}

$apiKey = (string)get_setting('openai_api_key', '');
$model = (string)get_setting('openai_model', 'gpt-5-mini');
$period = (int)get_setting('scan_period_min', 15);
$prompt = (string)get_setting('search_prompt', '');

$tgToken = (string)get_setting('telegram_token', '');
$tgChat = (string)get_setting('telegram_chat_id', '');

$scopeDomains  = (bool)get_setting('scope_domains_enabled', false);
$scopeTelegram = (bool)get_setting('scope_telegram_enabled', false);
$telegramMode  = (string)get_setting('telegram_mode', 'any');
$scopeForums   = (bool)get_setting('scope_forums_enabled', true);

$freshnessDays       = (int)get_setting('freshness_days', 7);
$languagesArr        = (array)get_setting('languages', []);
$regionsArr          = (array)get_setting('regions', []);
$languagesCsv        = $languagesArr ? implode(', ', $languagesArr) : '';
$regionsCsv          = $regionsArr ? implode(', ', $regionsArr) : '';

$cronSecret = (string)get_setting('cron_secret', '');
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cronUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/scan.php?secret=' . urlencode($cronSecret);

try {
    $activeDomainsCount = (int)pdo()->query("SELECT COUNT(*) FROM sources WHERE COALESCE(is_enabled,1)=1 AND COALESCE(is_paused,0)=0")->fetchColumn();
} catch (Throwable $e) {
    $activeDomainsCount = (int)get_setting('active_sources_count', 0);
}
$sourcesUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/sources.php';

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
  <style>
    .wizard-modal {position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.55);z-index:1000;}
    .wizard-box {background:#1e1f24;padding:1.25rem;max-width:560px;width:100%;border-radius:12px;box-shadow:0 4px 30px rgba(0,0,0,.4);}
    .wizard-steps {display:flex;gap:.5rem;margin-bottom:1rem;}
    .wizard-step {flex:1;height:4px;background:#444;border-radius:2px;}
    .wizard-step.active {background:#4ea1ff;}
    .wizard-actions {display:flex;justify-content:space-between;margin-top:1rem;}
    .small-input {width:100%;}
    .taglist {display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.4rem;}
    .taglist span {background:#2c2d33;padding:.25rem .5rem;border-radius:6px;font-size:.75rem;}
    .danger-inline {background:#4d1f1f;color:#fff;}
  </style>
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

      <div class="stack">
        <div class="row-gap" style="align-items:center;justify-content:space-between;">
          <div>
            <strong>Мастер запроса</strong>
            <div class="hint">Пошагово уточняет задачу и формирует внутренний поисковый промпт.</div>
          </div>
          <button type="button" class="btn" id="wizardStart">Запустить мастера</button>
        </div>
        <div class="hint" id="wizardPromptPreview"><?= $prompt ? e(mb_strimwidth($prompt,0,140,'…')) : 'Промпт ещё не задан' ?></div>
        <input type="hidden" name="search_prompt" id="search_prompt" value="<?= e($prompt) ?>">
      </div>

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

    <hr>
    <div class="card-title">Очистка данных</div>
    <div class="stack">
      <form method="post" onsubmit="return confirm('Удалить ВСЕ домены и кандидатов? Это удалит также связанные ссылки.');" class="row-gap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_domains">
        <button class="btn danger">Очистить домены</button>
      </form>
      <form method="post" onsubmit="return confirm('Удалить все найденные ссылки?');" class="row-gap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_links">
        <button class="btn danger">Очистить ссылки</button>
      </form>
    </div>

  </div>
</main>

<div class="wizard-modal" id="wizardModal" aria-hidden="true">
  <div class="wizard-box">
    <div class="wizard-steps" id="wizardSteps">
      <div class="wizard-step" data-step="0"></div>
      <div class="wizard-step" data-step="1"></div>
      <div class="wizard-step" data-step="2"></div>
      <div class="wizard-step" data-step="3"></div>
    </div>
    <div id="wizardBody" class="stack" style="gap:.75rem;min-height:180px"></div>
    <div class="wizard-actions">
      <button type="button" class="btn" id="wizardPrev" disabled>Назад</button>
      <div class="row-gap" style="gap:.5rem;">
        <button type="button" class="btn" id="wizardCancel">Отмена</button>
        <button type="button" class="btn primary" id="wizardNext">Далее</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('wizardModal');
  const startBtn = document.getElementById('wizardStart');
  const prevBtn = document.getElementById('wizardPrev');
  const nextBtn = document.getElementById('wizardNext');
  const cancelBtn = document.getElementById('wizardCancel');
  const bodyEl = document.getElementById('wizardBody');
  const stepsEls = Array.from(document.querySelectorAll('.wizard-step'));
  const promptInput = document.getElementById('search_prompt');
  const preview = document.getElementById('wizardPromptPreview');

  let step = 0;
  const data = { goal:'', where:[], keywords:[], languages:[], regions:[] };

  function render(){
    stepsEls.forEach((el,i)=> el.classList.toggle('active', i<=step));
    prevBtn.disabled = step===0;
    nextBtn.textContent = step===steps.length-1 ? 'Готово' : 'Далее';
    bodyEl.innerHTML = steps[step]();
  }

  function buildPrompt(){
    const parts = [];
    if (data.goal) parts.push('Цель: '+data.goal.trim());
    if (data.keywords.length) parts.push('Ключевые слова: '+data.keywords.join(', '));
    if (data.where.length) parts.push('Области: '+data.where.join(', '));
    if (data.languages.length) parts.push('Языки: '+data.languages.join(', '));
    if (data.regions.length) parts.push('Регионы: '+data.regions.join(', '));
    parts.push('Вернуть уникальные обсуждения за последние '+(<?= (int)$freshnessDays ?>)+' дней.');
    return parts.join('\n');
  }

  function collect(){
    if (step===0) { data.goal = document.getElementById('w_goal').value; }
    if (step===1) { data.keywords = document.getElementById('w_keywords').value.split(',').map(s=>s.trim()).filter(Boolean); }
    if (step===2) {
      data.where = Array.from(document.querySelectorAll('input[name="w_where[]"]:checked')).map(i=>i.value);
    }
    if (step===3) {
      data.languages = document.getElementById('w_langs').value.split(',').map(s=>s.trim()).filter(Boolean);
      data.regions   = document.getElementById('w_regs').value.split(',').map(s=>s.trim()).filter(Boolean);
    }
  }

  const steps = [
    ()=>`<label>Что ищем?<textarea id="w_goal" rows="4" class="small-input" placeholder="Например: упоминания моего бренда и плагина ...">${data.goal||''}</textarea></label>`,
    ()=>`<label>Ключевые слова (через запятую)<input id="w_keywords" type="text" class="small-input" value="${data.keywords.join(', ')}" placeholder="бренд, плагин, домен"></label>`,
    ()=>`<fieldset style="border:0;padding:0;" class="stack"><legend>Где искать</legend>
          <label><input type="checkbox" name="w_where[]" value="домены" ${data.where.includes('домены')?'checked':''}> Мои домены</label>
          <label><input type="checkbox" name="w_where[]" value="telegram" ${data.where.includes('telegram')?'checked':''}> Telegram</label>
          <label><input type="checkbox" name="w_where[]" value="форумы" ${data.where.includes('форумы')?'checked':''}> Форумы/сообщества</label>
        </fieldset>`,
    ()=>`<div class="grid-2"><label>Языки<input id="w_langs" type="text" class="small-input" value="${data.languages.join(', ')}" placeholder="ru, en"></label>
         <label>Регионы<input id="w_regs" type="text" class="small-input" value="${data.regions.join(', ')}" placeholder="UA, RU"></label></div>
         <div class="hint">Нажмите Готово для формирования промпта.</div>`
  ];

  startBtn?.addEventListener('click', ()=>{ step=0; render(); modal.style.display='flex'; modal.setAttribute('aria-hidden','false'); });
  cancelBtn?.addEventListener('click', ()=>{ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); });
  prevBtn?.addEventListener('click', ()=>{ collect(); if(step>0){step--; render();}});
  nextBtn?.addEventListener('click', ()=>{ collect(); if (step<steps.length-1){ step++; render(); } else { 
      const p = buildPrompt();
      promptInput.value = p;
      preview.textContent = p.length>160 ? p.slice(0,160)+'…' : p || 'Промпт ещё не задан';
      modal.style.display='none'; modal.setAttribute('aria-hidden','true');
    }});
  modal.addEventListener('click', e=>{ if(e.target===modal) { modal.style.display='none'; modal.setAttribute('aria-hidden','true'); }});
})();
</script>

<?php include 'footer.php'; ?>
</body>
</html>