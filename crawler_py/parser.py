"""Bóc HTML engnovate — logic giống PHP parser.

LIST: <article id="post-NNN"> trong <div class="tag-posts" data-tag-slug="...">.
DETAIL: <h1>, <source src= URL.mp3>, <div class="transcript-line" data-index data-start data-duration>.
"""
from __future__ import annotations

import re
from urllib.parse import urlparse

from bs4 import BeautifulSoup


def slug_from_url(url: str) -> str | None:
    m = re.search(r"/dictation-shadowing-exercises/([^/?#]+)/?", urlparse(url).path)
    return m.group(1) if m else None


def parse_listing(html: str) -> dict:
    soup = BeautifulSoup(html, "html.parser")
    lessons: list[dict] = []
    for tag_div in soup.select("div.tag-posts"):
        tag_slug = tag_div.get("data-tag-slug")
        tag_title = None
        prev = tag_div.find_previous("h2", class_="tag-title")
        if prev:
            tag_title = prev.get_text(strip=True)

        for art in tag_div.select('article[id^="post-"]'):
            post_id = None
            m = re.search(r"post-(\d+)", art.get("id", ""))
            if m:
                post_id = int(m.group(1))

            title_a = art.select_one("h3.post-title a")
            btn_a   = art.select_one("a.start-dictation-button")
            if not title_a or not btn_a:
                continue
            url   = btn_a.get("href", "").strip()
            title = title_a.get_text(strip=True)
            slug  = slug_from_url(url)
            if not slug:
                continue
            lessons.append({
                "slug": slug,
                "url": url,
                "title": title,
                "post_id": post_id,
                "tag_slug": tag_slug,
                "tag_title": tag_title,
            })

    next_url = None
    next_a = soup.select_one("a.next.page-numbers")
    if next_a:
        next_url = next_a.get("href")

    return {"lessons": lessons, "next_url": next_url}


_AUDIO_RE = re.compile(r'<source\s+src\s*=\s*"?([^"\s>]+\.mp3)[^>]*>', re.IGNORECASE)


def parse_detail(html: str) -> dict:
    soup = BeautifulSoup(html, "html.parser")

    title = None
    h1 = soup.select_one("header.entry-header h1") or soup.find("h1")
    if h1:
        title = h1.get_text(strip=True)

    # <source src= URL> không có dấu nháy → regex an toàn hơn DOM.
    audio_url = None
    m = _AUDIO_RE.search(html)
    if m:
        audio_url = m.group(1).strip()

    lines: list[dict] = []
    for node in soup.select("div.transcript-line"):
        idx_attr = node.get("data-index")
        if idx_attr in (None, ""):
            continue
        speaker_el = node.select_one("div.speaker")
        speaker = speaker_el.get_text(strip=True) if speaker_el else None

        words: list[str] = []
        text_parts: list[str] = []
        words_div = node.select_one("div.words")
        if words_div:
            for child in words_div.children:
                if hasattr(child, "name") and child.name:
                    w = child.get_text(strip=True)
                    if w:
                        words.append(w)
                    text_parts.append(child.get_text())
                else:
                    text_parts.append(str(child))
        text = re.sub(r"\s+", " ", "".join(text_parts)).strip()

        lines.append({
            "idx": int(idx_attr),
            "start_sec": float(node.get("data-start") or 0),
            "duration_sec": float(node.get("data-duration") or 0),
            "speaker": speaker,
            "text": text,
            "words": words,
        })

    lines.sort(key=lambda l: l["idx"])
    return {"title": title, "audio_url": audio_url, "lines": lines}
