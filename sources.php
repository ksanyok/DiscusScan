<?php
require_once __DIR__ . '/db.php';
require_login();

// Toggle domain activity from this page and come back preserving selection
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    pdo()->exec("UPDATE sources SET is_active = 1 - is_active WHERE id = {$id}");
    $back = (isset($_GET['source']) && ctype_digit($_GET['source'])) ? (int)$_GET['source'] : $id;
    header('Location: sources.php?source=' . $back);
    exit;
}

// Domains summary
$domains = pdo()->query("
    SELECT s.id, s.host, s.is_active, COUNT(l.id) AS links_count,
           MAX(COALESCE(l.last_seen, l.first_found)) AS last_seen
    FROM sources s
    LEFT JOIN links l ON l.source_id = s.id
    GROUP BY s.id, s.host, s.is_active
    ORDER BY links_count DESC, s.host ASC
")->fetchAll();

// Selected domain
$activeSource = (isset($_GET['source']) && ctype_digit($_GET['source'])) ? (int)$_GET['source'] : null;
$activeHost = null;
$domainLinks = [];
if ($activeSource) {
    $stmt = pdo()->prepare("SELECT host FROM sources WHERE id=?");
    $stmt->execute([$activeSource]);
    $activeHost = $stmt->fetchColumn() ?: null;

    $stmt = pdo()->prepare("
        SELECT l.* FROM links l
        WHERE l.source_id = ?
        ORDER BY COALESCE(l.last_seen, l.first_found) DESC
        LIMIT 500
    ");
    $stmt->execute([$activeSource]);
    $domainLinks = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Домены — Мониторинг</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
  <section class="grid domains">
    <div class="card glass left">
      <div class="card-title">Домены с упоминаниями</div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Домен</th><th>Ссылок</th><th>Обновлено</th>
            <th class="col-status">Статус</th><th class="col-actions">Упр.</th>
          </tr></thead>
          <tbody>
            <?php foreach ($domains as $d): ?>
              <tr<?= $activeSource===$d['id'] ? ' style="background:rgba(255,255,255,.05)"' : '';?>>
                <td><a href="sources.php?source=<?=$d['id']?>"><?=e($d['host'])?></a></td>
                <td><span class="pill"><?=$d['links_count']?></span></td>
                <td><?= $d['last_seen'] ? date('Y-m-d H:i', strtotime($d['last_seen'])) : '—' ?></td>
                <td class="col-status"><?= !empty($d['is_active']) ? '✅' : '⛔' ?></td>
                <td class="col-actions">
                  <a class="btn small" href="sources.php?toggle=<?=$d['id']?>&amp;source=<?=$d['id']?>"><?= !empty($d['is_active']) ? 'Пауза' : 'Вкл.' ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="muted" style="margin-top:8px">Клик по домену — покажем ссылки справа.</div>
    </div>

    <div class="card glass">
      <div class="card-title">Ссылки по домену: <?= $activeHost ? e($activeHost) : 'не выбран' ?></div>
      <?php if ($activeSource && $domainLinks): ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Заголовок</th><th>URL</th><th>Найдено</th><th>Обновлено</th><th>Пок.</th></tr></thead>
            <tbody>
              <?php foreach ($domainLinks as $l): ?>
                <tr>
                  <td class="ellipsis"><?=e($l['title'] ?? '—')?></td>
                  <td class="ellipsis"><a href="<?=e($l['url'])?>" target="_blank" rel="noopener"><?=e($l['url'])?></a></td>
                  <td><?= $l['first_found'] ? date('Y-m-d H:i', strtotime($l['first_found'])) : '—' ?></td>
                  <td><?= $l['last_seen'] ? date('Y-m-d H:i', strtotime($l['last_seen'])) : '—' ?></td>
                  <td><?= (int)$l['times_seen'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php elseif ($activeSource): ?>
        <div class="alert">Для выбранного домена пока нет ссылок.</div>
      <?php else: ?>
        <div class="alert">Выберите домен слева, чтобы увидеть ссылки.</div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php include 'footer.php'; ?>
</body>
</html>
