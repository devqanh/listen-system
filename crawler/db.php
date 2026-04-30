<?php
/**
 * Khởi tạo SQLite + helper truy vấn.
 * Schema được giữ idempotent (CREATE IF NOT EXISTS) để chạy lại an toàn.
 */

function db_open(array $cfg): PDO
{
    if (!is_dir($cfg['data_dir'])) {
        mkdir($cfg['data_dir'], 0777, true);
    }
    $pdo = new PDO('sqlite:' . $cfg['sqlite_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS lessons (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    slug            TEXT NOT NULL UNIQUE,
    url             TEXT NOT NULL,
    title           TEXT NOT NULL,
    category_slug   TEXT NOT NULL,
    tag_slug        TEXT,
    tag_title       TEXT,
    post_id         INTEGER,
    audio_url       TEXT,
    audio_local     TEXT,
    total_lines     INTEGER NOT NULL DEFAULT 0,
    status          TEXT NOT NULL DEFAULT 'pending',
    last_error      TEXT,
    discovered_at   TEXT NOT NULL DEFAULT (datetime('now')),
    fetched_at      TEXT
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS transcript_lines (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    lesson_id       INTEGER NOT NULL,
    idx             INTEGER NOT NULL,
    start_sec       REAL NOT NULL,
    duration_sec    REAL,
    speaker         TEXT,
    text            TEXT NOT NULL,
    words_json      TEXT,
    UNIQUE(lesson_id, idx),
    FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS crawl_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    run_at          TEXT NOT NULL DEFAULT (datetime('now')),
    phase           TEXT NOT NULL,
    message         TEXT
);
SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lessons_category ON lessons(category_slug)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lessons_status   ON lessons(status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lines_lesson_idx ON transcript_lines(lesson_id, idx)');
}

function db_log(PDO $pdo, string $phase, string $message): void
{
    $stmt = $pdo->prepare('INSERT INTO crawl_log(phase, message) VALUES(?, ?)');
    $stmt->execute([$phase, $message]);
    fwrite(STDOUT, "[$phase] $message\n");
}

function db_upsert_lesson_listing(PDO $pdo, array $data): bool
{
    $stmt = $pdo->prepare('SELECT id FROM lessons WHERE slug = ?');
    $stmt->execute([$data['slug']]);
    if ($stmt->fetchColumn()) {
        return false;
    }
    $cols = ['slug', 'url', 'title', 'category_slug', 'tag_slug', 'tag_title', 'post_id'];
    $place = implode(',', array_fill(0, count($cols), '?'));
    $insert = $pdo->prepare('INSERT INTO lessons(' . implode(',', $cols) . ') VALUES(' . $place . ')');
    $insert->execute(array_map(static fn($k) => $data[$k] ?? null, $cols));
    return true;
}

function db_pending_lessons(PDO $pdo, ?string $category = null, int $limit = 0): array
{
    $sql  = "SELECT * FROM lessons WHERE status IN ('pending','failed')";
    $args = [];
    if ($category) {
        $sql   .= ' AND category_slug = ?';
        $args[] = $category;
    }
    $sql .= ' ORDER BY id ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function db_save_lesson_detail(PDO $pdo, int $lessonId, array $detail): void
{
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            'UPDATE lessons SET audio_url = ?, audio_local = ?, total_lines = ?,
                status = ?, last_error = NULL, fetched_at = datetime(\'now\')
             WHERE id = ?'
        );
        $upd->execute([
            $detail['audio_url'],
            $detail['audio_local'],
            count($detail['lines']),
            'fetched',
            $lessonId,
        ]);

        $del = $pdo->prepare('DELETE FROM transcript_lines WHERE lesson_id = ?');
        $del->execute([$lessonId]);

        $ins = $pdo->prepare(
            'INSERT INTO transcript_lines(lesson_id, idx, start_sec, duration_sec, speaker, text, words_json)
             VALUES(?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($detail['lines'] as $line) {
            $ins->execute([
                $lessonId,
                $line['idx'],
                $line['start_sec'],
                $line['duration_sec'],
                $line['speaker'],
                $line['text'],
                json_encode($line['words'], JSON_UNESCAPED_UNICODE),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function db_mark_failed(PDO $pdo, int $lessonId, string $error): void
{
    $stmt = $pdo->prepare('UPDATE lessons SET status = ?, last_error = ? WHERE id = ?');
    $stmt->execute(['failed', $error, $lessonId]);
}
