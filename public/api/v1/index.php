<?php
/**
 * GET /api/v1/
 * Trả danh mục các endpoint có sẵn — giúp client khám phá API.
 */
require __DIR__ . '/_api.php';
api_init('GET');

$base = (function () {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path  = preg_replace('#/index\.php$#', '', $_SERVER['SCRIPT_NAME']);
    return $proto . '://' . $host . $path;
})();

$pdo  = api_pdo();
$keyFile = dirname(__DIR__, 3) . '/data/api.key';

api_ok([
    'name'       => 'Listen-English Library API',
    'version'    => 'v1',
    'auth'       => is_file($keyFile) && trim((string)file_get_contents($keyFile)) !== ''
                       ? 'X-API-Key required (header) hoặc ?api_key= (query)'
                       : 'public (không cần API key)',
    'cors'       => 'Access-Control-Allow-Origin: *',
    'stats'      => [
        'total_lessons_fetched' => (int)$pdo->query("SELECT COUNT(*) FROM lessons WHERE status = 'fetched'")->fetchColumn(),
        'total_transcript_lines' => (int)$pdo->query('SELECT COUNT(*) FROM transcript_lines')->fetchColumn(),
    ],
    'endpoints'  => [
        [
            'path'   => "$base/categories.php",
            'method' => 'GET',
            'desc'   => 'Danh sách 3 category (ielts, daily-life-stories, oxford-3000) + counts.',
        ],
        [
            'path'   => "$base/tags.php?category={slug}",
            'method' => 'GET',
            'desc'   => 'Sub-category (Cambridge IELTS X, Level N, …) trong 1 category.',
        ],
        [
            'path'   => "$base/lessons.php?category={cat}&tag={tag}&q={search}&page=1&limit=20&sort=tag_title",
            'method' => 'GET',
            'desc'   => 'List bài đã fetched, có lọc + phân trang. sort: tag_title|title|id_desc|id_asc.',
        ],
        [
            'path'   => "$base/lesson.php?id={id}  hoặc  ?slug={slug}",
            'method' => 'GET',
            'desc'   => 'Metadata 1 bài (không kèm transcript).',
        ],
        [
            'path'   => "$base/transcript.php?id={id}&words=1",
            'method' => 'GET',
            'desc'   => 'Transcript đầy đủ + audio_url. words=0 để bỏ field words[] nếu không cần.',
        ],
    ],
    'envelope'   => [
        'success' => '{"success": true, "data": ..., "meta": {...}}',
        'error'   => '{"success": false, "error": {"code": "BAD_REQUEST", "message": "..."}}',
    ],
]);
