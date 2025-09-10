<?php
require_once __DIR__ . '/db.php';
require_login();

// Добавление источника
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_source') {
    $url = trim($_POST['url'] ?? '');
    if ($url !== '') {
        $host = host_from_url($url);
        if ($host !== '') {
            try {
                $stmt = pdo()->prepare("INSERT INTO sources (host, url, is_active) VALUES (?,?,1)
                    ON DUPLICATE KEY UPDATE url=VALUES(url), is_active=1");
                $stmt->execute([$host, $url]);
                app_log('info', 'sources', 'Source added/updated', ['host' => $host, 'url' => $url]);
            } catch (Throwable $e) {
                app_log('error', 'sources', 'Add source failed', ['error' => $e->getMessage()]);
            }
        }
    }
    header('Location: index.php#sources');
    exit;
}

/// Переключение активности источника (с учётом возврата на нужный экран)
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    pdo()->exec("UPDATE sources SET is_active = 1 - is_active WHERE id = {$id}");
    $ret = $_GET['return'] ?? '';
    if ($ret === 'domains') {
        header('Location: index.php?view=domains&source=' . $id);
    } else {
        header('Location: index.php#sources');
    }
    exit;
}

// Базовые данные для дашборда
$lastScan = pdo()->query("SELECT * FROM scans ORDER BY id DESC LIMIT 1")->fetch() ?: null;
$totalSites = (int)pdo()->query("SELECT COUNT(*) FROM sources WHERE is_active=1")->fetchColumn();
$totalLinks = (int)pdo()->query("SELECT COUNT(*) FROM links")->fetchColumn();
$lastFound = $lastScan ? (int)$lastScan['found_links'] : 0;
$lastScanAt = $lastScan && $lastScan['finished_at'] ? date('Y-m-d H:i', strtotime($lastScan['finished_at'])) : '—';

$srcs = pdo()->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM links l WHERE l.source_id=s.id) AS links_count
    FROM sources s ORDER BY created_at DESC
")->fetchAll();

