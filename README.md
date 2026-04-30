# Listen-English Library

Cào bài luyện nghe (Practice Dictation) của [engnovate.com/dictation-shadowing-exercises](https://engnovate.com/dictation-shadowing-exercises/) về máy local, lưu vào SQLite + file MP3, kèm giao diện PHP để chọn bài và điền từ theo từng câu.

Có **2 phiên bản crawler** dùng chung 1 cơ sở dữ liệu (`data/library.sqlite`) và thư mục mp3 (`data/audio/`):

| Phiên bản | Đường dẫn | Mô hình | Ưu điểm |
|---|---|---|---|
| PHP (đơn luồng) | `crawler/` | Phase 1 list → Phase 2 detail tuần tự | Không cần cài thêm, chạy từ Laragon |
| Python (2 luồng) | `crawler_py/` | Thread T fetch transcript + DB, Thread A tải mp3 song song qua SQLite WAL | Nhanh hơn ~2x, vì khi đang download file lớn, luồng kia tiếp tục fetch HTML |

Hai phiên bản tương thích hoàn toàn: chạy Python phase 1, dừng giữa chừng, mở UI PHP xem bài đã có; rồi tiếp tục bằng PHP `--detail-only` cũng được.

## Kiến trúc

```
listen-english/
├── crawler/                 # CLI scripts cào dữ liệu (PHP, đơn luồng)
│   ├── config.php           # base url, cookie, headers, đường dẫn
│   ├── db.php               # khởi tạo SQLite + helper truy vấn
│   ├── http.php             # cURL có cookie + retry 429
│   ├── parser.php           # bóc <article>, <h3>, <source>, transcript-line
│   ├── crawl_list.php       # phase 1: liệt kê bài theo category, có phân trang
│   ├── crawl_detail.php     # phase 2: tải transcript + mp3 cho bài pending
│   └── run.php              # orchestrator (list → detail)
├── crawler_py/              # CLI scripts (Python, 2 luồng)
│   ├── config.py            # cấu hình + cookie (đồng bộ với PHP)
│   ├── db.py                # sqlite3 + WAL + claim_next thread-safe
│   ├── http.py              # requests session + cache HTML + retry 429
│   ├── parser.py            # BeautifulSoup
│   ├── crawl_list.py        # phase 1
│   ├── run.py               # 2 worker pool: transcript + audio download
│   └── requirements.txt     # requests + beautifulsoup4
├── public/                  # giao diện web (Apache phục vụ trực tiếp)
│   ├── index.php            # danh sách bài, lọc category + tag (sub-category)
│   ├── practice.php         # UI luyện nghe điền từ
│   ├── api/lessons.php      # JSON danh sách
│   ├── api/transcript.php   # JSON transcript của 1 bài
│   ├── assets/              # app.css + practice.js
│   └── audio/<category>/<slug>.mp3   # mp3 tĩnh, link trực tiếp /audio/...
└── data/
    ├── library.sqlite       # toàn bộ thư viện
    └── cache/               # cache HTML thô (debug)
```

## Schema SQLite

- `lessons (id, slug UNIQUE, url, title, category_slug, tag_slug, tag_title, post_id, audio_url, audio_local, total_lines, status, last_error, discovered_at, fetched_at)`
- `transcript_lines (id, lesson_id, idx, start_sec, duration_sec, speaker, text, words_json, UNIQUE(lesson_id, idx))`
- `crawl_log (id, run_at, phase, message)`

`status` của lesson:
- `pending` — mới detect từ trang list, chưa có transcript.
- `working_transcript` — Python: đang được 1 thread xử lý transcript (giả-lock).
- `audio_pending` — Python: đã có transcript + audio_url, chờ tải mp3.
- `working_audio` — Python: đang được thread audio xử lý.
- `fetched` — đã đầy đủ transcript + mp3.
- `failed` — kèm `last_error`, có thể retry với `--reclaim-failed`.

PHP đi thẳng `pending → fetched`. Python qua trạng thái trung gian `audio_pending` để 2 luồng làm song song. Cả hai đều idempotent: chạy lại không insert trùng (UNIQUE slug).

## Cách chạy crawler

### Phiên bản PHP (đơn luồng)

1. Mở `crawler/config.php`, dán cookie từ DevTools Chrome (sau khi đăng nhập Pro) vào `cookie`.
2. Chạy:

```bash
php crawler/run.php                 # cào hết 3 category (list + chi tiết)
php crawler/run.php ielts           # chỉ IELTS
php crawler/run.php ielts 5         # chỉ IELTS, 5 bài chi tiết / lượt (né 429)
php crawler/run.php --list-only     # chỉ phase 1
php crawler/run.php --detail-only   # chỉ phase 2
```

### Phiên bản Python (2 luồng song song)

1. Cài deps:
   ```bash
   pip install -r crawler_py/requirements.txt
   ```
   Gồm `curl_cffi` (impersonate Chrome TLS) và `beautifulsoup4`.

   **Quan trọng — vì sao dùng `curl_cffi` thay cho `requests`**: engnovate fingerprint TLS handshake. Python `requests` thuần (qua OpenSSL) bị nhận diện và trả 429 dù cùng IP+cookie với Chrome. `curl_cffi` (qua libcurl-impersonate) giả TLS/JA4 giống Chrome thật → server xử lý bình thường. Nếu vẫn gặp 429, đổi `IMPERSONATE = "chrome131"` (hoặc bản mới hơn) trong [crawler_py/httpclient.py](crawler_py/httpclient.py).
2. Mở `crawler_py/config.py`, dán cookie tương tự. (Có thể giữ đồng bộ với PHP bằng cách copy chuỗi cookie qua lại.)
3. Chạy crawler. Có 3 cách (chọn cách thuận tiện nhất):

```bash
# Cách A: wrapper bat/sh (chạy được từ bất kỳ thư mục nào)
run_crawler.bat                       # Windows
./run_crawler.sh                      # Linux/macOS/Git Bash
run_crawler.bat ielts 5               # truyền tham số bình thường

# Cách B: python -m (phải đứng ở project root)
cd c:\laragon\www\listen-english
python -m crawler_py.run
python -m crawler_py.run ielts
python -m crawler_py.run ielts 5      # giới hạn 5 bài transcript / lượt
python -m crawler_py.run --list-only
python -m crawler_py.run --detail-only
python -m crawler_py.run --reclaim-failed   # reset failed → pending/audio_pending

# Cách C: chạy trực tiếp file (đứng ở crawler_py/)
cd c:\laragon\www\listen-english\crawler_py
python run.py ielts 5
```

**Lỗi thường gặp**: `ModuleNotFoundError: No module named 'crawler_py'` — bạn đang ở **trong** thư mục `crawler_py/` và chạy `python -m crawler_py.run`. Hoặc dùng wrapper `run_crawler.bat`, hoặc `cd ..` ra project root, hoặc đổi sang `python run.py`.

Cấu hình số worker mỗi luồng trong `crawler_py/config.py`:
- `TRANSCRIPT_WORKERS = 1` (mặc định 1; tăng = nhiều thread cùng fetch HTML, dễ trigger 429)
- `AUDIO_WORKERS = 1` (mặc định 1; tăng = parallel download mp3 từ CDN)

**Rate-limit chia sẻ giữa 2 thread**: `crawler_py/http.py` có `RateLimiter` toàn cục — 2 thread (transcript + audio) cùng request engnovate.com phải xếp hàng theo `min_interval`. Khi gặp 429, `min_interval` tự nhân 1.5x (cooldown). Sau 5 request thành công liên tiếp, giảm dần về `base_interval = max(SLEEP_TRANSCRIPT, SLEEP_AUDIO)`. Backoff 429 lũy tiến: 90s → 135s → 200s … (tối đa 600s). Nếu vẫn 429 nhiều, tăng `SLEEP_TRANSCRIPT/SLEEP_AUDIO` trong config.

**Auto-reclaim**: Mỗi lần khởi động, `run.py` tự đặt lại các lesson kẹt `working_transcript` → `pending`, `working_audio` → `audio_pending` (xảy ra khi lần chạy trước Ctrl+C giữa lúc đang xử lý). Không cần flag thủ công.

Chạy định kỳ (cron / Task Scheduler) đều an toàn: UNIQUE(slug) chống trùng, cache HTML giảm fetch.

### Xử lý rate-limit

engnovate có 429. `http.php` tự sleep `sleep_on_429` (mặc định 90s) rồi retry tới `max_retries` lần. Mỗi request thường có sleep `sleep_between_requests` (3s) để giữ tốc độ thấp. Nếu cần chạy nhanh hơn, chỉnh trong `config.php`.

### Cookie hết hạn

Khi thấy log báo "Không có transcript-line" hàng loạt → cookie `wordpress_logged_in_*` đã hết hạn. Đăng nhập lại trên Chrome, copy cookie mới, paste vào `cookie` trong `config.php`, chạy lại.

## Giao diện luyện nghe

```bash
# Cách 1: dùng Laragon — truy cập http://listen-english.test/public/index.php
# Cách 2: PHP built-in server
php -S 127.0.0.1:8000 -t public
```

- `index.php`: danh sách bài, lọc 2 cấp (category → tag) + tìm theo tiêu đề. Mỗi card có link "nguồn ↗" mở trang gốc engnovate.
- `practice.php?id=N`: UI dictation. File mp3 phục vụ tĩnh tại URL `/audio/{category}/{slug}.mp3` — không stream qua PHP, không cần xử lý Range thủ công, Apache/Laragon tự lo:
  - Audio tự phát đoạn `[start_sec → start+duration]` của câu hiện tại.
  - Người dùng gõ vào textarea → nhấn **Enter** để chấm.
  - So khớp word-level theo LCS, bỏ qua hoa thường + dấu câu, tô xanh từ đúng, vàng từ thiếu, đỏ gạch ngang từ sai.
  - Phím tắt: **Space** play/pause, **Shift+R** nghe lại, **Shift+H** xem đáp án, **Shift+←/→** câu trước/sau.
  - Đổi tốc độ 0.75x – 1.5x bằng dropdown.
  - Click vào câu trong "Hiện toàn bộ transcript" để nhảy tới câu đó.

## Cấu trúc 3 category (đã verify trên HTML thật)

Tất cả 3 category dùng cùng template: `<div class="tag-posts" data-tag-slug="...">` chứa `<h2 class="tag-title">…</h2>` ở trên + nhiều `<article>`. Crawler đã lưu `tag_slug` + `tag_title` để filter cấp 2 trên UI.

| category_slug | Tổng nhóm | Ví dụ tag_title (lưu trong DB) |
|---|---|---|
| `ielts` | 10 | "Cambridge IELTS 19 Academic", "Cambridge IELTS 18 Academic", … |
| `daily-life-stories` | 5 | "Level 1", "Level 2", "Level 3", "Level 4", "Level 5" |
| `oxford-3000` | 12 | "3-Letter Words", "4-Letter Words", …, "14-Letter Words" |

Mỗi category có pagination `.../page/2?category=…` v.v. — crawler tự follow `<a class="next page-numbers">` đến hết.

## Phân tích cấu trúc engnovate (đã verify)

### Trang danh sách
`https://engnovate.com/dictation-shadowing-exercises/?category={ielts|daily-life-stories|oxford-3000}`

```html
<div class="tag-posts" data-tag-slug="cambridge-ielts-19-academic">
  <article id="post-172214">
    <h3 class="post-title">
      <a href="https://engnovate.com/dictation-shadowing-exercises/{slug}/">{Title}</a>
    </h3>
    <a href=".../?mode=shadowing"  class="start-shadowing-button">Practice Shadowing</a>
    <a href=".../{slug}/"          class="start-dictation-button">Practice Dictation</a>
  </article>
  ...
</div>
<a class="next page-numbers" href=".../page/2?category=ielts">Next »</a>
```

Phân trang: `https://engnovate.com/dictation-shadowing-exercises/page/{N}?category={cat}`.

### Trang chi tiết (Practice Dictation)

```html
<h1>Cambridge IELTS 18 Academic Listening Test 1 Part 1</h1>

<audio controls class="dictation-audio">
  <source src= https://engnovate.com/wp-content/uploads/.../audio1.mp3>
</audio>

<div class="transcript-container">
  <div class="transcript-line"
       id="transcript-line-0"
       data-index="0"
       data-start="91.09"
       data-duration="6.54">
    <div class="speaker">Speaker 1 (1)</div>
    <div class="words">
      <span class="word">Excuse</span>
      <span class="word">me</span>
      <span class="word">.</span>
      ...
    </div>
  </div>
  ...
</div>
```

- `data-start` và `data-duration` (giây) → dùng để phát từng câu trong UI.
- `<source src= URL>` không có dấu nháy, có khoảng trắng sau `=` → parser bóc bằng regex thay vì DOM.
- Mỗi `<span class="word">` có thể là 1 từ hoặc 1 dấu câu riêng → khi gộp lại với khoảng trắng giữa các span, ta có câu nguyên bản.

## Cập nhật thư viện định kỳ

Cron Linux (PHP):
```cron
0 3 * * * cd /path/to/listen-english && /usr/bin/php crawler/run.php >> data/cron.log 2>&1
```

Cron Linux (Python — nhanh hơn):
```cron
0 3 * * * cd /path/to/listen-english && /usr/bin/python3 -m crawler_py.run >> data/cron.log 2>&1
```

Windows Task Scheduler: tạo task chạy 1 trong 2:
- `php c:\laragon\www\listen-english\crawler\run.php`
- `python -m crawler_py.run` (working dir = `c:\laragon\www\listen-english`)

Mỗi lần chạy:
- Phase 1 phát hiện bài mới (so với UNIQUE slug đã có) → status `pending`.
- Phase 2 tải transcript + mp3 cho các bài `pending`/`audio_pending`/`failed` (sau khi reclaim).
