"""Orchestrator chạy 2 worker pool song song:

- Transcript worker: SELECT status='pending' → fetch detail HTML → parse → save transcript →
  status='audio_pending' (audio_url đã biết).
- Audio worker: SELECT status='audio_pending' → download mp3 → status='fetched'.

Cả 2 thread cùng đọc/ghi data/library.sqlite (WAL mode + busy_timeout). Bài 'failed' sẽ được
re-claim lại trong lần chạy sau (--reclaim-failed).

Cách dùng:
  python -m crawler_py.run                      # cào hết 3 category
  python -m crawler_py.run ielts                # chỉ ielts
  python -m crawler_py.run ielts 5              # ielts, transcript phase tối đa 5 bài / lượt
  python -m crawler_py.run --list-only
  python -m crawler_py.run --detail-only
  python -m crawler_py.run --reclaim-failed     # đặt lại failed → pending/audio_pending để retry
"""
from __future__ import annotations

# Cho phép chạy trực tiếp `python run.py` lẫn `python -m crawler_py.run`.
if __name__ == "__main__" and __package__ in (None, ""):
    import os, sys as _sys
    _sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    __package__ = "crawler_py"

import sys
import threading
import time
from pathlib import Path
from urllib.parse import quote

# Windows console mặc định cp1252 — ép UTF-8 để in tiếng Việt + ký tự đặc biệt.
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

from . import config, crawl_list, db, httpclient, parser


class Counter:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.values: dict[str, int] = {}

    def inc(self, key: str) -> int:
        with self.lock:
            self.values[key] = self.values.get(key, 0) + 1
            return self.values[key]

    def get(self, key: str) -> int:
        with self.lock:
            return self.values.get(key, 0)


def transcript_worker(stop: threading.Event, category: str | None,
                      counters: Counter, limit: int) -> None:
    conn = db.open_conn()
    session = httpclient.make_session()
    while not stop.is_set():
        if limit > 0 and counters.get("transcript_claimed") >= limit:
            break
        row = db.claim_next(conn, from_status="pending", to_status="working_transcript",
                            category=category)
        if row is None:
            break
        counters.inc("transcript_claimed")
        try:
            referer = (config.BASE_URL + config.LIST_PATH +
                       "?category=" + quote(row["category_slug"]))
            db.log(conn, "transcript", f"[{row['id']}] {row['slug']}")
            html = httpclient.fetch_html(session, row["url"], referer=referer)
            detail = parser.parse_detail(html)
            if not detail["lines"]:
                raise RuntimeError("không có transcript-line (có thể bài Pro / cookie hết hạn)")
            if not detail["audio_url"]:
                raise RuntimeError("không có <source> mp3")
            db.save_transcript(conn, int(row["id"]), detail["audio_url"], detail["lines"])
            counters.inc("transcript_ok")
            db.log(conn, "transcript", f"  -> OK lines={len(detail['lines'])}")
        except httpclient.RateLimitBlockedError as e:
            # Trả lesson về pending để lần chạy sau xử lý + dừng cả 2 worker.
            conn.execute("UPDATE lessons SET status='pending' WHERE id = ?", (row["id"],))
            db.log(conn, "transcript", f"  -> BLOCKED: {e}")
            stop.set()
            return
        except Exception as e:
            db.mark_failed(conn, int(row["id"]), str(e))
            counters.inc("transcript_fail")
            db.log(conn, "transcript", f"  -> FAIL: {e}")


