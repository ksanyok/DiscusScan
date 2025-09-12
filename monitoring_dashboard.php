<?php
require_once __DIR__ . '/db.php';
require_login();

// Проверяем включена ли оркестрация
$orchestrationEnabled = (bool)get_setting('orchestration_enabled', false);
if (!$orchestrationEnabled) {
    header('Location: monitoring_wizard.php');
    exit;
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_domain_pause') {
        $domainId = (int)($_POST['domain_id'] ?? 0);
        if ($domainId > 0) {
            pdo()->exec("UPDATE domains SET is_paused = 1 - is_paused WHERE id = {$domainId}");
            app_log('info', 'orchestration', 'Domain pause toggled', ['domain_id' => $domainId]);
        }
    }
    
    if ($action === 'run_seed_domains') {
        // Запуск семплинга доменов
        header('Location: monitoring_cron.php?action=seed_domains&manual=1');
        exit;
    }
    
    if ($action === 'run_scan') {
        // Запуск сканирования
        header('Location: monitoring_cron.php?action=scan&manual=1');
        exit;
    }
    
    header('Location: monitoring_dashboard.php');
    exit;
}

// Текущие настройки
$topic = (string)get_setting('orchestration_topic', '');
$sources = json_decode((string)get_setting('orchestration_sources', '["forums"]'), true) ?: ['forums'];
$languages = json_decode((string)get_setting('orchestration_languages', '["ru"]'), true) ?: ['ru'];
$regions = json_decode((string)get_setting('orchestration_regions', '["UA"]'), true) ?: ['UA'];
$freshnessHours = (int)get_setting('orchestration_freshness_window_hours', 72);
$lastRun = (string)get_setting('orchestration_last_run', '');

