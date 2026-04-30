# Listen-English Library API v1

REST API public read-only để đấu nối thư viện bài luyện nghe vào website / app khác. Trả JSON UTF-8, có CORS mở cho mọi origin.

**Base URL** (ví dụ trên Laragon local):
```
http://listen-english.test/public/api/v1
```

**Base URL** trên VPS / domain thật:
```
https://yourdomain.com/api/v1
```

## Envelope

Tất cả response dùng cùng cấu trúc:

```json
// Thành công
{
  "success": true,
  "data": ...,
  "meta": { "page": 1, "limit": 20, "total": 80, "has_more": true }   // optional
}

// Lỗi
{
  "success": false,
  "error": {
    "code": "BAD_REQUEST",       // BAD_REQUEST | NOT_FOUND | UNAUTHORIZED | METHOD_NOT_ALLOWED
    "message": "Thiếu tham số `category`"
  }
}
```

HTTP status code đi cùng:
- `200 OK` — thành công
- `400 Bad Request` — tham số sai/thiếu
- `401 Unauthorized` — sai/thiếu API key (khi đã bật auth)
- `404 Not Found` — không tìm thấy bài
- `405 Method Not Allowed` — chỉ chấp nhận GET

## Authentication (optional)

Mặc định public. Để bật API key:

1. Tạo file `data/api.key` chứa 1 key (chuỗi bất kỳ, ví dụ: `openssl rand -hex 24`).
2. Client gửi key qua 1 trong 2 cách:
   - Header `X-API-Key: <key>`
   - Query `?api_key=<key>`

Nếu file `data/api.key` không tồn tại hoặc rỗng → API public, không cần key.

## Endpoints

### `GET /` (index)

Tự giới thiệu API: list endpoint + stats. Tốt để client probe.

```bash
curl https://your.com/api/v1/
```

---

### `GET /categories.php`

Danh sách 3 category gốc + đếm bài.

```bash
curl https://your.com/api/v1/categories.php
```

Response:
```json
{
  "success": true,
  "data": [
    {"slug": "daily-life-stories", "fetched": 8,  "total": 40},
    {"slug": "ielts",              "fetched": 12, "total": 80},
    {"slug": "oxford-3000",        "fetched": 5,  "total": 71}
  ]
}
```

---

### `GET /tags.php?category={slug}`

Sub-category trong 1 category. Ví dụ trong `ielts` có "Cambridge IELTS 19 Academic", "Cambridge IELTS 18 Academic"…

| Param | Required | Mô tả |
|---|---|---|
| `category` | ✓ | `ielts` \| `daily-life-stories` \| `oxford-3000` |

```bash
curl "https://your.com/api/v1/tags.php?category=ielts"
```

Response:
```json
{
  "success": true,
  "data": [
    {"slug": "cambridge-ielts-10-academic", "title": "Cambridge IELTS 10 Academic", "fetched": 4, "total": 8},
    {"slug": "cambridge-ielts-19-academic", "title": "Cambridge IELTS 19 Academic", "fetched": 8, "total": 8}
  ],
  "meta": {"category": "ielts"}
}
```

---

### `GET /lessons.php`

List bài (đã fetched) có lọc + phân trang.

| Param | Required | Default | Mô tả |
|---|---|---|---|
| `category` |   | — | filter `category_slug` |
| `tag`      |   | — | filter `tag_slug` |
| `q`        |   | — | tìm trong title (LIKE %q%) |
| `page`     |   | `1` | số trang |
| `limit`    |   | `20` (max 100) | số bài/trang |
| `sort`     |   | `tag_title` | `tag_title` \| `title` \| `id_desc` \| `id_asc` |

```bash
curl "https://your.com/api/v1/lessons.php?category=ielts&tag=cambridge-ielts-19-academic&page=1&limit=10"
```