// Топ доменов по числу упоминаний за 30 дней (для чипов)
$domains = pdo()->query("
    SELECT 
        s.id,
        s.host,
        COUNT(l.id) AS links_count
    FROM sources s
    LEFT JOIN links l 
      ON l.source_id = s.id
     AND (l.first_found IS NULL OR l.first_found >= DATE_SUB(NOW(), INTERVAL 30 DAY))
    GROUP BY s.id, s.host
    ORDER BY links_count DESC, s.host ASC
    LIMIT 10
")->fetchAll() ?: [];

$recentLinks = pdo()->query("
    SELECT l.*, s.host FROM links l 
    JOIN sources s ON s.id=l.source_id
    ORDER BY (l.last_seen IS NULL) ASC, l.last_seen DESC, (l.first_found IS NULL) ASC, l.first_found DESC
    LIMIT 20
")->fetchAll();

$period = (int)get_setting('scan_period_min', 15);
$cronSecret = (string)get_setting('cron_secret', '');
$cronUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
         . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . dirname($_SERVER['SCRIPT_NAME']) . '/scan.php?secret=' . urlencode($cronSecret);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Дашборд — Мониторинг</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<header class="topbar">
  <div class="brand">🔎 Мониторинг</div>
  <nav>
    <a href="index.php" class="active">Дашборд</a>
    <a href="sources.php">Домены</a>
    <a href="settings.php">Настройки</a>
    <a href="auth.php?logout=1">Выход</a>
  </nav>
</header>

<main class="container">
  <section class="cards">
    <div class="card glass">
      <div class="card-title">Последнее сканирование</div>
      <div class="metric"><?=e($lastScanAt)?></div>
    </div>
    <div class="card glass">
      <div class="card-title">Активных источников</div>
      <div class="metric"><?=$totalSites?></div>
    </div>
    <div class="card glass">
      <div class="card-title">Всего ссылок</div>
      <div class="metric"><?=$totalLinks?></div>
    </div>
    <div class="card glass">
      <div class="card-title">Найдено в последний раз</div>
      <div class="metric"><?=$lastFound?></div>
    </div>
  </section>

  <section class="actions">
    <a class="btn primary" href="scan.php?manual=1" target="_blank" rel="noopener">🚀 Запустить сканирование</a>
    <div class="hint">Периодичность (guard): каждые <?=$period?> мин. Для CRON: <code><?=e($cronUrl)?></code></div>
  </section>

  <section class="card glass" id="top-domains">
    <div class="card-title" style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
      <span>Топ доменов (за 30 дней)</span>
      <a class="btn-link" href="sources.php">Все домены →</a>
    </div>
    <?php
      // Возьмём первые 3 домена с наибольшим числом упоминаний
      $topDomains = array_slice($domains ?? [], 0, 3);
    ?>
    <div class="chips">
      <?php if ($topDomains): foreach ($topDomains as $d): ?>
        <a class="chip" href="sources.php?source=<?=$d['id']?>">
          <span class="chip-host"><?=e($d['host'])?></span>
          <span class="chip-count"><?= (int)$d['links_count'] ?></span>
        </a>
      <?php endforeach; else: ?>
        <div class="muted">Пока нет доменов с упоминаниями.</div>
      <?php endif; ?>
    </div>
  </section>

  <section class="stack" id="lists">
    <details class="card glass accordion">
      <summary class="card-title">Источники (сканируемые домены) — управление</summary>
      <div class="content">
        <form method="post" class="row add-form">
          <input type="hidden" name="action" value="add_source">
          <input type="url" name="url" placeholder="https://forum.example.com/" required>
          <button class="btn">Добавить</button>
        </form>
  
        <div class="table-wrap">
          <table class="table">
            <thead><tr>
              <th>Хост</th><th>URL</th><th>Активен</th><th>Ссылок</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($srcs as $s): ?>
              <tr>
                <td><?=e($s['host'])?></td>
                <td class="ellipsis"><a href="<?=e($s['url'])?>" target="_blank" rel="noopener"><?=e($s['url'])?></a></td>
                <td><?= $s['is_active'] ? '✅' : '⛔' ?></td>
                <td><span class="pill"><?= (int)$s['links_count'] ?></span></td>
                <td><a class="btn small" href="?toggle=<?=$s['id']?>"><?= $s['is_active'] ? 'Пауза' : 'Вкл.' ?></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="muted" style="margin-top:8px">«Пауза» — временно исключает домен из сканирования. Текущие ссылки остаются в базе, новые с этого домена не добавляются.</div>
      </div>
    </details>

    <div class="card glass">
      <div class="card-title">Последние 20 ссылок</div>
      <div class="table-wrap">
        <table class="table table-links">
          <colgroup>
            <col style="width:14%">
            <col style="width:38%">
            <col style="width:32%">
            <col style="width:10%">
            <col style="width:6%">
          </colgroup>
          <thead>
            <tr>
              <th>Домен</th>
              <th>Заголовок</th>
              <th>URL</th>
              <th>Время</th>
              <th class="col-views hide-md">Пок.</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recentLinks as $l): ?>
            <tr>
              <td><span class="cut"><?=e($l['host'])?></span></td>
              <td><span class="cut"><?=e($l['title'] ?? '—')?></span></td>
              <td><a class="cut" href="<?=e($l['url'])?>" target="_blank" rel="noopener"><?=e($l['url'])?></a></td>
              <td>
                <div class="dates">
                  <?= $l['last_seen'] ? 'Обн.: ' . date('d.m H:i', strtotime($l['last_seen'])) : 'Обн.: —' ?><br>
                  <?= $l['first_found'] ? 'Найд.: ' . date('d.m H:i', strtotime($l['first_found'])) : 'Найд.: —' ?>
                </div>
              </td>
              <td class="col-views hide-md"><?= (int)$l['times_seen'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</main>
</body>
</html>