// Статистика доменов
try {
    $domainsStats = pdo()->query("
        SELECT 
            COUNT(*) as total,
            SUM(is_paused = 0) as active,
            SUM(is_paused = 1) as paused,
            AVG(score) as avg_score
        FROM domains
    ")->fetch() ?: ['total' => 0, 'active' => 0, 'paused' => 0, 'avg_score' => 0];
} catch (Throwable $e) {
    $domainsStats = ['total' => 0, 'active' => 0, 'paused' => 0, 'avg_score' => 0];
}

// Статистика тем
try {
    $topicsStats = pdo()->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as last_7d
        FROM topics
    ")->fetch() ?: ['total' => 0, 'last_24h' => 0, 'last_7d' => 0];
} catch (Throwable $e) {
    $topicsStats = ['total' => 0, 'last_24h' => 0, 'last_7d' => 0];
}

// Последние запуски
try {
    $recentRuns = pdo()->query("
        SELECT * FROM runs 
        ORDER BY started_at DESC 
        LIMIT 5
    ")->fetchAll() ?: [];
} catch (Throwable $e) {
    $recentRuns = [];
}

// Топ доменов по количеству результатов
try {
    $topDomains = pdo()->query("
        SELECT 
            d.id, d.domain, d.is_paused, d.score, d.last_scan_at,
            COUNT(t.id) as topics_count
        FROM domains d
        LEFT JOIN topics t ON t.domain_id = d.id
        GROUP BY d.id, d.domain, d.is_paused, d.score, d.last_scan_at
        ORDER BY topics_count DESC, d.score DESC
        LIMIT 10
    ")->fetchAll() ?: [];
} catch (Throwable $e) {
    $topDomains = [];
}

// Последние найденные темы
try {
    $recentTopics = pdo()->query("
        SELECT 
            t.*, d.domain 
        FROM topics t
        JOIN domains d ON d.id = t.domain_id
        ORDER BY t.created_at DESC
        LIMIT 20
    ")->fetchAll() ?: [];
} catch (Throwable $e) {
    $recentTopics = [];
}

$sourceLabels = [
    'forums' => 'Форумы',
    'telegram' => 'Telegram'
];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Панель мониторинга — DiscusScan</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
  <style>
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .stat-card { text-align: center; }
    .stat-value { font-size: 32px; font-weight: 700; color: var(--pri); }
    .stat-label { font-size: 14px; color: var(--muted); margin-top: 4px; }
    .domain-item { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; margin: 4px 0; }
    .domain-name { flex: 1; font-weight: 600; }
    .domain-stats { display: flex; gap: 8px; align-items: center; color: var(--muted); font-size: 12px; }
    .pause-btn { padding: 4px 8px; font-size: 11px; }
    .config-summary { background: rgba(255,255,255,0.02); padding: 12px; border-radius: 8px; margin: 12px 0; font-size: 13px; }
    .topic-item { padding: 8px 0; border-bottom: 1px solid var(--border); }
    .topic-title { font-weight: 600; margin-bottom: 4px; }
    .topic-meta { font-size: 12px; color: var(--muted); }
  </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
  <div class="card glass">
    <div class="card-title" style="display: flex; align-items: center; justify-content: space-between;">
      <span>🎯 Панель мониторинга</span>
      <a href="monitoring_wizard.php" class="btn small btn-ghost">⚙️ Настройки</a>
    </div>
    
    <div class="config-summary">
      <strong>Текущая тема:</strong> <?= e($topic) ?: 'Не задана' ?><br>
      <strong>Источники:</strong> <?= implode(', ', array_map(fn($s) => $sourceLabels[$s] ?? $s, $sources)) ?><br>
      <strong>Языки:</strong> <?= implode(', ', $languages) ?> | 
      <strong>Регионы:</strong> <?= implode(', ', $regions) ?> | 
      <strong>Окно:</strong> <?= $freshnessHours ?>ч<br>
      <strong>Последний запуск:</strong> <?= $lastRun ? date('Y-m-d H:i', strtotime($lastRun)) : 'Никогда' ?>
    </div>

    <div class="stats-grid">
      <div class="card glass stat-card">
        <div class="stat-value"><?= (int)$domainsStats['total'] ?></div>
        <div class="stat-label">Всего доменов</div>
      </div>
      <div class="card glass stat-card">
        <div class="stat-value"><?= (int)$domainsStats['active'] ?></div>
        <div class="stat-label">Активных доменов</div>
      </div>
      <div class="card glass stat-card">
        <div class="stat-value"><?= (int)$topicsStats['total'] ?></div>
        <div class="stat-label">Всего тем</div>
      </div>
      <div class="card glass stat-card">
        <div class="stat-value"><?= (int)$topicsStats['last_24h'] ?></div>
        <div class="stat-label">За 24 часа</div>
      </div>
    </div>

    <div class="row" style="gap: 16px; margin-bottom: 16px;">
      <form method="post" style="display: inline;">
        <input type="hidden" name="action" value="run_seed_domains">
        <button type="submit" class="btn primary">🌱 Семплинг доменов</button>
      </form>
      
      <form method="post" style="display: inline;">
        <input type="hidden" name="action" value="run_scan">
        <button type="submit" class="btn primary">🔍 Запустить поиск</button>
      </form>
    </div>
  </div>

  <div class="grid">
    <div class="card glass">
      <div class="card-title">Топ доменов по результатам</div>
      <?php if ($topDomains): ?>
        <?php foreach ($topDomains as $domain): ?>
          <div class="domain-item">
            <div class="domain-name"><?= e($domain['domain']) ?></div>
            <div class="domain-stats">
              <span><?= (int)$domain['topics_count'] ?> тем</span>
              <span>Score: <?= number_format((float)$domain['score'], 1) ?></span>
              <?php if ($domain['last_scan_at']): ?>
                <span><?= date('m-d H:i', strtotime($domain['last_scan_at'])) ?></span>
              <?php endif; ?>
            </div>
            <form method="post" style="display: inline;">
              <input type="hidden" name="action" value="toggle_domain_pause">
              <input type="hidden" name="domain_id" value="<?= $domain['id'] ?>">
              <button type="submit" class="btn small pause-btn">
                <?= $domain['is_paused'] ? 'Включить' : 'Пауза' ?>
              </button>
            </form>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="alert">Домены пока не найдены. Запустите семплинг доменов.</div>
      <?php endif; ?>
    </div>

    <div class="card glass">
      <div class="card-title">Последние запуски</div>
      <?php if ($recentRuns): ?>
        <div class="table-wrap" style="max-height: 300px;">
          <table class="table">
            <thead>
              <tr><th>Дата</th><th>Статус</th><th>Найдено</th><th>Окно</th></tr>
            </thead>
            <tbody>
              <?php foreach ($recentRuns as $run): ?>
                <tr>
                  <td><?= $run['started_at'] ? date('m-d H:i', strtotime($run['started_at'])) : '—' ?></td>
                  <td>
                    <?php if ($run['status'] === 'completed'): ?>
                      <span style="color: var(--ok)">✓</span>
                    <?php elseif ($run['status'] === 'started'): ?>
                      <span style="color: var(--pri)">⏳</span>
                    <?php else: ?>
                      <span style="color: var(--bad)">✗</span>
                    <?php endif; ?>
                  </td>
                  <td><?= (int)$run['found_count'] ?></td>
                  <td>
                    <?php if ($run['window_from'] && $run['window_to']): ?>
                      <?= date('m-d H:i', strtotime($run['window_from'])) ?> - <?= date('H:i', strtotime($run['window_to'])) ?>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="alert">Запусков пока не было.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card glass">
    <div class="card-title">Последние найденные темы</div>
    <?php if ($recentTopics): ?>
      <div style="max-height: 400px; overflow-y: auto;">
        <?php foreach ($recentTopics as $topic): ?>
          <div class="topic-item">
            <div class="topic-title">
              <a href="<?= e($topic['url']) ?>" target="_blank" rel="noopener">
                <?= e($topic['title']) ?>
              </a>
            </div>
            <div class="topic-meta">
              <?= e($topic['domain']) ?> • 
              <?= $topic['published_at'] ? date('Y-m-d H:i', strtotime($topic['published_at'])) : 'Дата неизвестна' ?> • 
              Score: <?= number_format((float)$topic['score'], 1) ?>
              <?php if ($topic['author']): ?>
                • Автор: <?= e($topic['author']) ?>
              <?php endif; ?>
            </div>
            <?php if ($topic['snippet']): ?>
              <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
                <?= e(mb_substr($topic['snippet'], 0, 200)) ?><?= mb_strlen($topic['snippet']) > 200 ? '...' : '' ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="alert">Темы пока не найдены. Запустите поиск.</div>
    <?php endif; ?>
  </div>
</main>

<?php include 'footer.php'; ?>
</body>
</html>