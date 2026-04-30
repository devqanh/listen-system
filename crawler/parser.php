<?php
/**
 * Bóc tách HTML engnovate.
 *
 * Trang LIST: mỗi bài là <article id="post-NNN"> chứa
 *   <h3 class="post-title"><a href="...slug/">Title</a></h3>
 *   <a class="start-dictation-button" href="...slug/">Practice Dictation</a>
 * Bài thuộc nhóm <div class="tag-posts" data-tag-slug="..."> với <h2 class="tag-title">.
 * Pagination: <a class="next page-numbers" href="...page/N?category=cat">.
 *
 * Trang DETAIL:
 *   <h1>Title</h1>
 *   <article id="post-NNN" class="... dictation_test_category-{cat} dictation_test_tag-{tag}">
 *   <source src= URL_MP3>          (lưu ý: không có dấu nháy, có khoảng trắng sau dấu =)
 *   <div class="transcript-line" id="transcript-line-N" data-index="N" data-start="X" data-duration="Y">
 *     <div class="speaker">Speaker 1 (1)</div>
 *     <div class="words"><span class="word">Excuse</span> <span class="word">me</span><span class="word">.</span> ...</div>
 *   </div>
 */

function parse_listing(string $html): array
{
    $dom = parser_load_html($html);
    $xp  = new DOMXPath($dom);

    $lessons = [];
    foreach ($xp->query('//div[contains(@class, "tag-posts")]') as $tagDiv) {
        $tagSlug  = $tagDiv->getAttribute('data-tag-slug') ?: null;
        $tagTitle = null;
        $h2       = $xp->query('preceding-sibling::h2[contains(@class,"tag-title")][1]', $tagDiv);
        if ($h2->length) {
            $tagTitle = trim($h2->item(0)->textContent);
        }

        foreach ($xp->query('.//article[contains(@id, "post-")]', $tagDiv) as $art) {
            $postId = null;
            if (preg_match('/post-(\d+)/', $art->getAttribute('id'), $m)) {
                $postId = (int)$m[1];
            }

            $titleA = $xp->query('.//h3[contains(@class,"post-title")]//a', $art)->item(0);
            $btnA   = $xp->query('.//a[contains(@class,"start-dictation-button")]', $art)->item(0);
            if (!$titleA || !$btnA) {
                continue;
            }
            $url   = $btnA->getAttribute('href');
            $title = trim($titleA->textContent);
            $slug  = parser_slug_from_url($url);
            if (!$slug) {
                continue;
            }
            $lessons[] = [
                'slug'      => $slug,
                'url'       => $url,
                'title'     => $title,
                'post_id'   => $postId,
                'tag_slug'  => $tagSlug,
                'tag_title' => $tagTitle,
            ];
        }
    }

    $next = null;
    foreach ($xp->query('//a[contains(@class,"next") and contains(@class,"page-numbers")]') as $a) {
        $next = $a->getAttribute('href');
        break;
    }

    return ['lessons' => $lessons, 'next_url' => $next];
}

function parse_detail(string $html): array
{
    $dom = parser_load_html($html);
    $xp  = new DOMXPath($dom);

    $title = null;
    $h1    = $xp->query('//header[contains(@class,"entry-header")]//h1')->item(0)
           ?: $xp->query('//h1')->item(0);
    if ($h1) {
        $title = trim($h1->textContent);
    }

    // <source src= URL> đôi khi không có dấu nháy => regex an toàn hơn DOM.
    $audioUrl = null;
    if (preg_match('/<source\s+src\s*=\s*"?([^"\s>]+\.mp3)[^>]*>/i', $html, $m)) {
        $audioUrl = trim($m[1]);
    }

    $lines = [];
    foreach ($xp->query('//div[contains(@class,"transcript-line")]') as $node) {
        $idx = $node->getAttribute('data-index');
        if ($idx === '') {
            continue;
        }
        $speaker = '';
        $sp = $xp->query('.//div[contains(@class,"speaker")]', $node)->item(0);
        if ($sp) {
            $speaker = trim($sp->textContent);
        }
        $words   = [];
        $textBuf = '';
        $wordsDiv = $xp->query('.//div[contains(@class,"words")]', $node)->item(0);
        if ($wordsDiv) {
            foreach ($wordsDiv->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $textBuf .= $child->nodeValue;
                } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                    $w = trim($child->textContent);
                    if ($w !== '') {
                        $words[] = $w;
                    }
                    $textBuf .= $child->textContent;
                }
            }
        }
        $text = preg_replace('/\s+/u', ' ', trim($textBuf));

        $lines[] = [
            'idx'          => (int)$idx,
            'start_sec'    => (float)$node->getAttribute('data-start'),
            'duration_sec' => (float)$node->getAttribute('data-duration'),
            'speaker'      => $speaker !== '' ? $speaker : null,
            'text'         => $text,
            'words'        => $words,
        ];
    }
    usort($lines, static fn($a, $b) => $a['idx'] <=> $b['idx']);

    return [
        'title'     => $title,
        'audio_url' => $audioUrl,
        'lines'     => $lines,
    ];
}

function parser_slug_from_url(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    if (preg_match('~/dictation-shadowing-exercises/([^/?#]+)/?~', $path, $m)) {
        return $m[1];
    }
    return null;
}

function parser_load_html(string $html): DOMDocument
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Ép UTF-8 để DOMDocument không tự đoán.
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return $dom;
}