Response:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "slug": "cambridge-ielts-19-academic-listening-part-1-test-1",
      "title": "Cambridge IELTS 19 Academic Listening Test 1 Part 1",
      "category_slug": "ielts",
      "tag_slug": "cambridge-ielts-19-academic",
      "tag_title": "Cambridge IELTS 19 Academic",
      "total_lines": 43,
      "audio_url": "https://your.com/audio/ielts/cambridge-ielts-19-academic-listening-part-1-test-1.mp3",
      "source_url": "https://engnovate.com/dictation-shadowing-exercises/cambridge-ielts-19-academic-listening-part-1-test-1/",
      "fetched_at": "2026-04-30 01:16:00"
    }
  ],
  "meta": {
    "page": 1, "limit": 10, "total": 8, "has_more": false,
    "filters": {"category": "ielts", "tag": "cambridge-ielts-19-academic"}
  }
}
```

---

### `GET /lesson.php?id={id}` hoặc `?slug={slug}`

Metadata 1 bài (không kèm transcript). Dùng khi cần thông tin nhanh + audio_url.

```bash
curl "https://your.com/api/v1/lesson.php?id=73"
curl "https://your.com/api/v1/lesson.php?slug=cambridge-ielts-10-academic-listening-part-1-test-1"
```

Response:
```json
{
  "success": true,
  "data": {
    "id": 73,
    "slug": "cambridge-ielts-10-academic-listening-part-1-test-1",
    "title": "Cambridge IELTS 10 Academic Listening Test 1 Part 1",
    "category_slug": "ielts",
    "tag_slug": "cambridge-ielts-10-academic",
    "tag_title": "Cambridge IELTS 10 Academic",
    "total_lines": 58,
    "audio_url": "https://your.com/audio/ielts/...mp3",
    "source_url": "https://engnovate.com/...",
    "fetched_at": "2026-04-30 01:16:00"
  }
}
```

---

### `GET /transcript.php?id={id}&words={0|1}`

Transcript đầy đủ + metadata bài. Đây là endpoint chính để build UI dictation ở web khác.

| Param | Required | Default | Mô tả |
|---|---|---|---|
| `id`    | ✓ (hoặc slug) | — | id bài |
| `slug`  | ✓ (hoặc id)   | — | slug bài |
| `words` |   | `1` | `0` để bỏ field `words[]` (giảm payload nếu chỉ cần text) |

```bash
curl "https://your.com/api/v1/transcript.php?id=73"
```

Response:
```json
{
  "success": true,
  "data": {
    "lesson": {
      "id": 73,
      "slug": "...",
      "title": "...",
      "category_slug": "ielts",
      "tag_slug": "...",
      "tag_title": "...",
      "total_lines": 58,
      "audio_url": "https://your.com/audio/ielts/...mp3",
      "source_url": "https://engnovate.com/...",
      "fetched_at": "..."
    },
    "lines": [
      {
        "idx": 0,
        "start_sec": 102.17,
        "duration_sec": 4.63,
        "speaker": "Speaker 1 (1)",
        "text": "Good morning. World tours. My name is Jamie. How can I help you?",
        "words": ["Good", "morning", ".", "World", "tours", ".", "My", "name", "is", "Jamie", ".", "How", "can", "I", "help", "you", "?"]
      }
    ]
  }
}
```

`words[]` giữ cả punctuation thành span riêng — dùng để render mask `[-----](N)` cho dictation. Nếu chỉ cần plain text → dùng `text` và bỏ `words` qua `?words=0`.

---

### Audio file

MP3 phục vụ tĩnh tại đường dẫn trả về trong `audio_url`:
```
GET https://your.com/audio/{category}/{slug}.mp3
```

Server (Nginx/Apache) phải hỗ trợ HTTP `Range` header để player có thể seek. Nginx và Apache mặc định bật. **Tránh dùng `php -S`** — dev server built-in của PHP **không** hỗ trợ Range, audio không seek được.

## Ví dụ tích hợp JS

```js
const API = 'https://your.com/api/v1';

// Lấy danh sách bài IELTS
const r = await fetch(`${API}/lessons.php?category=ielts&limit=10`);
const { success, data, meta } = await r.json();

// Lấy transcript của bài đầu
const t = await fetch(`${API}/transcript.php?id=${data[0].id}`).then(r => r.json());
const audio = new Audio(t.data.lesson.audio_url);
audio.currentTime = t.data.lines[0].start_sec;
audio.play();
```

## Ví dụ tích hợp PHP (gọi API ngược lại từ web khác)

```php
$ctx = stream_context_create(['http' => ['header' => 'X-API-Key: yourkey']]);
$json = file_get_contents('https://your.com/api/v1/lessons.php?category=ielts', false, $ctx);
$res  = json_decode($json, true);
foreach ($res['data'] as $lesson) {
    echo $lesson['title'] . ' — ' . $lesson['audio_url'] . "\n";
}
```

## Lưu ý production

- Nginx/Apache vhost trỏ DocumentRoot vào `public/` để URL đẹp `/api/v1/...` thay vì `/public/api/v1/...`.
- Cache: API set `Cache-Control: public, max-age=60`. Nếu CDN trước, set thêm `s-maxage` tùy ý.
- Rate limit: thêm ở mức Nginx/Cloudflare nếu sợ abuse.
- HTTPS bắt buộc — `audio_url` tự dò scheme từ `$_SERVER['HTTPS']`.
