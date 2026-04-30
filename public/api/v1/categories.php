<?php
/**
 * GET /api/v1/categories.php
 * Danh sách 3 category gốc + đếm bài đã fetch / tổng.
 *
 * Response:
 *   {
 *     "success": true,
 *     "data": [
 *       {"slug": "ielts", "fetched": 12, "total": 80},
 *       {"slug": "daily-life-stories", "fetched": 8, "total": 40},
 *       ...
 *     ]
 *   }
 */
require __DIR__ . '/_api.php';
api_init('GET');

$pdo  = api_pdo();
$stmt = $pdo->query(
    "SELECT category_slug AS slug,
            SUM(CASE WHEN status = 'fetched' THEN 1 ELSE 0 END) AS fetched,
            COUNT(*) AS total
       FROM lessons
   GROUP BY category_slug
   ORDER BY category_slug"
);
$rows = array_map(static fn($r) => [
    'slug'    => $r['slug'],
    'fetched' => (int)$r['fetched'],
    'total'   => (int)$r['total'],
], $stmt->fetchAll(PDO::FETCH_ASSOC));

api_ok($rows);
