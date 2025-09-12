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
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='clear_data') {
    try {
        pdo()->exec('TRUNCATE TABLE links');
        @pdo()->exec('TRUNCATE TABLE topics');
        @pdo()->exec('TRUNCATE TABLE domains');
        @pdo()->exec('TRUNCATE TABLE scans');
        @pdo()->exec('TRUNCATE TABLE runs');
        $ok = 'Данные (links/topics/domains/scans/runs) очищены';
        app_log('info','maintenance','Data cleared',[]);
    } catch (Throwable $e) {
        $ok = 'Ошибка очистки: '.$e->getMessage();
        app_log('error','maintenance','Clear failed',['error'=>$e->getMessage()]);
    }
}

// Обработка умного мастера
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smart_wizard'])) {
    $userInput = trim($_POST['user_description'] ?? '');
    $apiKey = (string)get_setting('openai_api_key', '');
    $model = (string)get_setting('openai_model', 'gpt-5-mini');
    $step = $_POST['wizard_step'] ?? 'clarify';
    
    // Используем исходное описание из сессии на этапе generate, если не пришло новое
    if ($step === 'generate' && $userInput === '') {
        $wizardDataTmp = $_SESSION['wizard_data'] ?? null;
        if ($wizardDataTmp && !empty($wizardDataTmp['original_input'])) {
            $userInput = $wizardDataTmp['original_input'];
        }
    }
    
    if (!empty($userInput) && !empty($apiKey)) {
        if ($step === 'clarify') {
            // Первый этап: анализ и генерация вопросов
            $wizardResult = processSmartWizard($userInput, $apiKey, $model, 'clarify');
            
            if ($wizardResult['ok']) {
                if (empty($wizardResult['questions'])) {
                    // Информации достаточно, сразу генерируем промпт
                    $finalResult = processSmartWizard($userInput, $apiKey, $model, 'generate');
                    
                    if ($finalResult['ok']) {
                        set_setting('search_prompt', $finalResult['prompt']);
                        if (!empty($finalResult['languages'])) {
                            set_setting('detected_languages', json_encode($finalResult['languages']));
                            $existing = get_setting('search_languages', []);
                            if (empty($existing) || (is_string($existing) && trim($existing)==='')) {
                                set_setting('search_languages', json_encode($finalResult['languages']));
                            }
                        }
                        if (!empty($finalResult['regions'])) {
                            set_setting('detected_regions', json_encode($finalResult['regions']));
                            $existingR = get_setting('search_regions', []);
                            if (empty($existingR) || (is_string($existingR) && trim($existingR)==='')) {
                                set_setting('search_regions', json_encode($finalResult['regions']));
                            }
                        }
                        
                        $ok = 'Промпт сформирован автоматически! Языки и регионы определены.';
                        header('Location: settings.php?wizard_success=1');
                        exit;
                    } else {
                        $ok = 'Ошибка генерации промпта: ' . ($finalResult['error'] ?? 'Неизвестная ошибка');
                    }
                } else {
                    // Нужны уточняющие вопросы - сохраняем в сессии (добавлены recommendations)
                    $_SESSION['wizard_data'] = [
                        'original_input' => $userInput,
                        'questions' => $wizardResult['questions'],
                        'auto_detected' => $wizardResult['auto_detected'] ?? [],
                        'recommendations' => $wizardResult['recommendations'] ?? []
                    ];
                    header('Location: settings.php?wizard_questions=1');
                    exit;
                }
            } else {
                $ok = 'Ошибка анализа: ' . ($wizardResult['error'] ?? 'Неизвестная ошибка');
            }
        } elseif ($step === 'generate') {
            // Второй этап: генерация финального промпта на основе ответов
            $wizardData = $_SESSION['wizard_data'] ?? null;
            if (!$wizardData) {
                $ok = 'Ошибка: данные мастера не найдены';
            } else {
                // Объединяем оригинальное описание с ответами
                $combinedInput = $wizardData['original_input'] . "\n\nДополнительная информация:\n";
                
                foreach ($wizardData['questions'] as $i => $question) {
                    $answer = $_POST["question_$i"] ?? '';
                    if (is_array($answer)) { $answer = implode(', ', $answer); }
                    $answer = trim($answer);
                    if ($answer !== '') {
                        $combinedInput .= $question['question'] . ": " . $answer . "\n";
                    }
                }

                // Новые блоки: свободный ввод языков и регионов (без чекбоксов)
                // Поддерживаем обратную совместимость: если пришли wizard_languages[] (старый формат) — добавим их тоже
                $langs = [];
                $freeLangs = trim($_POST['wizard_languages_custom'] ?? '');
                if ($freeLangs !== '') {
                    $langs = preg_split('~[;:,\n\r\t\s]+~u', $freeLangs, -1, PREG_SPLIT_NO_EMPTY);
                } elseif (!empty($_POST['wizard_languages']) && is_array($_POST['wizard_languages'])) { // fallback
                    $langs = $_POST['wizard_languages'];
                }
                $langs = array_values(array_unique(array_filter(array_map('trim', $langs))));
                if ($langs) {
                    $combinedInput .= "Предпочитаемые языки: " . implode(', ', $langs) . "\n";
                }
                
                $regions = [];
                $freeRegs = trim($_POST['wizard_regions_custom'] ?? '');
                if ($freeRegs !== '') {
                    $regions = preg_split('~[;:,\n\r\t\s]+~u', $freeRegs, -1, PREG_SPLIT_NO_EMPTY);
                } elseif (!empty($_POST['wizard_regions']) && is_array($_POST['wizard_regions'])) { // fallback
                    $regions = $_POST['wizard_regions'];
                }
                $regions = array_values(array_unique(array_filter(array_map('trim', $regions))));
                if ($regions) {
                    $combinedInput .= "Предпочитаемые регионы: " . implode(', ', $regions) . "\n";
                }

                // Передаём дополненный ввод
                $finalResult = processSmartWizard($combinedInput, $apiKey, $model, 'generate');
                
                if ($finalResult['ok']) {
                    set_setting('search_prompt', $finalResult['prompt']);
                    if (!empty($finalResult['languages'])) {
                        set_setting('detected_languages', json_encode($finalResult['languages']));
                        $existing = get_setting('search_languages', []);
                        if (empty($existing) || (is_string($existing) && trim($existing)==='')) {
                            set_setting('search_languages', json_encode($finalResult['languages']));
                        }
                    }
                    if (!empty($finalResult['regions'])) {
                        set_setting('detected_regions', json_encode($finalResult['regions']));
                        $existingR = get_setting('search_regions', []);
                        if (empty($existingR) || (is_string($existingR) && trim($existingR)==='')) {
                            set_setting('search_regions', json_encode($finalResult['regions']));
                        }
                    }
                    
                    // Очищаем данные мастера
                    unset($_SESSION['wizard_data']);
                    
                    $ok = 'Промпт сформирован с учетом ваших ответов! Языки и регионы определены.';
                    header('Location: settings.php?wizard_success=1');
                    exit;
                } else {
                    $ok = 'Ошибка генерации финального промпта: ' . ($finalResult['error'] ?? 'Неизвестная ошибка');
                }
            }
        }
    } else {
        $ok = 'Заполните описание и убедитесь что указан OpenAI API ключ';
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
        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px;">
          <button type="button" id="smartWizardBtn" class="btn small btn-ghost">🤖 Умный мастер</button>
          <span class="muted" style="font-size: 12px;">Опишите что хотите отслеживать, ИИ сформирует промпт</span>
        </div>
        <textarea name="search_prompt" rows="5" placeholder="Опиши задачу для агента..."><?=e($prompt)?></textarea>
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
        <input type="hidden" name="wizard_step" value="clarify">
        
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

<div id="toastContainer" class="toast-container" aria-live="polite" aria-atomic="true"></div>

<style>
.modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1000; }
.modal-backdrop { position: absolute; width: 100%; height: 100%; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); }
.modal-content { position: relative; max-width: 760px; margin: 4% auto; background: var(--card); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow); max-height: 92vh; display: flex; flex-direction: column; }
.modal-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px 16px; border-bottom: 1px solid var(--border); }
.modal-header h3 { margin: 0; }
.modal-close { background: none; border: none; font-size: 24px; color: var(--muted); cursor: pointer; line-height: 1; }
.modal-close:hover { color: var(--text); }
.modal-body { padding: 20px 24px; overflow-y: auto; }
.modal-body form input[type=text], .modal-body form textarea { width: 100%; }
.modal-body .inline-group { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0 4px; }
.modal-body .inline-group label { background: rgba(255,255,255,0.04); padding: 4px 10px; border-radius: 20px; display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; border: 1px solid var(--border); }
.modal-body .inline-group input { width: auto; height: auto; }
.small-note { font-size: 11px; color: var(--muted); margin-top: 4px; }
.checkbox input[type=checkbox], .checkbox input[type=radio] { width: 16px; height: 16px; }
.spinner { width: 40px; height: 40px; border: 4px solid var(--border); border-top: 4px solid var(--pri); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 12px; }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.tag-add{ background:rgba(255,255,255,0.05); border:1px solid var(--border); color:var(--text); padding:2px 8px; border-radius:14px; font-size:11px; cursor:pointer; margin:0 4px 4px 0; }
.tag-add:hover{ background:var(--pri); color:#fff; }
.btn.danger{background:linear-gradient(135deg,#ff5555,#ff2d2d);color:#fff;}
.btn.danger:hover{filter:brightness(1.1);} 
.btn.small{padding:6px 10px; font-size:12px; font-weight:600;}
.toast-container{ position:fixed; top:12px; right:12px; display:flex; flex-direction:column; gap:10px; z-index:1200; max-width:300px; }
.toast{ background:var(--card); border:1px solid var(--border); box-shadow:0 4px 18px -4px rgba(0,0,0,0.4); padding:10px 14px; border-radius:12px; font-size:13px; line-height:1.4; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; animation:toastIn .35s ease; }
.toast-success{ border-color:#2e8b57; }
.toast-error{ border-color:#ff4d4f; }
.toast-close{ background:none; border:none; font-size:16px; line-height:1; cursor:pointer; color:var(--muted); padding:0 2px; }
.toast-close:hover{ color:var(--text); }
.toast.hide{ opacity:0; transform:translateY(-6px); transition:.3s; }
@keyframes toastIn{ from{opacity:0; transform:translateY(-6px);} to{opacity:1; transform:translateY(0);} }
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
setTimeout(function(){
  showToast('✨ Промпт успешно сгенерирован! Проверьте поле ниже.','success');
}, 150);
<?php endif; ?>

// Показать вопросы если ИИ их сгенерировал
<?php if (isset($_GET['wizard_questions']) && isset($_SESSION['wizard_data'])): ?>
setTimeout(function() {
  showQuestionsModal();
}, 100);
<?php endif; ?>

function showQuestionsModal() {
  const questionsData = <?= json_encode($_SESSION['wizard_data'] ?? null, JSON_UNESCAPED_UNICODE) ?>;
  if (!questionsData || !questionsData.questions) return;
  const modal = document.getElementById('smartWizardModal');
  const modalBody = modal.querySelector('.modal-body');
  const detected = questionsData.auto_detected || {};
  const hasLangs = Array.isArray(detected.languages) && detected.languages.length>0;
  const hasRegions = Array.isArray(detected.regions) && detected.regions.length>0;
  const singleFallback = questionsData.questions.length===1 && /языки и регионы/i.test(questionsData.questions[0].question || '');

  let questionsHtml = '';
  if (singleFallback && !hasLangs && !hasRegions) {
    questionsHtml += '<p class="muted" style="margin-bottom:10px;">Укажите любые подсказки по языкам и географии для мониторинга. Можно в свободной форме.</p>';
    questionsHtml += '<div style="margin:0 0 14px; padding:10px 14px; border:1px solid var(--border); border-radius:10px; font-size:12.5px; line-height:1.5; background:rgba(255,255,255,0.04);">'
      + '<strong>Примеры:</strong><br>'
      + '– ru, en; регионы: UA, PL, DE<br>'
      + '– Хочу русскоязычные и украинские обсуждения в Украине, Польше и Германии<br>'
      + '– Европа (значит AL, AT, BE, BY, ... GB, UA и т.п.)<br>'
      + '– Английский в США и Канаде<br>'
      + 'Можно написать группами: “Европа”, “СНГ”, “Латинская Америка” — ИИ сам постарается развернуть.</div>';
  } else {
    questionsHtml += '<p class="muted">Уточните детали в свободной форме. Ответьте текстом — никаких чекбоксов, просто впишите что считаете нужным. Можно пропустить вопросы.</p>';
  }
  if (Array.isArray(questionsData.recommendations) && questionsData.recommendations.length) {
    questionsHtml += '<div style="margin:12px 0 18px; padding:10px 14px; border:1px solid var(--border); border-radius:10px; background:rgba(255,255,255,0.04);">';
    questionsHtml += '<div style="font-weight:600; font-size:13px; margin-bottom:6px;">Рекомендации улучшения</div><ul style="margin:0; padding-left:18px; font-size:12.5px; line-height:1.45;">';
    questionsData.recommendations.forEach(r => { questionsHtml += '<li>'+escapeHtml(r)+'</li>'; });
    questionsHtml += '</ul></div>';
  }
  questionsHtml += '<form id="questionsForm" method="post">';
  questionsHtml += '<input type="hidden" name="smart_wizard" value="1">';
  questionsHtml += '<input type="hidden" name="wizard_step" value="generate">';
  
  questionsData.questions.forEach((question, index) => {
    let placeholder = 'Ваш ответ...';
    if (singleFallback) {
      placeholder = 'Например: ru, en; регионы: UA, PL, DE / или: Европа; или: rus, uk + Украина и Польша';
    }
    questionsHtml += '<div style="margin-bottom: 16px;">';
    questionsHtml += '<label style="font-weight: 600; margin-bottom: 6px; display:block;">' + escapeHtml(question.question) + '</label>';
    questionsHtml += '<textarea name="question_' + index + '" rows="3" placeholder="'+escapeHtml(placeholder)+'" style="width:100%; resize:vertical;"></textarea>';
    questionsHtml += '</div>';
  });
  
  if (hasLangs || hasRegions) {
    const langs = hasLangs ? detected.languages : [];
    const regions = hasRegions ? detected.regions : [];
    questionsHtml += '<div style="margin:20px 0; padding:12px; border:1px solid var(--border); border-radius:10px; background:rgba(91,140,255,0.07);">';
    if (langs.length) {
      questionsHtml += '<div style="margin-bottom:10px;"><strong>Предполагаемые языки:</strong> '+escapeHtml(langs.join(', '))+'</div>';
    }
    questionsHtml += '<label style="display:block; font-size:12px; margin-bottom:4px;">Языки (коды через запятую)</label>';
    questionsHtml += '<input type="text" name="wizard_languages_custom" placeholder="ru, en, uk..." value="'+escapeHtml(langs.join(', '))+'" style="width:100%; margin-bottom:12px;">';
    if (regions.length) {
      questionsHtml += '<div style="margin-bottom:10px;"><strong>Предполагаемые регионы:</strong> '+escapeHtml(regions.join(', '))+'</div>';
    }
    questionsHtml += '<label style="display:block; font-size:12px; margin-bottom:4px;">Регионы (коды стран через запятую)</label>';
    questionsHtml += '<input type="text" name="wizard_regions_custom" placeholder="UA, PL, DE..." value="'+escapeHtml(regions.join(', '))+'" style="width:100%;">';
    questionsHtml += '<div class="small-note" style="margin-top:8px;">Можно удалить лишнее или добавить свои через запятую. “Европа” автоматически развернётся.</div>';
    questionsHtml += '</div>';
  }
  
  questionsHtml += '<div class="modal-actions">';
  questionsHtml += '<button type="button" class="btn btn-ghost" onclick="closeWizardModal()">Отмена</button>';
  questionsHtml += '<button type="submit" class="btn primary">✨ Создать промпт</button>';
  questionsHtml += '</div>';
  questionsHtml += '</form>';
  
  questionsHtml += '<div id="questionsLoadingState" style="display: none; text-align: center; padding: 20px;">';
  questionsHtml += '<div class="spinner"></div>';
  questionsHtml += '<p>ИИ создает финальный промпт...</p>';
  questionsHtml += '</div>';
  
  modalBody.innerHTML = questionsHtml;
  
  document.getElementById('questionsForm').addEventListener('submit', function() {
    this.style.display = 'none';
    document.getElementById('questionsLoadingState').style.display = 'block';
  });
  
  modal.style.display = 'block';
  document.body.style.overflow = 'hidden';
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

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
</script>

</body>
</html>