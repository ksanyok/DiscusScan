<?php
require_once __DIR__ . '/db.php';
require_login();

$step = (int)($_GET['step'] ?? 1);
$maxStep = 5;

// Обработка сохранения настроек
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_orchestration'])) {
    $topic = trim($_POST['topic'] ?? '');
    $sources = [];
    if (isset($_POST['sources'])) {
        foreach ($_POST['sources'] as $src) {
            if (in_array($src, ['forums', 'telegram'])) {
                $sources[] = $src;
            }
        }
    }
    
    $languages = [];
    if (isset($_POST['languages'])) {
        foreach ($_POST['languages'] as $lang) {
            if (preg_match('/^[a-z]{2}$/', $lang)) {
                $languages[] = $lang;
            }
        }
    }
    
    $regions = [];
    if (isset($_POST['regions'])) {
        foreach ($_POST['regions'] as $reg) {
            if (preg_match('/^[A-Z]{2}$/', $reg)) {
                $regions[] = $reg;
            }
        }
    }
    
    $freshness = max(1, (int)($_POST['freshness_window_hours'] ?? 72));
    $perDomainLimit = max(1, min(50, (int)($_POST['per_domain_limit'] ?? 5)));
    $totalLimit = max(1, min(500, (int)($_POST['total_limit'] ?? 50)));
    
    // Сохраняем настройки
    set_setting('orchestration_topic', $topic);
    set_setting('orchestration_sources', json_encode($sources));
    set_setting('orchestration_languages', json_encode($languages));
    set_setting('orchestration_regions', json_encode($regions));
    set_setting('orchestration_freshness_window_hours', $freshness);
    set_setting('orchestration_per_domain_limit', $perDomainLimit);
    set_setting('orchestration_total_limit', $totalLimit);
    set_setting('orchestration_enabled', true);
    
    app_log('info', 'orchestration', 'Orchestration settings saved', [
        'topic' => $topic,
        'sources' => $sources,
        'languages' => $languages
    ]);
    
    header('Location: monitoring_dashboard.php');
    exit;
}

// Текущие настройки для предзаполнения
$currentTopic = (string)get_setting('orchestration_topic', '');
$currentSources = json_decode((string)get_setting('orchestration_sources', '["forums"]'), true) ?: ['forums'];
$currentLanguages = json_decode((string)get_setting('orchestration_languages', '["ru","uk","en"]'), true) ?: ['ru','uk','en'];
$currentRegions = json_decode((string)get_setting('orchestration_regions', '["UA","PL"]'), true) ?: ['UA','PL'];
$currentFreshness = (int)get_setting('orchestration_freshness_window_hours', 72);
$currentPerDomain = (int)get_setting('orchestration_per_domain_limit', 5);
$currentTotal = (int)get_setting('orchestration_total_limit', 50);

// Доступные опции
$availableLanguages = [
    'ru' => 'Русский',
    'uk' => 'Українська', 
    'en' => 'English',
    'pl' => 'Polski',
    'de' => 'Deutsch',
    'fr' => 'Français'
];

