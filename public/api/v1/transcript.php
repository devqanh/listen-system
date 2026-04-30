<?php
/**
 * GET /api/v1/transcript.php?id=NN
 * hoặc /api/v1/transcript.php?slug=...
 *
 * Trả metadata + đầy đủ transcript_lines (mỗi line có start_sec, duration_sec,
 * speaker, text, words[]) — đủ data để tự xây UI dictation player ở web khác.
 *
 * Tham số:
 *   - id   (hoặc slug): bắt buộc 1 trong 2
 *   - words (default=1): nếu 0, bỏ field words[] để giảm payload
 *
 * Response:
 *   {
 *     "success": true,
 *     "data": {
 *       "lesson": {
 *         "id": 73, "slug": "...", "title": "...",
 *         "category_slug": "ielts", "tag_slug": "...", "tag_title": "...",
 *         "total_lines": 58,
 *         "audio_url": "http://host/audio/ielts/...mp3",
 *         "source_url": "https://engnovate.com/...",
 *         "fetched_at": "..."
 *       },
 *       "lines": [
 *         {
 *           "idx": 0,
 *           "start_sec": 102.17,
 *           "duration_sec": 4.63,
 *           "speaker": "Speaker 1 (1)",
 *           "text": "Good morning. World tours. ...",
 *           "words": ["Good", "morning", ".", "World", "tours", ...]
 *         },
 *         ...
 *       ]
 *     }
 *   }
 */
require __DIR__ . '/_api.php';
api_init('GET');

$pdo         = api_pdo();
$includeWords = (int)($_GET['words'] ?? 1) === 1;

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

$lesson = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lesson) api_error('NOT_FOUND', 'Bài không tồn tại hoặc chưa được crawl', 404);

$tStmt = $pdo->prepare(
    "SELECT idx, start_sec, duration_sec, speaker, text, words_json
       FROM transcript_lines
      WHERE lesson_id = ?
   ORDER BY idx ASC"
);
$tStmt->execute([(int)$lesson['id']]);

$lines = [];
foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $line = [
        'idx'          => (int)$r['idx'],
        'start_sec'    => (float)$r['start_sec'],
        'duration_sec' => $r['duration_sec'] !== null ? (float)$r['duration_sec'] : null,
        'speaker'      => $r['speaker'],
        'text'         => $r['text'],
    ];
    if ($includeWords) {
        $line['words'] = $r['words_json'] ? json_decode($r['words_json'], true) : [];
    }
    $lines[] = $line;
}

api_ok([
    'lesson' => [
        'id'            => (int)$lesson['id'],
        'slug'          => $lesson['slug'],
        'title'         => $lesson['title'],
        'category_slug' => $lesson['category_slug'],
        'tag_slug'      => $lesson['tag_slug'],
        'tag_title'     => $lesson['tag_title'],
        'total_lines'   => (int)$lesson['total_lines'],
        'audio_url'     => $lesson['audio_local'] ? api_audio_url($lesson['audio_local']) : null,
        'source_url'    => $lesson['url'],
        'fetched_at'    => $lesson['fetched_at'],
    ],
    'lines' => $lines,
]);
