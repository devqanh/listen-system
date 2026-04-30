<?php
/**
 * GET /api/v1/tags.php?category=ielts
 * Danh sách sub-category (tag) trong 1 category.
 *
 * Tham số:
 *   - category (required): slug category — ielts | daily-life-stories | oxford-3000
 *
 * Response:
 *   {
 *     "success": true,
 *     "data": [
 *       {"slug": "cambridge-ielts-19-academic", "title": "Cambridge IELTS 19 Academic", "fetched": 8, "total": 8},
 *       ...
 *     ],
 *     "meta": {"category": "ielts"}
 *   }
 */
require __DIR__ . '/_api.php';
api_init('GET');

$category = api_str('category', null, 80);
$pdo      = api_pdo();
$stmt     = $pdo->prepare(
    "SELECT tag_slug  AS slug,
            tag_title AS title,
            SUM(CASE WHEN status = 'fetched' THEN 1 ELSE 0 END) AS fetched,
            COUNT(*) AS total
       FROM lessons
      WHERE category_slug = ? AND tag_slug IS NOT NULL
   GROUP BY tag_slug, tag_title
   ORDER BY tag_title"
);
$stmt->execute([$category]);
$rows = array_map(static fn($r) => [
    'slug'    => $r['slug'],
    'title'   => $r['title'],
    'fetched' => (int)$r['fetched'],
    'total'   => (int)$r['total'],
], $stmt->fetchAll(PDO::FETCH_ASSOC));

api_ok($rows, ['category' => $category]);
