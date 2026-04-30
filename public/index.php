<?php
require __DIR__ . '/_bootstrap.php';

$pdo = app_pdo();

$cats = [];
foreach ($pdo->query(
    "SELECT category_slug,
            SUM(CASE WHEN status = 'fetched' THEN 1 ELSE 0 END) AS done,
            COUNT(*) AS total
       FROM lessons
   GROUP BY category_slug
   ORDER BY category_slug"
) as $r) {
    $cats[] = $r;
}

$cat = $_GET['category'] ?? '';
$tag = $_GET['tag']      ?? '';
$q   = trim($_GET['q']   ?? '');

$tags = [];
if ($cat !== '') {
    $tstmt = $pdo->prepare(
        "SELECT tag_slug, tag_title,
                SUM(CASE WHEN status = 'fetched' THEN 1 ELSE 0 END) AS done,
                COUNT(*) AS total
           FROM lessons
          WHERE category_slug = ? AND tag_slug IS NOT NULL
       GROUP BY tag_slug, tag_title
       ORDER BY tag_title"
    );
    $tstmt->execute([$cat]);
    $tags = $tstmt->fetchAll();
}

$where = ["status = 'fetched'"];
$args  = [];
if ($cat !== '') { $where[] = 'category_slug = ?'; $args[] = $cat; }
if ($tag !== '') { $where[] = 'tag_slug = ?';      $args[] = $tag; }
if ($q   !== '') { $where[] = 'title LIKE ?';      $args[] = '%' . $q . '%'; }

$sql = 'SELECT id, slug, title, category_slug, tag_title, total_lines, url
          FROM lessons
         WHERE ' . implode(' AND ', $where) . '
      ORDER BY tag_title, title';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$lessons = $stmt->fetchAll();

$totalAll = (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE status = 'fetched'")->fetchColumn();
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Listen-English Library</title>
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body class="library-page">

<header class="hero">
  <div class="hero-inner">
    <h1>🎧 Listen-English Library</h1>
    <p>Luyện <strong>dictation</strong> với <?= $totalAll ?> bài đã lưu — nghe đoạn audio, gõ lại từng từ bạn nghe được.</p>
    <div class="hero-stats">
      <?php foreach ($cats as $c): ?>
        <a href="?category=<?= h($c['category_slug']) ?>" class="stat-pill cat-<?= h($c['category_slug']) ?> <?= $cat === $c['category_slug'] ? 'active' : '' ?>">
          <span class="stat-name"><?= h($c['category_slug']) ?></span>
          <span class="stat-num"><?= (int)$c['done'] ?> / <?= (int)$c['total'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<section class="filters">
  <form method="get" class="filter-form">
    <input type="hidden" name="category" value="<?= h($cat) ?>">
    <?php if ($tags): ?>
      <select name="tag" onchange="this.form.submit()">
        <option value="">— Tất cả nhóm —</option>
        <?php foreach ($tags as $t): ?>
          <option value="<?= h($t['tag_slug']) ?>" <?= $tag === $t['tag_slug'] ? 'selected' : '' ?>>
            <?= h($t['tag_title']) ?> (<?= (int)$t['done'] ?>/<?= (int)$t['total'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="🔎 Tìm tiêu đề bài…">
    <button type="submit">Lọc</button>
    <?php if ($cat || $tag || $q): ?>
      <a class="reset" href="index.php">Xóa lọc</a>
    <?php endif; ?>
  </form>
</section>

<section class="lesson-grid">
  <?php if (!$lessons): ?>
    <div class="empty">
      <p>Chưa có bài nào hiển thị. Chạy crawler để thêm:</p>
      <pre>python -m crawler_py.run</pre>
      <pre>php crawler/run.php</pre>
    </div>
  <?php endif; ?>

  <?php
    $currentTag = null;
    foreach ($lessons as $l):
        if ($l['tag_title'] !== $currentTag):
            if ($currentTag !== null) echo '</div>';
            $currentTag = $l['tag_title'];
            echo '<div class="tag-section">';
            echo '<h2 class="tag-heading">' . h($currentTag ?: '(không phân nhóm)') . '</h2>';
            echo '<div class="tag-grid">';
        endif;
  ?>
    <a class="lesson-card" href="practice.php?id=<?= (int)$l['id'] ?>">
      <span class="cat-badge cat-<?= h($l['category_slug']) ?>"><?= h($l['category_slug']) ?></span>
      <h3 class="lesson-title"><?= h($l['title']) ?></h3>
      <div class="lesson-meta">
        <span class="lines-count">📝 <?= (int)$l['total_lines'] ?> câu</span>
        <span class="play-cta">▶ Luyện</span>
      </div>
    </a>
  <?php endforeach; if ($currentTag !== null) echo '</div></div>'; ?>
</section>



</body>
</html>
