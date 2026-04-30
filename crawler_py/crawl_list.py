"""Phase 1: cào danh sách bài (đơn luồng)."""
from __future__ import annotations

# Cho phép chạy trực tiếp `python crawl_list.py` lẫn `python -m crawler_py.crawl_list`.
if __name__ == "__main__" and __package__ in (None, ""):
    import os, sys
    sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
    __package__ = "crawler_py"

from urllib.parse import quote

from . import config, db, httpclient, parser


def run(category_filter: list[str] | None = None) -> int:
    conn = db.open_conn()
    session = httpclient.make_session()

    cats = category_filter or config.CATEGORIES
    new_total = 0
    for cat in cats:
        page = 1
        referer = config.BASE_URL + config.LIST_PATH
        url = f"{referer}?category={quote(cat)}"

        while url:
            db.log(conn, "list", f"Fetch {url}")
            html = httpclient.fetch_html(session, url, referer=referer, use_cache=False)
            parsed = parser.parse_listing(html)
            added = 0
            for l in parsed["lessons"]:
                l["category_slug"] = cat
                if db.upsert_lesson_listing(conn, l):
                    added += 1
                    new_total += 1
            db.log(conn, "list",
                   f"  category={cat} page={page} found={len(parsed['lessons'])} new={added}")

            nxt = parsed["next_url"]
            if not nxt or nxt == url:
                break
            referer, url = url, nxt
            page += 1
            if page > 50:
                db.log(conn, "list", "  WARN: dừng vì >50 trang")
                break
    db.log(conn, "list", f"Tổng số bài mới thêm: {new_total}")
    return new_total


if __name__ == "__main__":
    import sys
    cats = sys.argv[1].split(",") if len(sys.argv) > 1 else None
    run(cats)
