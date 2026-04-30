<?php
/**
 * Phase 1: cào danh sách tất cả bài thuộc 3 category, kèm phân trang.
 * Chỉ insert bài mới (UNIQUE slug) → có thể chạy lại định kỳ để bắt bài mới.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/parser.php';

function crawl_list_run(array $cfg, PDO $pdo, ?array $onlyCategories = null): int
{
    $cats = $onlyCategories ?: $cfg['categories'];
    $newCount = 0;
    foreach ($cats as $cat) {
        $page    = 1;
        $referer = $cfg['base_url'] . $cfg['list_path'];
        $url     = $referer . '?category=' . rawurlencode($cat);

        while ($url !== null) {
            db_log($pdo, 'list', "Fetch $url");
            // Listing page hay đổi (có thêm bài mới) nên không cache:
            $html   = http_fetch($cfg, $url, $referer, false);
            $parsed = parse_listing($html);
            $added  = 0;
            foreach ($parsed['lessons'] as $l) {
                $l['category_slug'] = $cat;
                if (db_upsert_lesson_listing($pdo, $l)) {
                    $added++;
                    $newCount++;
                }
            }
            db_log($pdo, 'list', "  category=$cat page=$page found=" . count($parsed['lessons']) . " new=$added");

            if (!$parsed['next_url'] || $parsed['next_url'] === $url) {
                break;
            }
            $referer = $url;
            $url     = $parsed['next_url'];
            $page++;
            // Hard cap để tránh loop bất tận do site đổi pagination:
            if ($page > 50) {
                db_log($pdo, 'list', "  WARN: dừng vì >50 trang, kiểm tra thủ công");
                break;
            }
        }
    }
    db_log($pdo, 'list', "Tổng số bài mới thêm: $newCount");
    return $newCount;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $cfg = require __DIR__ . '/config.php';
    $pdo = db_open($cfg);
    $only = null;
    if (isset($argv[1])) {
        $only = explode(',', $argv[1]);
    }
    crawl_list_run($cfg, $pdo, $only);
}
