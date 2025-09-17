<?php
require_once __DIR__ . '/db.php';
require_login();

$models = [
    'gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o'
];

$ok = '';

// Дополнительные действия (AJAX тест ключа и очистка данных)
function testOpenAIKey(string $key, string $model): array {
    if ($key === '') return ['ok'=>false,'error'=>'Ключ пустой'];
    $t0 = microtime(true);
    $payload = [
        'model' => $model,
        'messages' => [
            ['role'=>'system','content'=>'ping'],
            ['role'=>'user','content'=>'ping']
        ],
        'max_completion_tokens' => 10
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'Expect:'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25
    ]);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $dt = (int)round((microtime(true)-$t0)*1000);
    if ($resp === false) return ['ok'=>false,'error'=>'Curl error: '.$err];
    $data = json_decode($resp,true);
    if ($status===200 && isset($data['choices'][0]['message'])) {
        app_log('info','api_key_test','OK',['model'=>$model,'latency_ms'=>$dt]);
        return ['ok'=>true,'latency_ms'=>$dt,'model'=>$model];
    }
    $preview = mb_substr($resp,0,200);
    app_log('error','api_key_test','Fail',['status'=>$status,'preview'=>$preview]);
    $msg = $data['error']['message'] ?? ('HTTP '.$status);
    return ['ok'=>false,'error'=>$msg,'status'=>$status];
}

// AJAX тест ключа
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='test_api_key') {
    $testKey = trim($_POST['openai_api_key'] ?? '');
    $testModel = trim($_POST['openai_model'] ?? 'gpt-5-mini');
    $res = testOpenAIKey($testKey, $testModel);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

// Очистка данных
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'clear_data')) {
    try {
        pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
        // Сначала дочерние таблицы
        @pdo()->exec('TRUNCATE TABLE links');
        @pdo()->exec('TRUNCATE TABLE topics');
        // Затем связанные результаты
        @pdo()->exec('TRUNCATE TABLE scans');
        @pdo()->exec('TRUNCATE TABLE runs');
        // Таблицы доменов и источников (источники теперь тоже очищаем)
        @pdo()->exec('TRUNCATE TABLE domains');
        @pdo()->exec('TRUNCATE TABLE sources');
        pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
        // Сброс инкрементального маркера, чтобы следующий скан не пытался искать только "после прошлого"
        set_setting('last_scan_at', '');
        $ok = 'Данные очищены (links/topics/scans/runs/domains/sources). Маркер last_scan_at сброшен.';
        app_log('info','maintenance','Data cleared + last_scan_at reset',[]);
    } catch (Throwable $e) {
        try { pdo()->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $e2) {}
        $ok = 'Ошибка очистки: '.$e->getMessage();
        app_log('error','maintenance','Clear failed',['error'=>$e->getMessage()]);
    }
}

// Обработка обычных настроек (исключаем очистку данных, чтобы не затирать ключи пустыми значениями)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['smart_wizard']) && (($_POST['action'] ?? '') !== 'clear_data')) {
    // базовые настройки
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

    // Новые поля: языки и регионы поиска
    $rawLangs = trim($_POST['search_languages'] ?? '');
    $langsArr = [];
    if ($rawLangs !== '') {
        $parts = preg_split('~[;,\s]+~u', $rawLangs, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
            $p = strtolower(trim($p));
            if ($p !== '' && mb_strlen($p) <= 8) $langsArr[] = $p;
        }
        $langsArr = array_values(array_unique($langsArr));
    }
    set_setting('search_languages', $langsArr);

    $rawRegs = trim($_POST['search_regions'] ?? '');
    $regsArr = [];
    if ($rawRegs !== '') {
        $parts = preg_split('~[;,\s]+~u', strtoupper($rawRegs), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $p) {
            $p = strtoupper(trim($p));
            if ($p !== '' && mb_strlen($p) <= 8) $regsArr[] = $p;
        }
        $regsArr = array_values(array_unique($regsArr));
    }
    set_setting('search_regions', $regsArr);

    // CRON секрет
    $cron_secret = trim($_POST['cron_secret'] ?? '');
    if ($cron_secret === '') $cron_secret = bin2hex(random_bytes(12));
    set_setting('cron_secret', $cron_secret);

    $ok = 'Сохранено';
    app_log('info', 'settings', 'Settings updated', []);
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

