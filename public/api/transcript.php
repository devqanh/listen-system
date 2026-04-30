<?php
/** API JSON: trả transcript + metadata 1 lesson để UI dictation dùng. */

require __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = app_pdo();
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'missing id']);
    exit;
}

$lstmt = $pdo->prepare('SELECT * FROM lessons WHERE id = ?');
$lstmt->execute([$id]);
$lesson = $lstmt->fetch();
if (!$lesson) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

$tstmt = $pdo->prepare(
    'SELECT idx, start_sec, duration_sec, speaker, text, words_json
       FROM transcript_lines
      WHERE lesson_id = ?
   ORDER BY idx ASC'
);
$tstmt->execute([$id]);
$lines = [];
foreach ($tstmt->fetchAll() as $r) {
    $r['words'] = $r['words_json'] ? json_decode($r['words_json'], true) : [];
    unset($r['words_json']);
    $lines[] = $r;
}

echo json_encode([
    'lesson' => [
        'id'            => (int)$lesson['id'],
        'slug'          => $lesson['slug'],
        'title'         => $lesson['title'],
        'category_slug' => $lesson['category_slug'],
        'tag_title'     => $lesson['tag_title'],
        'audio_url'     => 'audio/' . $lesson['audio_local'],
        'total_lines'   => (int)$lesson['total_lines'],
    ],
    'lines' => $lines,
], JSON_UNESCAPED_UNICODE);