$availableRegions = [
    'UA' => 'Украина (.ua)',
    'PL' => 'Польша (.pl)', 
    'RU' => 'Россия (.ru)',
    'BY' => 'Беларусь (.by)',
    'DE' => 'Германия (.de)',
    'FR' => 'Франция (.fr)',
    'US' => 'США (.com)',
    'GB' => 'Великобритания (.uk)'
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Мастер настроек оркестрации — DiscusScan</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    .wizard-container { max-width: 800px; margin: 0 auto; }
    .wizard-step { display: none; }
    .wizard-step.active { display: block; }
    .wizard-nav { display: flex; justify-content: space-between; margin-top: 24px; }
    .step-indicator { display: flex; gap: 12px; margin-bottom: 24px; }
    .step-dot { width: 12px; height: 12px; border-radius: 50%; background: var(--muted); opacity: 0.3; }
    .step-dot.active { background: var(--pri); opacity: 1; }
    .step-dot.completed { background: var(--ok); opacity: 1; }
    .checkbox-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
    .range-group { display: flex; align-items: center; gap: 12px; }
    .range-group input[type=range] { flex: 1; }
    .range-value { min-width: 60px; text-align: center; font-weight: 600; }
  </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
  <div class="wizard-container">
    <div class="card glass">
      <div class="card-title">🎯 Мастер настроек оркестрации</div>
      
      <div class="step-indicator">
        <?php for ($i = 1; $i <= $maxStep; $i++): ?>
          <div class="step-dot <?= $i === $step ? 'active' : ($i < $step ? 'completed' : '') ?>"></div>
        <?php endfor; ?>
      </div>

      <form method="post" id="wizardForm">
        <!-- Шаг 1: Тема поиска -->
        <div class="wizard-step <?= $step === 1 ? 'active' : '' ?>">
          <h3>Шаг 1. Что ищем?</h3>
          <p class="muted">Опишите тему или ключевые слова для поиска упоминаний.</p>
          
          <label>Тема поиска
            <textarea name="topic" rows="4" placeholder="Например: упоминания моего продукта, бренда или услуги на форумах и в сообществах" required><?= e($currentTopic) ?></textarea>
          </label>
          
          <div class="alert">
            <strong>Совет:</strong> Будьте конкретны. Указывайте названия продуктов, бренды, ключевые термины. 
            Система будет искать только новые упоминания после последнего запуска.
          </div>
        </div>

        <!-- Шаг 2: Источники -->
        <div class="wizard-step <?= $step === 2 ? 'active' : '' ?>">
          <h3>Шаг 2. Где ищем?</h3>
          <p class="muted">Выберите типы источников для мониторинга.</p>
          
          <div class="checkbox-grid">
            <label class="checkbox">
              <input type="checkbox" name="sources[]" value="forums" <?= in_array('forums', $currentSources) ? 'checked' : '' ?>>
              <span>📋 Форумы и сообщества</span>
              <small>Тематические форумы, Q&A сайты, Reddit</small>
            </label>
            
            <label class="checkbox">
              <input type="checkbox" name="sources[]" value="telegram" <?= in_array('telegram', $currentSources) ? 'checked' : '' ?>>
              <span>💬 Telegram каналы</span>
              <small>Публичные каналы и группы</small>
            </label>
          </div>
          
          <div class="alert">
            <strong>Рекомендация:</strong> Форумы обязательны для качественного мониторинга. 
            Telegram добавляйте если нужно отслеживать обсуждения в мессенджерах.
          </div>
        </div>

        <!-- Шаг 3: Языки -->
        <div class="wizard-step <?= $step === 3 ? 'active' : '' ?>">
          <h3>Шаг 3. Языки поиска</h3>
          <p class="muted">Выберите языки для поиска контента.</p>
          
          <div class="checkbox-grid">
            <?php foreach ($availableLanguages as $code => $name): ?>
              <label class="checkbox">
                <input type="checkbox" name="languages[]" value="<?= e($code) ?>" <?= in_array($code, $currentLanguages) ? 'checked' : '' ?>>
                <span><?= e($name) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Шаг 4: Регионы -->
        <div class="wizard-step <?= $step === 4 ? 'active' : '' ?>">
          <h3>Шаг 4. Регионы и домены</h3>
          <p class="muted">Выберите страны и регионы для поиска (влияет на ccTLD подсказки).</p>
          
          <div class="checkbox-grid">
            <?php foreach ($availableRegions as $code => $name): ?>
              <label class="checkbox">
                <input type="checkbox" name="regions[]" value="<?= e($code) ?>" <?= in_array($code, $currentRegions) ? 'checked' : '' ?>>
                <span><?= e($name) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Шаг 5: Ограничения -->
        <div class="wizard-step <?= $step === 5 ? 'active' : '' ?>">
          <h3>Шаг 5. Ограничения и расписание</h3>
          <p class="muted">Настройте параметры поиска и ограничения.</p>
          
          <div class="stack">
            <label>Окно свежести (часы)
              <div class="range-group">
                <input type="range" name="freshness_window_hours" min="6" max="168" value="<?= $currentFreshness ?>" oninput="this.nextElementSibling.textContent = this.value + 'ч'">
                <span class="range-value"><?= $currentFreshness ?>ч</span>
              </div>
              <small class="muted">Искать контент не старше указанного времени</small>
            </label>
            
            <div class="grid-2">
              <label>Лимит на домен
                <input type="number" name="per_domain_limit" value="<?= $currentPerDomain ?>" min="1" max="50">
                <small class="muted">Максимум результатов с одного домена</small>
              </label>
              
              <label>Общий лимит
                <input type="number" name="total_limit" value="<?= $currentTotal ?>" min="1" max="500">
                <small class="muted">Максимум результатов за запуск</small>
              </label>
            </div>
          </div>
          
          <div class="alert success">
            <strong>Готово!</strong> После сохранения система начнет автоматический поиск по расписанию. 
            Вы сможете управлять доменами и просматривать результаты в панели мониторинга.
          </div>
        </div>

        <div class="wizard-nav">
          <?php if ($step > 1): ?>
            <a href="?step=<?= $step - 1 ?>" class="btn btn-ghost">← Назад</a>
          <?php else: ?>
            <a href="settings.php" class="btn btn-ghost">← К настройкам</a>
          <?php endif; ?>
          
          <?php if ($step < $maxStep): ?>
            <a href="?step=<?= $step + 1 ?>" class="btn primary">Далее →</a>
          <?php else: ?>
            <button type="submit" name="save_orchestration" class="btn primary">💾 Сохранить и запустить</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</main>

<?php include 'footer.php'; ?>
</body>
</html>