$searchLangs = get_setting('search_languages', []);
if (is_string($searchLangs)) { $tmp = json_decode($searchLangs, true); if (is_array($tmp)) $searchLangs = $tmp; }
if (!is_array($searchLangs)) $searchLangs = [];
$searchRegions = get_setting('search_regions', []);
if (is_string($searchRegions)) { $tmp = json_decode($searchRegions, true); if (is_array($tmp)) $searchRegions = $tmp; }
if (!is_array($searchRegions)) $searchRegions = [];
$detectedLangs = json_decode((string)get_setting('detected_languages', '[]'), true); if (!is_array($detectedLangs)) $detectedLangs=[];
$detectedRegs  = json_decode((string)get_setting('detected_regions', '[]'), true); if (!is_array($detectedRegs)) $detectedRegs=[];

// вспомогательное
$cronSecret = (string)get_setting('cron_secret', '');
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$cronUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/scan.php?secret=' . urlencode($cronSecret);

// количество активных доменов и ссылка на их управление
try {
    $activeDomainsCount = (int)pdo()->query("SELECT COUNT(*) FROM sources WHERE is_active=1")->fetchColumn();
} catch (Throwable $e) {
    $activeDomainsCount = (int)get_setting('active_sources_count', 0); // fallback, если нет таблицы
}
$sourcesUrl = $baseUrl . dirname($_SERVER['SCRIPT_NAME']) . '/sources.php';
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
    <form method="post" class="stack settings-form">

      <label>OpenAI API Key
        <div style="display:flex; gap:8px; align-items:center;">
          <input type="password" name="openai_api_key" value="<?=e($apiKey)?>" placeholder="sk-..." style="flex:1;">
          <button type="button" class="btn small" id="testApiBtn" style="white-space:nowrap;">Проверить</button>
        </div>
        <div id="apiTestStatus" class="hint" style="min-height:16px; margin-top:4px;"></div>
      </label>

      <label>Модель агента
        <select name="openai_model">
          <?php foreach ($models as $m): ?>
            <option value="<?=e($m)?>" <?=$m===$model?'selected':''?>><?=e($m)?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>Промпт (что и где искать)
        <div style="margin-bottom:8px;">
          <span class="muted" style="font-size:12px;">Опишите что хотите отслеживать (обычный текст). ИИ сам уточнит детали.</span>
        </div>
        <div class="prompt-wrapper with-wizard">
          <textarea name="search_prompt" rows="5" placeholder="Опиши задачу для агента..."><?=e($prompt)?></textarea>
          <button type="button" id="smartWizardBtn" class="wizard-fab" title="Умный мастер" aria-label="Умный мастер генерации промпта">🤖<span class="wf-label">Мастер</span></button>
          <div class="prompt-help" tabindex="0" aria-label="Подсказка по формату промпта">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <div class="prompt-help-bubble">
              <div class="phb-title">Как писать промпт</div>
              <ul>
                <li>Пишите обычным языком — НЕ нужно JSON / код.</li>
                <li>Опишите: что мониторим, цели, ключевые сущности, что исключить.</li>
                <li>Можно перечислить конкурентов / бренды / географию.</li>
                <li>Языки и регионы задайте тут или доверьте мастеру.</li>
                <li>Если сложно — нажмите «Мастер» справа.</li>
              </ul>
              <div class="phb-foot">Пример: Отслеживать упоминания моего SaaS сервиса в RU и PL: отзывы, сравнения с конкурентами, жалобы на скорость, запросы на новые функции.</div>
            </div>
          </div>
        </div>
      </label>

      <label>Языки и регионы поиска
        <div style="display:flex; gap:16px; flex-wrap:wrap; margin-top:8px;">
          <div style="flex:1; min-width:220px;">
            <div style="font-size:12px; font-weight:600; margin-bottom:4px;">Языки</div>
            <input type="text" name="search_languages" id="search_languages_input" value="<?=e(implode(', ', $searchLangs))?>" placeholder="например: ru, en, uk" />
            <?php if ($detectedLangs): ?>
              <div class="hint" style="margin-top:4px;">Рекомендации: 
                <?php foreach ($detectedLangs as $dl): if (!in_array($dl,$searchLangs,true)): ?>
                  <button type="button" class="tag-add" data-add-target="search_languages_input" data-value="<?=e($dl)?>"><?=e($dl)?></button>
                <?php endif; endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div style="flex:1; min-width:220px;">
            <div style="font-size:12px; font-weight:600; margin-bottom:4px;">Регионы</div>
            <input type="text" name="search_regions" id="search_regions_input" value="<?=e(implode(', ', $searchRegions))?>" placeholder="например: UA, PL, DE" />
            <?php if ($detectedRegs): ?>
              <div class="hint" style="margin-top:4px;">Рекомендации: 
                <?php foreach ($detectedRegs as $dr): if (!in_array($dr,$searchRegions,true)): ?>
                  <button type="button" class="tag-add" data-add-target="search_regions_input" data-value="<?=e($dr)?>"><?=e($dr)?></button>
                <?php endif; endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="hint">Укажите языки и регионы (через запятую или пробел). Эти списки будут использоваться при поиске. Клик по рекомендации добавит её в поле.</div>
        <div class="hint" style="margin-top:8px;">
          Пресеты:
          <button type="button" class="preset-btn" data-langs="en, de, fr, es, it" data-regs="DE, FR, GB, ES, IT">Европа (top‑5)</button>
          <button type="button" class="preset-btn" data-langs="en, es, pt, fr, de, ru, ar, zh, hi, ja" data-regs="US, GB, DE, FR, ES, IT, IN, BR, CA, AU">Мир (top‑10)</button>
          <button type="button" class="preset-btn" data-langs="ru, uk, kk, be, uz" data-regs="RU, UA, KZ, BY, UZ">СНГ</button>
          <button type="button" class="preset-btn" data-langs="en, fr, es" data-regs="US, CA">Северная Америка</button>
          <button type="button" class="preset-btn" data-langs="pl, cs, sk, hu, ro, bg" data-regs="PL, CZ, SK, HU, RO, BG">Восточная Европа</button>
        </div>
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

      <div class="hint">CRON URL: <code><?=e($cronUrl)?></code></div>
      <div class="hint">CLI: <code>php <?=e(__DIR__ . '/scan.php')?> </code></div>

      <button class="btn primary">Сохранить</button>
    </form>

    <hr style="margin:28px 0; opacity:0.4;">
    <div class="card-title">Сервис</div>
    <form method="post" onsubmit="return confirm('Удалить ВСЕ найденные ссылки, домены, темы и историю сканов? Действие необратимо. Продолжить?');" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
      <input type="hidden" name="action" value="clear_data">
      <button type="submit" class="btn danger">🗑 Очистить найденные данные</button>
      <span class="muted" style="font-size:12px;">Удалит links, topics, domains, scans, runs</span>
    </form>
  </div>
