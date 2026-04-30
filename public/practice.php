<?php
require __DIR__ . '/_bootstrap.php';

$id  = (int)($_GET['id'] ?? 0);
$pdo = app_pdo();

$stmt = $pdo->prepare("SELECT * FROM lessons WHERE id = ? AND status = 'fetched'");
$stmt->execute([$id]);
$lesson = $stmt->fetch();
if (!$lesson) {
    http_response_code(404);
    echo 'Lesson không tồn tại hoặc chưa được crawl. <a href="index.php">Về danh sách</a>';
    exit;
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dictation · <?= h($lesson['title']) ?></title>
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body class="practice-page">

<nav class="topbar">
  <a href="index.php" class="back">← Thư viện</a>
</nav>

<main class="practice-shell" id="app" data-lesson-id="<?= (int)$lesson['id'] ?>">
  <header class="practice-head">
    <h1><?= h($lesson['title']) ?></h1>
    <p class="practice-sub">
      <em>Practice Mode: Dictation</em>
      · <span class="tag-pill"><?= h($lesson['tag_title'] ?: $lesson['category_slug']) ?></span>
      · <?= (int)$lesson['total_lines'] ?> câu
    </p>
  </header>

  <audio id="audio" controls preload="auto" class="audio-bar" src="audio/<?= h($lesson['audio_local']) ?>"></audio>

  <section class="mode-row">
    <label class="mode-label">Chế độ:
      <select id="dict-mode">
        <option value="word" selected>Word — đoán từng từ</option>
        <option value="whole">Whole — gõ cả câu</option>
      </select>
    </label>
    <label class="mode-label">Tốc độ:
      <select id="speed">
        <option value="0.75">0.75×</option>
        <option value="1" selected>1×</option>
        <option value="1.25">1.25×</option>
        <option value="1.5">1.5×</option>
      </select>
    </label>
  </section>

  <div class="counter-row">
    Câu <span id="cur-idx">0</span> <span class="muted">/</span> <span id="total-idx">0</span>
  </div>

  <section class="dictation-card">
    <div class="speaker-chip" id="speaker-tag"></div>
    <div class="display" id="display"></div>
    <input id="user-input" type="text" placeholder="Đợi nghe xong câu rồi gõ ở đây…" autocomplete="off" spellcheck="false" disabled>
  </section>

  <p class="score-line">
    <span class="score-label">Hoàn thành:</span>
    <strong id="score">0</strong> / <span id="total-idx-2">0</span> câu
  </p>

  <section class="control-row">
    <div class="control-group left">
      <button id="btn-relisten" class="btn dark" title="Nghe lại đoạn audio câu hiện tại (Shift+R)">
        <span class="icon">↻</span><span class="label">Nghe lại</span>
      </button>
      <button id="btn-show" class="btn orange" title="Hiện đáp án từ đang đoán (Shift+H)">
        <span class="icon">👁</span><span class="label">Đáp án</span>
      </button>
    </div>
    <div class="control-group right">
      <button id="btn-check" class="btn purple" title="Kiểm tra (Enter)">
        <span class="icon">✓</span><span class="label">Kiểm tra</span>
      </button>
      <button id="btn-prev" class="btn blue" title="Quay lại câu trước (Shift+←)">
        <span class="icon">←</span><span class="label">Trước</span>
      </button>
      <button id="btn-next" class="btn green" title="Phát câu kế tiếp / bắt đầu (Space)">
        <span class="icon">▶</span><span class="label" id="next-label">Bắt đầu</span>
      </button>
    </div>
  </section>

  <div class="result" id="result"></div>

  <details class="show-transcript">
    <summary>📜 Hiện toàn bộ transcript (gian lận — chỉ xem khi cần)</summary>
    <div id="full-transcript"></div>
  </details>

  <details class="shortcuts">
    <summary>⌨ Phím tắt</summary>
    <ul>
      <li><kbd>Space</kbd> phát câu hiện tại / câu kế tiếp</li>
      <li><kbd>Enter</kbd> kiểm tra từ vừa gõ</li>
      <li><kbd>Shift</kbd> + <kbd>R</kbd> nghe lại câu hiện tại</li>
      <li><kbd>Shift</kbd> + <kbd>H</kbd> hiện đáp án từ đang đoán</li>
      <li><kbd>Shift</kbd> + <kbd>←</kbd> / <kbd>→</kbd> câu trước / sau</li>
    </ul>
  </details>
</main>

<script src="<?= h(asset('assets/practice.js')) ?>"></script>
</body>
</html>
