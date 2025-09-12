<?php
require_once __DIR__ . '/db.php';
require_login();

$models = [
    'gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4.1-mini', 'gpt-4o'
];

$ok = '';

// Обработка умного мастера
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smart_wizard'])) {
    $userInput = trim($_POST['user_description'] ?? '');
    $apiKey = (string)get_setting('openai_api_key', '');
    $model = (string)get_setting('openai_model', 'gpt-5-mini');
    
    if (!empty($userInput) && !empty($apiKey)) {
        // Вызываем OpenAI для анализа пользовательского ввода
        $wizardResult = processSmartWizard($userInput, $apiKey, $model);
        
        if ($wizardResult['ok']) {
            // Сохраняем результаты обработки
            set_setting('search_prompt', $wizardResult['prompt']);
            if (!empty($wizardResult['languages'])) {
                set_setting('detected_languages', json_encode($wizardResult['languages']));
            }
            if (!empty($wizardResult['regions'])) {
                set_setting('detected_regions', json_encode($wizardResult['regions']));
            }
            
            $ok = 'Промпт сформирован автоматически! Языки и регионы определены.';
            
            app_log('info', 'smart_wizard', 'Prompt generated', [
                'user_input_length' => strlen($userInput),
                'generated_prompt_length' => strlen($wizardResult['prompt']),
                'languages' => $wizardResult['languages'] ?? [],
                'regions' => $wizardResult['regions'] ?? []
            ]);
        } else {
            $ok = 'Ошибка генерации промпта: ' . ($wizardResult['error'] ?? 'Неизвестная ошибка');
        }
    } else {
        $ok = 'Заполните описание и убедитесь что указан OpenAI API ключ';
    }
    
    // Перезагружаем страницу чтобы показать обновленный промпт
    if (strpos($ok, 'сформирован') !== false) {
        header('Location: settings.php?wizard_success=1');
        exit;
    }
}

// Обработка обычных настроек
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['smart_wizard'])) {
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
        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
          <button type="button" id="smartWizardBtn" class="btn small btn-ghost">🤖 Умный мастер</button>
          <span class="muted" style="font-size: 12px;">Опишите что хотите отслеживать, ИИ сформирует промпт</span>
        </div>
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
    </form>
  </div>
</main>
<?php include 'footer.php'; ?>

<!-- Модальное окно умного мастера -->
<div id="smartWizardModal" class="modal" style="display: none;">
  <div class="modal-backdrop"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h3>🤖 Умный мастер промптов</h3>
      <button type="button" class="modal-close">&times;</button>
    </div>
    <div class="modal-body">
      <p class="muted">Опишите в произвольной форме что вы хотите отслеживать. ИИ автоматически сформирует промпт и определит языки/регионы для поиска.</p>
      
      <form id="wizardForm" method="post">
        <input type="hidden" name="smart_wizard" value="1">
        
        <label>Описание задачи
          <textarea name="user_description" rows="6" placeholder="Например: Хочу отслеживать упоминания моего стартапа по продаже органических овощей в Украине и Польше. Интересуют обсуждения на форумах про здоровое питание, отзывы покупателей, сравнения с конкурентами..." required></textarea>
        </label>
        
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" onclick="closeWizardModal()">Отмена</button>
          <button type="submit" class="btn primary" id="generateBtn">✨ Сгенерировать промпт</button>
        </div>
      </form>
      
      <div id="loadingState" style="display: none; text-align: center; padding: 20px;">
        <div class="spinner"></div>
        <p>ИИ анализирует ваше описание и формирует промпт...</p>
      </div>
    </div>
  </div>
</div>

<style>
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1000; }
.modal-backdrop { position: absolute; width: 100%; height: 100%; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
.modal-content { position: relative; max-width: 600px; margin: 5% auto; background: var(--card); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px 16px; border-bottom: 1px solid var(--border); }
.modal-header h3 { margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; color: var(--muted); cursor: pointer; line-height: 1; }
.modal-close:hover { color: var(--text); }
.modal-body { padding: 20px 24px; }
.modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; }

.spinner { width: 40px; height: 40px; border: 4px solid var(--border); border-top: 4px solid var(--pri); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<script>
function openWizardModal() {
  document.getElementById('smartWizardModal').style.display = 'block';
  document.body.style.overflow = 'hidden';
}

function closeWizardModal() {
  document.getElementById('smartWizardModal').style.display = 'none';
  document.body.style.overflow = '';
}

// Открытие модального окна
document.getElementById('smartWizardBtn').addEventListener('click', openWizardModal);

// Закрытие по клику на backdrop
document.querySelector('.modal-backdrop').addEventListener('click', closeWizardModal);

// Закрытие по ESC
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeWizardModal();
});

// Обработка формы
document.getElementById('wizardForm').addEventListener('submit', function(e) {
  const description = this.user_description.value.trim();
  if (!description) {
    e.preventDefault();
    alert('Пожалуйста, опишите что вы хотите отслеживать');
    return;
  }
  
  // Показываем загрузку
  document.querySelector('.modal-body form').style.display = 'none';
  document.getElementById('loadingState').style.display = 'block';
  
  // Форма отправится автоматически
});

// Показать сообщение об успехе если пришли после генерации
<?php if (isset($_GET['wizard_success'])): ?>
setTimeout(function() {
  alert('✨ Промпт успешно сгенерирован! Проверьте поле "Промпт" выше.');
}, 100);
<?php endif; ?>
</script>

</body>
</html>