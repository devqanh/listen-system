<?php
/**
 * Phase 2: với mỗi lesson đang pending/failed, fetch trang chi tiết → bóc transcript + tải mp3.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/parser.php';

function crawl_detail_run(array $cfg, PDO $pdo, ?string $category = null, int $limit = 0): int
{
    $rows = db_pending_lessons($pdo, $category, $limit);
    db_log($pdo, 'detail', "Bắt đầu fetch chi tiết: " . count($rows) . " bài");
    $ok = 0;
    foreach ($rows as $row) {
        try {
            $referer = $cfg['base_url'] . $cfg['list_path'] . '?category=' . rawurlencode($row['category_slug']);
            db_log($pdo, 'detail', "[{$row['id']}] {$row['slug']}");
            $html   = http_fetch($cfg, $row['url'], $referer, true);
            $detail = parse_detail($html);

            if (empty($detail['lines'])) {
                throw new RuntimeException('Không có transcript-line (có thể bài này thuộc Pro hoặc cookie hết hạn)');
            }
            if (!$detail['audio_url']) {
                throw new RuntimeException('Không tìm thấy <source> mp3');
            }

            // Lưu mp3 theo {audio_dir}/{category}/{slug}.mp3
            $localRel = $row['category_slug'] . '/' . $row['slug'] . '.mp3';
            $localAbs = rtrim($cfg['audio_dir'], '/\\') . '/' . $localRel;
            http_download($cfg, $detail['audio_url'], $localAbs, $row['url']);

            db_save_lesson_detail($pdo, (int)$row['id'], [
                'audio_url'   => $detail['audio_url'],
                'audio_local' => $localRel,
                'lines'       => $detail['lines'],
            ]);
            $ok++;
            db_log($pdo, 'detail', "  -> OK lines=" . count($detail['lines']) . " mp3=$localRel");
        } catch (Throwable $e) {
            db_mark_failed($pdo, (int)$row['id'], $e->getMessage());
            db_log($pdo, 'detail', "  -> FAIL: " . $e->getMessage());
        }
    }
    db_log($pdo, 'detail', "Hoàn tất: thành công $ok / " . count($rows));
    return $ok;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $cfg = require __DIR__ . '/config.php';
    $pdo = db_open($cfg);
    $cat = $argv[1] ?? null;
    $lim = (int)($argv[2] ?? 0);
    crawl_detail_run($cfg, $pdo, $cat, $lim);
}
