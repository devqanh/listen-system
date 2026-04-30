"""Cấu hình crawler Python — share data/library.sqlite + data/audio với phiên bản PHP."""
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DATA_DIR     = PROJECT_ROOT / "data"
# Audio đặt trong public/ để Apache phục vụ trực tiếp (không cần PHP stream).
AUDIO_DIR    = PROJECT_ROOT / "public" / "audio"
CACHE_DIR    = DATA_DIR / "cache"
SQLITE_PATH  = DATA_DIR / "library.sqlite"

BASE_URL   = "https://engnovate.com"
LIST_PATH  = "/dictation-shadowing-exercises/"
CATEGORIES = ["ielts", "daily-life-stories", "oxford-3000"]

# Khi True: cache HTML xuống đĩa để tránh fetch lại trong các lần chạy sau.
CACHE_HTML = True

# RateLimiter dùng `max(SLEEP_TRANSCRIPT, SLEEP_AUDIO)` làm khoảng cách tối thiểu giữa
# 2 request bất kỳ (chia sẻ giữa 2 thread). Tăng nếu engnovate trả 429 nhiều.
SLEEP_TRANSCRIPT = 5   # luồng fetch transcript
SLEEP_AUDIO      = 4   # luồng tải mp3
SLEEP_LIST       = 5
SLEEP_ON_429     = 90  # base — backoff lũy tiến 1.5x mỗi lần retry, tối đa 600s
MAX_RETRIES      = 3

# Số worker thread — mặc định 1 cho mỗi pipeline để tránh trigger 429.
TRANSCRIPT_WORKERS = 1
AUDIO_WORKERS      = 1

HEADERS = {
    "accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7",
    "accept-language": "en-US,en;q=0.9,vi;q=0.8",
    "priority": "u=0, i",
    "sec-ch-ua": '"Chromium";v="146", "Not-A.Brand";v="24", "Google Chrome";v="146"',
    "sec-ch-ua-mobile": "?0",
    "sec-ch-ua-platform": '"Windows"',
    "sec-fetch-dest": "document",
    "sec-fetch-mode": "navigate",
    "sec-fetch-site": "same-origin",
    "sec-fetch-user": "?1",
    "upgrade-insecure-requests": "1",
    "user-agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36",
}

# Cookie từ DevTools Chrome sau khi đăng nhập tài khoản Pro.
COOKIE = "_ga=GA1.1.1323277857.1777483882; saved_premium_activation_code=TTZKY0JX067H; wordpress_logged_in_942c4a947e373e5d4114cc2f266a7126=dewavn22%40gmail.com%7C1809020803%7CpE38CVVJsSPPO4sk2103roeplxUZksknYRicRzraroz%7C848ee57e57fe2fafcaa8747ef1379c24831c39c11a26a5dce224f78c541f78de; _ga_6ET5P62S5Y=GS2.1.s1777483882$o1$g1$t1777484990$j42$l0$h0"
