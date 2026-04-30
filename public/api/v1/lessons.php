<?php
/**
 * GET /api/v1/lessons.php
 * Liệt kê bài (đã fetched) có lọc + phân trang.
 *
 * Tham số:
 *   - category (optional): filter theo category_slug
 *   - tag      (optional): filter theo tag_slug (sub-category)
 *   - q        (optional): tìm trong title (LIKE %q%)
 *   - page     (optional, default=1): trang
 *   - limit    (optional, default=20, max=100): số bài / trang
 *   - sort     (optional, default=tag_title): tag_title | title | id_desc | id_asc
 *
 * Response:
 *   {
 *     "success": true,
 *     "data": [
 *       {
 *         "id": 73,
 *         "slug": "cambridge-ielts-10-academic-listening-part-1-test-1",
 *         "title": "Cambridge IELTS 10 Academic Listening Test 1 Part 1",
 *         "category_slug": "ielts",
 *         "tag_slug": "cambridge-ielts-10-academic",
 *         "tag_title": "Cambridge IELTS 10 Academic",
 *         "total_lines": 58,
 *         "audio_url": "http://host/audio/ielts/...mp3",
 *         "source_url": "https://engnovate.com/.../",
 *         "fetched_at": "2026-04-30 01:16:00"
 *       },
 *       ...
 *     ],
 *     "meta": {"page": 1, "limit": 20, "total": 12, "has_more": false}
 *   }
 */
require __DIR__ . '/_api.php';
api_init('GET');

$category = $_GET['category'] ?? '';
$tag      = $_GET['tag']      ?? '';
$q        = trim($_GET['q']   ?? '');
$page     = api_int('page',  1, 1);
$limit    = api_int('limit', 20, 1, 100);
$sort     = $_GET['sort'] ?? 'tag_title';
$offset   = ($page - 1) * $limit;

$where = ["status = 'fetched'"];
$args  = [];
if ($category !== '') { $where[] = 'category_slug = ?'; $args[] = $category; }
if ($tag      !== '') { $where[] = 'tag_slug = ?';      $args[] = $tag; }
if ($q        !== '') { $where[] = 'title LIKE ?';      $args[] = '%' . $q . '%'; }

$orderBy = match ($sort) {
    'title'    => 'title ASC',
    'id_desc'  => 'id DESC',
    'id_asc'   => 'id ASC',
    default    => 'tag_title ASC, title ASC',
};

$pdo  = api_pdo();
$base = 'FROM lessons WHERE ' . implode(' AND ', $where);

$totalStmt = $pdo->prepare("SELECT COUNT(*) $base");
$totalStmt->execute($args);
$total = (int)$totalStmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT id, slug, title, category_slug, tag_slug, tag_title, total_lines,
            audio_local, url AS source_url, fetched_at
     $base
     ORDER BY $orderBy LIMIT $limit OFFSET $offset"
);
$stmt->execute($args);

$rows = array_map(static function ($r) {
    return [
        'id'            => (int)$r['id'],
        'slug'          => $r['slug'],
        'title'         => $r['title'],
        'category_slug' => $r['category_slug'],
        'tag_slug'      => $r['tag_slug'],
        'tag_title'     => $r['tag_title'],
        'total_lines'   => (int)$r['total_lines'],
        'audio_url'     => $r['audio_local'] ? api_audio_url($r['audio_local']) : null,
        'source_url'    => $r['source_url'],
        'fetched_at'    => $r['fetched_at'],
    ];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

api_ok($rows, [
    'page'     => $page,
    'limit'    => $limit,
    'total'    => $total,
    'has_more' => $offset + count($rows) < $total,
    'filters'  => array_filter([
        'category' => $category ?: null,
        'tag'      => $tag ?: null,
        'q'        => $q ?: null,
    ]),
]);