def audio_worker(stop: threading.Event, category: str | None,
                 counters: Counter, transcript_done: threading.Event,
                 max_idle_polls: int = 6) -> None:
    """Tải mp3 cho lessons audio_pending. Poll thêm vài lần khi queue trống
    để chờ transcript_worker bơm việc; thoát khi transcript_done & queue rỗng."""
    conn = db.open_conn()
    session = httpclient.make_session()
    idle = 0
    while not stop.is_set():
        row = db.claim_next(conn, from_status="audio_pending", to_status="working_audio",
                            category=category)
        if row is None:
            if transcript_done.is_set():
                idle += 1
                if idle >= max_idle_polls:
                    break
            time.sleep(2)
            continue
        idle = 0
        try:
            audio_url = row["audio_url"]
            if not audio_url:
                raise RuntimeError("audio_url trống")
            local_rel = f"{row['category_slug']}/{row['slug']}.mp3"
            local_abs = Path(config.AUDIO_DIR) / local_rel
            db.log(conn, "audio", f"[{row['id']}] {row['slug']} <- {audio_url}")
            httpclient.download_file(session, audio_url, local_abs, referer=row["url"])
            db.mark_fetched(conn, int(row["id"]), local_rel)
            counters.inc("audio_ok")
            db.log(conn, "audio", f"  -> OK {local_rel}")
        except httpclient.RateLimitBlockedError as e:
            conn.execute("UPDATE lessons SET status='audio_pending' WHERE id = ?", (row["id"],))
            db.log(conn, "audio", f"  -> BLOCKED: {e}")
            stop.set()
            return
        except Exception as e:
            db.mark_failed(conn, int(row["id"]), str(e))
            counters.inc("audio_fail")
            db.log(conn, "audio", f"  -> FAIL: {e}")


def run_detail_phase(category: str | None, limit: int = 0) -> dict[str, int]:
    stop = threading.Event()
    transcript_done = threading.Event()
    counters = Counter()

    t_threads: list[threading.Thread] = []
    a_threads: list[threading.Thread] = []
    for i in range(config.TRANSCRIPT_WORKERS):
        t = threading.Thread(target=transcript_worker,
                             args=(stop, category, counters, limit),
                             name=f"T-trans-{i}", daemon=True)
        t.start(); t_threads.append(t)
    for i in range(config.AUDIO_WORKERS):
        t = threading.Thread(target=audio_worker,
                             args=(stop, category, counters, transcript_done),
                             name=f"T-audio-{i}", daemon=True)
        t.start(); a_threads.append(t)

    try:
        for t in t_threads:
            t.join()
        transcript_done.set()
        for t in a_threads:
            t.join()
    except KeyboardInterrupt:
        print("Đang dừng...", flush=True)
        stop.set()
        transcript_done.set()
        for t in t_threads + a_threads:
            t.join(timeout=10)

    return dict(counters.values)


def main(argv: list[str]) -> None:
    args = argv[1:]
    list_only   = "--list-only"   in args
    detail_only = "--detail-only" in args
    reclaim     = "--reclaim-failed" in args
    args = [a for a in args if not a.startswith("--")]

    category = args[0] if args else None
    limit    = int(args[1]) if len(args) > 1 else 0

    # Luôn phục hồi các lesson kẹt working_* (do lần chạy trước bị Ctrl+C hoặc crash).
    # Tại 1 thời điểm chỉ có 1 process crawler, nên working_* không bao giờ "đang chạy thật".
    conn = db.open_conn()
    rec_t = conn.execute("UPDATE lessons SET status='pending' "
                         "WHERE status='working_transcript'").rowcount
    rec_a = conn.execute("UPDATE lessons SET status='audio_pending' "
                         "WHERE status='working_audio'").rowcount
    if rec_t or rec_a:
        print(f"[startup] reclaim {rec_t} working_transcript, {rec_a} working_audio", flush=True)

    if reclaim:
        conn.execute("UPDATE lessons SET status='audio_pending' "
                     "WHERE status='failed' AND audio_url IS NOT NULL")
        rec_f = conn.execute("UPDATE lessons SET status='pending' "
                             "WHERE status='failed'").rowcount
        print(f"[startup] reclaim {rec_f} failed lessons", flush=True)

    blocked = False
    try:
        if not detail_only:
            crawl_list.run([category] if category else None)
    except httpclient.RateLimitBlockedError as e:
        print(f"\n[BLOCKED] {e}\n", flush=True)
        blocked = True

    counters: dict[str, int] = {}
    if not list_only and not blocked:
        counters = run_detail_phase(category, limit)

    conn = db.open_conn()
    print("\n=== Tổng kết library ===")
    rows = conn.execute(
        "SELECT category_slug, status, COUNT(*) n FROM lessons "
        "GROUP BY category_slug, status ORDER BY category_slug, status"
    ).fetchall()
    for r in rows:
        print(f"{r['category_slug']:<22} {r['status']:<18} {r['n']:>5}")
    if counters:
        print("\nLượt chạy này:", counters)


if __name__ == "__main__":
    main(sys.argv)
