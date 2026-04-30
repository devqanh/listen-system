<?php
/**
 * GET /api/v1/lesson.php?id=NN
 * hoặc /api/v1/lesson.php?slug=cambridge-ielts-10-...
 *
 * Trả metadata 1 bài (KHÔNG kèm transcript — dùng /transcript.php cho transcript đầy đủ).
 *
 * Response:
 *   {
 *     "success": true,
 *     "data": {
 *       "id": 73,
 *       "slug": "cambridge-ielts-10-...",
 *       "title": "Cambridge IELTS 10 ...",
 *       "category_slug": "ielts",
 *       "tag_slug": "cambridge-ielts-10-academic",
 *       "tag_title": "Cambridge IELTS 10 Academic",
 *       "total_lines": 58,
 *       "audio_url": "http://host/audio/ielts/...mp3",
 *       "source_url": "https://engnovate.com/...",
 *       "fetched_at": "2026-04-30 01:16:00"
 *     }
 *   }
 */
require __DIR__ . '/_api.php';
api_init('GET');

$pdo = api_pdo();

if (isset($_GET['id'])) {
    $id   = api_int('id', null, 1);
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ? AND status = 'fetched'");
    $stmt->execute([$id]);
} elseif (isset($_GET['slug'])) {
    $slug = api_str('slug', null, 200);
    $stmt = $pdo->prepare("SELECT * FROM lessons WHERE slug = ? AND status = 'fetched'");
    $stmt->execute([$slug]);
} else {
    api_error('BAD_REQUEST', 'Cần truyền `id` hoặc `slug`');
}

$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) api_error('NOT_FOUND', 'Bài không tồn tại hoặc chưa được crawl', 404);

api_ok([
    'id'            => (int)$row['id'],
    'slug'          => $row['slug'],
    'title'         => $row['title'],
    'category_slug' => $row['category_slug'],
    'tag_slug'      => $row['tag_slug'],
    'tag_title'     => $row['tag_title'],
    'total_lines'   => (int)$row['total_lines'],
    'audio_url'     => $row['audio_local'] ? api_audio_url($row['audio_local']) : null,
    'source_url'    => $row['url'],
    'fetched_at'    => $row['fetched_at'],
]);
