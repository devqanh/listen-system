"""SQLite layer — schema giống PHP (cùng file library.sqlite).

Mỗi thread mở connection riêng (sqlite3 không thread-safe khi share connection).
Bật WAL để 2 thread ghi song song không block.
"""
from __future__ import annotations

import sqlite3
import threading
from contextlib import contextmanager
from pathlib import Path

from . import config

_init_lock = threading.Lock()
_initialized = False


def _maybe_migrate(conn: sqlite3.Connection) -> None:
    global _initialized
    with _init_lock:
        if _initialized:
            return
        conn.executescript(
            """
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
            CREATE TABLE IF NOT EXISTS crawl_log (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                run_at  TEXT NOT NULL DEFAULT (datetime('now')),
                phase   TEXT NOT NULL,
                message TEXT
            );
            CREATE INDEX IF NOT EXISTS idx_lessons_category ON lessons(category_slug);
            CREATE INDEX IF NOT EXISTS idx_lessons_status   ON lessons(status);
            CREATE INDEX IF NOT EXISTS idx_lines_lesson_idx ON transcript_lines(lesson_id, idx);
            """
        )
        conn.commit()
        _initialized = True


def open_conn() -> sqlite3.Connection:
    """Mở connection mới — gọi 1 lần per thread."""
    Path(config.DATA_DIR).mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(
        str(config.SQLITE_PATH),
        timeout=30.0,           # đợi lock thay vì lỗi ngay
        isolation_level=None,   # autocommit + transaction qua context manager
    )
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode = WAL")
    conn.execute("PRAGMA foreign_keys = ON")
    conn.execute("PRAGMA busy_timeout = 30000")
    _maybe_migrate(conn)
    return conn


_log_lock = threading.Lock()


def log(conn: sqlite3.Connection, phase: str, message: str) -> None:
    with _log_lock:
        conn.execute("INSERT INTO crawl_log(phase, message) VALUES(?, ?)", (phase, message))
        print(f"[{phase}] {message}", flush=True)


def upsert_lesson_listing(conn: sqlite3.Connection, data: dict) -> bool:
    """Trả True nếu thực sự thêm mới (slug chưa có)."""
    cur = conn.execute("SELECT id FROM lessons WHERE slug = ?", (data["slug"],))
    if cur.fetchone():
        return False
    cols = ("slug", "url", "title", "category_slug", "tag_slug", "tag_title", "post_id")
    placeholders = ",".join("?" for _ in cols)
    conn.execute(
        f"INSERT INTO lessons({','.join(cols)}) VALUES({placeholders})",
        tuple(data.get(c) for c in cols),
    )
    return True


def fetch_lessons_by_status(conn: sqlite3.Connection, statuses: list[str], category: str | None = None, limit: int = 0) -> list[sqlite3.Row]:
    qs = ",".join("?" for _ in statuses)
    sql = f"SELECT * FROM lessons WHERE status IN ({qs})"
    args: list = list(statuses)
    if category:
        sql += " AND category_slug = ?"
        args.append(category)
    sql += " ORDER BY id ASC"
    if limit > 0:
        sql += f" LIMIT {int(limit)}"
    return list(conn.execute(sql, args))


def claim_next(conn: sqlite3.Connection, from_status: str, to_status: str, category: str | None = None) -> sqlite3.Row | None:
    """Lấy 1 row từ trạng thái `from_status`, ngay lập tức chuyển sang `to_status` (giả-lock).

    Hai worker khác nhau dùng `claim_next` với cặp status khác nhau nên không tranh nhau.
    Trong cùng 1 worker chạy nhiều thread, busy_timeout + UPDATE atomic đảm bảo không double-claim.
    """
    while True:
        try:
            conn.execute("BEGIN IMMEDIATE")
            sql = "SELECT * FROM lessons WHERE status = ?"
            args: list = [from_status]
            if category:
                sql += " AND category_slug = ?"
                args.append(category)
            sql += " ORDER BY id ASC LIMIT 1"
            row = conn.execute(sql, args).fetchone()
            if not row:
                conn.execute("COMMIT")
                return None
            conn.execute("UPDATE lessons SET status = ? WHERE id = ?", (to_status, row["id"]))
            conn.execute("COMMIT")
            return row
        except sqlite3.OperationalError:
            conn.execute("ROLLBACK")
            continue


def save_transcript(conn: sqlite3.Connection, lesson_id: int, audio_url: str, lines: list[dict]) -> None:
    """Lưu transcript. Sau bước này status chuyển 'audio_pending' chờ luồng tải mp3."""
    import json
    conn.execute("BEGIN IMMEDIATE")
    try:
        conn.execute("DELETE FROM transcript_lines WHERE lesson_id = ?", (lesson_id,))
        conn.executemany(
            """INSERT INTO transcript_lines
               (lesson_id, idx, start_sec, duration_sec, speaker, text, words_json)
               VALUES(?, ?, ?, ?, ?, ?, ?)""",
            [
                (
                    lesson_id, l["idx"], l["start_sec"], l["duration_sec"],
                    l["speaker"], l["text"], json.dumps(l["words"], ensure_ascii=False),
                )
                for l in lines
            ],
        )
        conn.execute(
            """UPDATE lessons SET audio_url = ?, total_lines = ?, status = 'audio_pending',
                                  last_error = NULL
                            WHERE id = ?""",
            (audio_url, len(lines), lesson_id),
        )
        conn.execute("COMMIT")
    except Exception:
        conn.execute("ROLLBACK")
        raise


def mark_fetched(conn: sqlite3.Connection, lesson_id: int, audio_local: str) -> None:
    conn.execute(
        """UPDATE lessons
              SET audio_local = ?, status = 'fetched',
                  last_error = NULL, fetched_at = datetime('now')
            WHERE id = ?""",
        (audio_local, lesson_id),
    )


def mark_failed(conn: sqlite3.Connection, lesson_id: int, error: str) -> None:
    conn.execute(
        "UPDATE lessons SET status = 'failed', last_error = ? WHERE id = ?",
        (error, lesson_id),
    )