</main>
<?php include 'footer.php'; ?>

<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<style>
.toast-container{ position:fixed; top:12px; right:12px; display:flex; flex-direction:column; gap:10px; z-index:1200; max-width:300px; }
.toast{ background:var(--card); border:1px solid var(--border); box-shadow:0 4px 18px -4px rgba(0,0,0,0.4); padding:10px 14px; border-radius:12px; font-size:13px; line-height:1.4; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; animation:toastIn .35s ease; }
.toast-success{ border-color:#2e8b57; }
.toast-error{ border-color:#ff4d4f; }
.toast-close{ background:none; border:none; font-size:16px; line-height:1; cursor:pointer; color:var(--muted); padding:0 2px; }
.toast-close:hover{ color:var(--text); }
.toast.hide{ opacity:0; transform:translateY(-6px); transition:.3s; }
@keyframes toastIn{ from{opacity:0; transform:translateY(-6px);} to{opacity:1; transform:translateY(0);} }
.prompt-wrapper{position:relative;}
.prompt-wrapper textarea{padding-right:42px;}
.prompt-help{position:absolute; top:8px; right:8px; width:22px; height:22px; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.06); border:1px solid var(--border); border-radius:6px; cursor:pointer; color:var(--muted); transition:.2s;}
.prompt-help:hover,.prompt-help:focus{color:var(--text); background:rgba(255,255,255,0.1);} 
.prompt-help-bubble{position:absolute; top:28px; right:0; width:340px; background:var(--card); border:1px solid var(--border); padding:14px 16px; border-radius:12px; box-shadow:0 10px 40px -10px rgba(0,0,0,.6); font-size:12.5px; line-height:1.45; display:none; z-index:30;}
.prompt-help:focus .prompt-help-bubble, .prompt-help:hover .prompt-help-bubble{display:block;}
.prompt-help-bubble ul{margin:0 0 8px 18px; padding:0;}
.prompt-help-bubble li{margin:0 0 4px;}
.phb-title{font-weight:600; margin-bottom:6px; font-size:13px;}
.phb-foot{margin-top:6px; font-size:11px; opacity:.8;}
.prompt-wrapper.with-wizard textarea{padding-right:46px; padding-bottom:70px;}
.wizard-fab{position:absolute; bottom:8px; right:8px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:4px; width:64px; height:64px; border:none; cursor:pointer; border-radius:18px; font-size:22px; line-height:1; font-weight:600; background:linear-gradient(135deg,#5b8cff,#8f5bff); color:#fff; box-shadow:0 10px 28px -8px rgba(0,0,0,.55),0 4px 14px -4px rgba(91,140,255,.5); transition:.25s; position:absolute;}
.wizard-fab:hover{transform:translateY(-3px) rotate(-2deg); box-shadow:0 16px 36px -10px rgba(0,0,0,.65),0 6px 18px -6px rgba(91,140,255,.6);}
.wizard-fab:active{transform:translateY(-1px) scale(.97);}
.wizard-fab:before{content:''; position:absolute; inset:0; border-radius:inherit; background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.35),transparent 60%); mix-blend-mode:overlay; pointer-events:none;}
.wizard-fab .wf-label{font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; line-height:1; margin-top:-2px;}
@media (max-width:700px){ .wizard-fab{width:54px; height:54px; font-size:18px;} .wizard-fab .wf-label{font-size:9px;} .prompt-wrapper.with-wizard textarea{padding-bottom:62px;} }
.preset-btn{ background:rgba(255,255,255,0.05); border:1px solid var(--border); color:var(--text); padding:4px 8px; border-radius:10px; font-size:12px; cursor:pointer; margin:0 6px 6px 0; }
.preset-btn:hover{ background:var(--pri); color:#fff; }
.tag-add{ background:rgba(255,255,255,0.05); border:1px solid var(--border); color:var(--text); padding:2px 8px; border-radius:14px; font-size:11px; cursor:pointer; margin:0 4px 4px 0; }
.tag-add:hover{ background:var(--pri); color:#fff; }
.btn.danger{background:linear-gradient(135deg,#ff5555,#ff2d2d);color:#fff;}
.btn.danger:hover{filter:brightness(1.1);} 
.btn.small{padding:6px 10px; font-size:12px; font-weight:600;}
</style>
<script>
// Открыть мастер из отдельного файла с корректной инициализацией стилей и скриптов
const smartBtn = document.getElementById('smartWizardBtn');
if (smartBtn) {
  smartBtn.addEventListener('click', async () => {
    try {
      // Если уже открыт — не плодим дубликаты
      const existing = document.getElementById('smartWizardModal');
      if (existing) { return; }
      const r = await fetch('wizard.php?modal=1', { headers: { 'X-Requested-With': 'fetch' } });
      const html = await r.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      const modal = doc.getElementById('smartWizardModal');
      if (!modal) { throw new Error('Нет содержимого мастера'); }
      // Подключаем стили из ответа
      doc.querySelectorAll('style').forEach(styleEl => {
        const s = document.createElement('style');
        s.textContent = styleEl.textContent;
        document.head.appendChild(s);
      });
      // Вставляем модалку
      document.body.appendChild(modal);
      document.body.style.overflow = 'hidden';
      // Исполняем скрипты из ответа
      doc.querySelectorAll('script').forEach(se => {
        const s = document.createElement('script');
        // Копируем inline-скрипт
        if (se.textContent) s.textContent = se.textContent;
        // Копируем src, если вдруг будет
        if (se.src) s.src = se.src;
        document.body.appendChild(s);
      });
    } catch (e) { showToast('Не удалось открыть мастер: ' + e, 'error'); }
  });
}

// Добавление значений из рекомендаций
document.querySelectorAll('.tag-add').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const targetId = btn.getAttribute('data-add-target');
    const val = btn.getAttribute('data-value');
    const inp = document.getElementById(targetId);
    if (!inp) return;
    let current = inp.value.split(/[;,\s]+/).filter(x=>x.trim()!=='');
    if (!current.includes(val)) { current.push(val); }
    inp.value = current.join(', ');
  });
});

// Пресеты языков/регионов
function applyPreset(btn){
  const langs = (btn.getAttribute('data-langs')||'').trim();
  const regs  = (btn.getAttribute('data-regs')||'').trim();
  const langInp = document.getElementById('search_languages_input');
  const regInp  = document.getElementById('search_regions_input');
  if (langInp && langs) langInp.value = langs;
  if (regInp && regs) regInp.value = regs;
  showToast('Пресет применён','success');
}

document.querySelectorAll('.preset-btn').forEach(b=>b.addEventListener('click', ()=>applyPreset(b)));

const testBtn = document.getElementById('testApiBtn');
if (testBtn){
  testBtn.addEventListener('click', ()=>{
    const statusEl = document.getElementById('apiTestStatus');
    const key = document.querySelector('input[name=openai_api_key]').value.trim();
    const model = document.querySelector('select[name=openai_model]').value;
    if(!key){ statusEl.textContent='Введите ключ'; return; }
    testBtn.disabled=true; statusEl.textContent='Проверка...';
    fetch('settings.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body:new URLSearchParams({action:'test_api_key', openai_api_key:key, openai_model:model})})
      .then(r=>r.json()).then(data=>{
        if(data.ok){ statusEl.textContent='✔ Ключ работает ('+data.latency_ms+' ms)'; statusEl.style.color='#52d273'; }
        else { statusEl.textContent='✖ '+(data.error||'Ошибка'); statusEl.style.color='#ff6b6b'; }
      }).catch(e=>{ statusEl.textContent='Сбой: '+e; statusEl.style.color='#ff6b6b'; })
      .finally(()=>{ testBtn.disabled=false; });
  });
}

function showToast(message, type='success', timeout=5000){
  const cont = document.getElementById('toastContainer');
  if(!cont) return;
  const el = document.createElement('div');
  el.className = 'toast toast-'+type;
  el.innerHTML = '<span>'+escapeHtml(message)+'</span><button type="button" class="toast-close" aria-label="Закрыть">×</button>';
  cont.appendChild(el);
  const remove = ()=>{ el.classList.add('hide'); setTimeout(()=>el.remove(),300); };
  el.querySelector('.toast-close').addEventListener('click', remove);
  setTimeout(remove, timeout);
}
function escapeHtml(text){ const div=document.createElement('div'); div.textContent=text; return div.innerHTML; }
</script>

</body>
</html>