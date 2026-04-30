"""HTTP client dùng `curl_cffi` (impersonate Chrome TLS) + cookie + retry 429 + cache HTML.

Tại sao không phải `requests` thuần: engnovate fingerprint TLS handshake — `requests`
(OpenSSL/urllib3) trả 429 ngay cả khi PowerShell/curl.exe trả 200 trên cùng IP+cookie.
`curl_cffi.requests` impersonate Chrome JA3/JA4 nên server coi như browser thật.

Kèm `RateLimiter` toàn cục: 2 thread (transcript + audio) cùng request engnovate.com
phải xếp hàng theo min_interval. Khi gặp 429, tạm thời tăng min_interval (backoff).
"""
from __future__ import annotations

import hashlib
import threading
import time
from pathlib import Path

from curl_cffi import requests

from . import config

# Phiên bản Chrome dùng để impersonate. Có thể đổi qua "chrome131", "chrome124", "safari17_2", v.v.
IMPERSONATE = "chrome"


class RateLimitBlockedError(RuntimeError):
    """Engnovate trả 429 nhiều lần liên tiếp — IP nhiều khả năng đang bị block dài hạn."""


class RateLimiter:
    """Throttle request toàn cục + circuit breaker.

    - 2 thread (transcript + audio) đều xếp hàng qua `wait()` theo `min_interval`.
    - 429 → nhân `min_interval` 1.5x (cap 30s) + tăng `consecutive_throttles`.
    - Thành công → reset `consecutive_throttles`, từ từ giảm `min_interval` về `base`.
    - Circuit breaker: nếu `consecutive_throttles` ≥ `BREAKER_THRESHOLD` (5) → `tripped()` True
      → caller dừng sớm thay vì sleep mãi.
    """
    BREAKER_THRESHOLD = 5

    def __init__(self, base_interval: float) -> None:
        self.base = base_interval
        self.cur  = base_interval
        self.last = 0.0
        self.success_streak = 0
        self.consecutive_throttles = 0
        self.lock = threading.Lock()

    def wait(self) -> None:
        with self.lock:
            now = time.monotonic()
            wait_for = self.last + self.cur - now
            if wait_for > 0:
                time.sleep(wait_for)
            self.last = time.monotonic()

    def on_success(self) -> None:
        with self.lock:
            self.success_streak += 1
            self.consecutive_throttles = 0
            if self.success_streak >= 5 and self.cur > self.base:
                self.cur = max(self.base, self.cur * 0.7)
                self.success_streak = 0

    def on_throttle(self) -> None:
        with self.lock:
            self.success_streak = 0
            self.consecutive_throttles += 1
            self.cur = min(30.0, max(self.cur * 1.5, self.base * 2))

    def tripped(self) -> bool:
        with self.lock:
            return self.consecutive_throttles >= self.BREAKER_THRESHOLD


# Một limiter dùng chung cho toàn bộ phiên (mọi thread đều xếp hàng qua đây).
_limiter = RateLimiter(base_interval=max(config.SLEEP_TRANSCRIPT, config.SLEEP_AUDIO))


def make_session() -> requests.Session:
    s = requests.Session(impersonate=IMPERSONATE)
    # curl_cffi đã tự set bộ header Chrome chuẩn (sec-ch-ua, accept-encoding, …);
    # chúng ta chỉ cần ghi đè những header chuyên biệt + cookie.
    s.headers.update({
        "accept-language": "en-US,en;q=0.9,vi;q=0.8",
        "cookie": config.COOKIE,
    })
    return s


def _cache_path(url: str) -> Path:
    return config.CACHE_DIR / (hashlib.sha1(url.encode()).hexdigest() + ".html")


def _sleep_429(attempt: int) -> None:
    """Backoff lũy tiến: lần 1 nhỏ, lần sau lớn dần."""
    base = config.SLEEP_ON_429
    delay = min(int(base * (1.5 ** (attempt - 1))), 600)
    print(f"[429] backoff sleep {delay}s (attempt={attempt})", flush=True)
    time.sleep(delay)


def fetch_html(session: requests.Session, url: str, *, referer: str | None = None,
               use_cache: bool = True) -> str:
    if use_cache and config.CACHE_HTML:
        p = _cache_path(url)
        if p.is_file():
            return p.read_text(encoding="utf-8", errors="replace")

    headers = {"referer": referer} if referer else {}

    last_err: Exception | None = None
    for attempt in range(1, config.MAX_RETRIES + 1):
        _limiter.wait()
        try:
            r = session.get(url, headers=headers, timeout=60)
        except Exception as e:  # curl_cffi raise CurlError + variants
            last_err = e
            time.sleep(5)
            continue
        if r.status_code == 429:
            _limiter.on_throttle()
            if _limiter.tripped():
                raise RateLimitBlockedError(
                    f"engnovate trả 429 liên tục {_limiter.BREAKER_THRESHOLD} lần — "
                    "IP của bạn nhiều khả năng đang bị block. Hãy đợi 5–15 phút rồi chạy lại "
                    "(hoặc đổi mạng / dùng 4G mobile hotspot)."
                )
            if attempt >= config.MAX_RETRIES:
                raise RuntimeError(f"Rate-limited 429 sau {attempt} lần thử: {url}")
            _sleep_429(attempt)
            continue
        if 200 <= r.status_code < 300:
            _limiter.on_success()
            body = r.text
            if config.CACHE_HTML:
                config.CACHE_DIR.mkdir(parents=True, exist_ok=True)
                _cache_path(url).write_text(body, encoding="utf-8")
            return body
        raise RuntimeError(f"HTTP {r.status_code} khi fetch {url}")
    raise RuntimeError(f"Không fetch được {url}: {last_err}")


def download_file(session: requests.Session, url: str, dest: Path, *,
                  referer: str | None = None) -> None:
    if dest.is_file() and dest.stat().st_size > 0:
        return
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".part")
    headers = {"referer": referer} if referer else {}

    for attempt in range(1, config.MAX_RETRIES + 1):
        _limiter.wait()
        try:
            with session.stream("GET", url, headers=headers, timeout=300) as r:
                if r.status_code == 429:
                    _limiter.on_throttle()
                    if _limiter.tripped():
                        raise RateLimitBlockedError(
                            f"engnovate trả 429 liên tục {_limiter.BREAKER_THRESHOLD} lần "
                            "khi tải mp3 — đợi 5–15 phút rồi chạy lại."
                        )
                    if attempt >= config.MAX_RETRIES:
                        raise RuntimeError(f"Rate-limited 429 download: {url}")
                    _sleep_429(attempt)
                    continue
                if not (200 <= r.status_code < 300):
                    raise RuntimeError(f"Download HTTP {r.status_code}: {url}")
                with tmp.open("wb") as f:
                    for chunk in r.iter_content(chunk_size=64 * 1024):
                        if chunk:
                            f.write(chunk)
            tmp.rename(dest)
            _limiter.on_success()
            return
        except RateLimitBlockedError:
            tmp.unlink(missing_ok=True)
            raise
        except Exception as e:
            tmp.unlink(missing_ok=True)
            if attempt >= config.MAX_RETRIES:
                raise RuntimeError(f"Download lỗi {url}: {e}")
            time.sleep(5)
    raise RuntimeError(f"Không tải được {url}")
