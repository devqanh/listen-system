<?php
/**
 * Orchestrator: chạy phase 1 (list) rồi phase 2 (detail).
 *
 * Cách dùng:
 *   php crawler/run.php                    # cào hết 3 category
 *   php crawler/run.php ielts              # chỉ cào category ielts
 *   php crawler/run.php ielts 5            # chỉ cào ielts, giới hạn 5 bài chi tiết/lượt
 *   php crawler/run.php --list-only        # chỉ phase 1
 *   php crawler/run.php --detail-only      # chỉ phase 2
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/crawl_list.php';
require_once __DIR__ . '/crawl_detail.php';

$cfg = require __DIR__ . '/config.php';
$pdo = db_open($cfg);

$args     = array_slice($argv, 1);
$listOnly = in_array('--list-only', $args, true);
$detOnly  = in_array('--detail-only', $args, true);
$args     = array_values(array_filter($args, static fn($a) => !str_starts_with($a, '--')));

$category = $args[0] ?? null;
$limit    = isset($args[1]) ? (int)$args[1] : 0;
$cats     = $category ? [$category] : null;

if (!$detOnly) {
    crawl_list_run($cfg, $pdo, $cats);
}
if (!$listOnly) {
    crawl_detail_run($cfg, $pdo, $category, $limit);
}

// Tóm tắt cuối:
$stmt = $pdo->query(
    "SELECT category_slug, status, COUNT(*) AS n
       FROM lessons
   GROUP BY category_slug, status
   ORDER BY category_slug, status"
);
echo "\n=== Tổng kết library ===\n";
foreach ($stmt as $r) {
    printf("%-22s %-10s %5d\n", $r['category_slug'], $r['status'], $r['n']);
}
