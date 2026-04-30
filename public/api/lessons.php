<?php
/** API JSON: liệt kê lesson đã fetch (cho dropdown chọn bài). */

require __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pdo  = app_pdo();
$cat  = $_GET['category'] ?? null;
$q    = trim($_GET['q'] ?? '');

$sql  = "SELECT id, slug, title, category_slug, tag_slug, tag_title, total_lines, audio_local
           FROM lessons
          WHERE status = 'fetched'";
$args = [];
if ($cat) {
    $sql   .= ' AND category_slug = ?';
    $args[] = $cat;
}
if ($q !== '') {
    $sql   .= ' AND title LIKE ?';
    $args[] = '%' . $q . '%';
}
$sql .= ' ORDER BY tag_title, title';

$stmt = $pdo->prepare($sql);
$stmt->execute($args);
echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
