<?php
require_once __DIR__ . '/db.php';
require_login();

// Toggle domain activity (legacy)
if (isset($_GET['toggle']) && ctype_digit($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    pdo()->exec("UPDATE sources SET is_active = 1 - is_active WHERE id = {$id}");
    $back = (isset($_GET['source']) && ctype_digit($_GET['source'])) ? (int)$_GET['source'] : $id;
    header('Location: sources.php?source=' . $back);
    exit;
}

// New: toggle is_enabled
if (isset($_GET['toggle_enabled']) && ctype_digit($_GET['toggle_enabled'])) {
    $id = (int)$_GET['toggle_enabled'];
    pdo()->exec("UPDATE sources SET is_enabled = 1 - COALESCE(is_enabled,1) WHERE id = {$id}");
    header('Location: sources.php?source=' . $id);
    exit;
}
// New: toggle is_paused
if (isset($_GET['toggle_paused']) && ctype_digit($_GET['toggle_paused'])) {
    $id = (int)$_GET['toggle_paused'];
    pdo()->exec("UPDATE sources SET is_paused = 1 - COALESCE(is_paused,0) WHERE id = {$id}");
    header('Location: sources.php?source=' . $id);
    exit;
}

// NEW: enable discovered domain
if (isset($_GET['enable_discovered'])) {
    $domain = strtolower(trim($_GET['enable_discovered']));
    $domain = preg_replace('~[^a-z0-9\.-]~', '', $domain);
    if ($domain !== '') {
        try {
            $plat = detect_platform($domain, null);
        } catch (Throwable $e) { $plat = 'unknown'; }
        // upsert source
        $st = pdo()->prepare("INSERT INTO sources (host, url, is_active, is_enabled, is_paused, note, platform, discovered_via)
                              VALUES (?,?,?,?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled), platform=VALUES(platform), discovered_via=VALUES(discovered_via)");
        $st->execute([$domain, 'https://' . $domain, 1, 1, 0, 'enabled from discovered', $plat, 'llm_discovery']);
        // mark discovered as verified
        db_mark_discovered_status($domain, 'verified', 5);
        header('Location: sources.php');
        exit;
    }
}

// Freshness window
$freshnessDays = (int)get_setting('freshness_days', 7);
try {
    $sinceDt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->sub(new DateInterval('P' . max(1, $freshnessDays) . 'D'));
    $sinceSql = $sinceDt->format('Y-m-d H:i:s');
    $sinceIso = $sinceDt->format('Y-m-d\TH:i:s\Z');
} catch (Throwable $e) {
    $sinceSql = gmdate('Y-m-d H:i:s');
    $sinceIso = gmdate('Y-m-d\TH:i:s\Z');
}

// Domains summary with fresh counter
$stmt = pdo()->prepare("
    SELECT s.id, s.host,
           COALESCE(s.is_active,1) AS is_active,
           COALESCE(s.is_enabled,1) AS is_enabled,
           COALESCE(s.is_paused,0)  AS is_paused,
           COUNT(l.id) AS links_count,
           SUM(CASE WHEN l.published_at IS NOT NULL AND l.published_at >= :since THEN 1 ELSE 0 END) AS fresh_count,
           MAX(COALESCE(l.last_seen, l.first_found)) AS last_seen
    FROM sources s
    LEFT JOIN links l ON l.source_id = s.id
    GROUP BY s.id, s.host, is_active, is_enabled, is_paused
    ORDER BY fresh_count DESC, links_count DESC, s.host ASC
");
$stmt->execute([':since' => $sinceSql]);
$domains = $stmt->fetchAll();

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
        ORDER BY COALESCE(l.published_at, l.last_seen, l.first_found) DESC
        LIMIT 500
    ");
    $stmt->execute([$activeSource]);
    $domainLinks = $stmt->fetchAll();
}

// Paused hosts list for preview
$pausedHosts = pdo()->query("SELECT host FROM sources WHERE COALESCE(is_paused,0)=1 ORDER BY host")->fetchAll(PDO::FETCH_COLUMN) ?: [];

// NEW: Discovered sources block data
$disc = pdo()->query("SELECT domain, proof_url, platform_guess, status, score, first_seen_at, last_checked_at FROM discovered_sources ORDER BY first_seen_at DESC LIMIT 200")->fetchAll() ?: [];
$discByStatus = ['new'=>[], 'verified'=>[], 'failed'=>[], 'rejected'=>[]];
foreach ($disc as $r) { $discByStatus[$r['status']][] = $r; }
$discCounts = [ 'new'=>count($discByStatus['new']), 'verified'=>count($discByStatus['verified']), 'failed'=>count($discByStatus['failed']), 'rejected'=>count($discByStatus['rejected']) ];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Домены — Мониторинг</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
  <section class="grid domains">
    <div class="card glass left">
      <div class="card-title" style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
        <span>Домены с упоминаниями</span>
        <details>
          <summary class="btn small">Показать paused hosts</summary>
          <div class="muted" style="margin-top:6px">
            <?php if ($pausedHosts): ?>
              <code><?=e(json_encode(array_values($pausedHosts), JSON_UNESCAPED_UNICODE))?></code>
            <?php else: ?>
              <span>Нет паузных источников</span>
            <?php endif; ?>
          </div>
        </details>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Домен</th>
            <th>Всего</th>
            <th>За <?=$freshnessDays?>д</th>
            <th>Обновлено</th>
            <th>Enabled</th>
            <th>Pause</th>
            <th class="col-actions">Упр.</th>
          </tr></thead>
          <tbody>
            <?php foreach ($domains as $d): ?>
              <tr<?= $activeSource===$d['id'] ? ' style="background:rgba(255,255,255,.05)"' : '';?>>
                <td><a href="sources.php?source=<?=$d['id']?>"><?=e($d['host'])?></a></td>
                <td><span class="pill"><?=$d['links_count']?></span></td>
                <td><span class="pill"><?= (int)$d['fresh_count'] ?></span></td>
                <td><?= $d['last_seen'] ? date('Y-m-d H:i', strtotime($d['last_seen'])) : '—' ?></td>
                <td class="col-status"><?= !empty($d['is_enabled']) ? '✅' : '⛔' ?>
                  <a class="btn small" href="sources.php?toggle_enabled=<?=$d['id']?>">перекл.</a>
                </td>
                <td class="col-status"><?= !empty($d['is_paused']) ? '⏸️' : '▶️' ?>
                  <a class="btn small" href="sources.php?toggle_paused=<?=$d['id']?>">перекл.</a>
                </td>
                <td class="col-actions">
                  <a class="btn small" href="sources.php?toggle=<?=$d['id']?>&amp;source=<?=$d['id']?>"><?= !empty($d['is_active']) ? 'Пауза (legacy)' : 'Вкл. (legacy)' ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="muted" style="margin-top:8px">Клик по домену — покажем ссылки справа. Счётчик «За <?=$freshnessDays?>д» считает по published_at.</div>

      <!-- NEW: Discovered sources block -->
      <hr>
      <div class="card-title">Discovered (LLM)
        <span class="pill">new: <?=$discCounts['new']?></span>
        <span class="pill">verified: <?=$discCounts['verified']?></span>
        <span class="pill">failed: <?=$discCounts['failed']?></span>
      </div>
      <?php if ($disc): ?>
        <div class="table-wrap">
          <table class="table compact">
            <thead><tr><th>Домен</th><th>Proof</th><th>Платформа</th><th>Статус</th><th class="col-actions">Действие</th></tr></thead>
            <tbody>
              <?php foreach ($disc as $r): ?>
                <tr>
                  <td class="ellipsis"><?=e($r['domain'])?></td>
                  <td class="ellipsis"><a href="<?=e($r['proof_url'])?>" target="_blank" rel="noopener">proof</a></td>
                  <td><?=e($r['platform_guess'] ?: 'unknown')?></td>
                  <td><?=e($r['status'])?></td>
                  <td>
                    <?php if ($r['status']==='new' || $r['status']==='failed'): ?>
                      <a class="btn small" href="sources.php?enable_discovered=<?=urlencode($r['domain'])?>">Включить</a>
                    <?php else: ?>
                      <span class="pill">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="muted">Нет кандидатов Discovery.</div>
      <?php endif; ?>
    </div>

    <div class="card glass">
      <div class="card-title">Ссылки по домену: <?= $activeHost ? e($activeHost) : 'не выбран' ?></div>
      <?php if ($activeSource && $domainLinks): ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Заголовок</th><th>URL</th><th>Опубл.</th><th>Найдено</th><th>Обновлено</th><th>Пок.</th></tr></thead>
            <tbody>
              <?php foreach ($domainLinks as $l): ?>
                <tr>
                  <td class="ellipsis"><?=e($l['title'] ?? '—')?></td>
                  <td class="ellipsis"><a href="<?=e($l['url'])?>" target="_blank" rel="noopener"><?=e($l['url'])?></a></td>
                  <td><?= $l['published_at'] ? date('Y-m-d H:i', strtotime($l['published_at'])) : '—' ?></td>
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
