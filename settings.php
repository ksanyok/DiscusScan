<?php
require_once __DIR__ . '/db.php';
require_login();

$models = [
    'gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o'
];

$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        <input type="password" name="openai_api_key" value="<?=e($apiKey)?>" placeholder="sk-...">
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

      <div class="hint">CRON URL: <code><?=e($cronUrl)?></code></div>
      <div class="hint">CLI: <code>php <?=e(__DIR__ . '/scan.php')?></code></div>

      <button class="btn primary">Сохранить</button>
      
      <hr>
      <div class="card-title">Оркестрация поиска</div>
      <div class="hint" style="margin-bottom: 12px;">
        Настройте автоматический поиск новых результатов по расписанию с сохранением состояния и группировкой по доменам.
      </div>
      <a href="monitoring_wizard.php" class="btn btn-ghost">🎯 Мастер настроек оркестрации</a>
    </form>
  </div>
</main>
<?php include 'footer.php'; ?>
</body>
</html>