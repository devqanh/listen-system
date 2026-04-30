<?php
/**
 * cURL wrapper hỗ trợ cookie + retry khi gặp 429.
 * Cache HTML xuống đĩa để tránh fetch lại trang đã có (debug & tiết kiệm).
 */

function http_fetch(array $cfg, string $url, ?string $referer = null, bool $useCache = true): string
{
    if ($useCache && !empty($cfg['cache_html'])) {
        $cached = http_cache_read($cfg, $url);
        if ($cached !== null) {
            return $cached;
        }
    }

    $headers = $cfg['headers'];
    if ($referer) {
        $headers[] = 'referer: ' . $referer;
    }

    $attempt = 0;
    while (true) {
        $attempt++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIE         => $cfg['cookie'] ?? '',
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false, // môi trường Laragon đôi khi thiếu CA bundle
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            if ($attempt >= ($cfg['max_retries'] ?? 3)) {
                throw new RuntimeException("curl error fetching $url: $err");
            }
            sleep(5);
            continue;
        }
        if ($code === 429) {
            if ($attempt >= ($cfg['max_retries'] ?? 3)) {
                throw new RuntimeException("Rate-limited 429 sau $attempt lần thử: $url");
            }
            $sleep = (int)($cfg['sleep_on_429'] ?? 90);
            fwrite(STDOUT, "[429] sleep {$sleep}s rồi thử lại $url\n");
            sleep($sleep);
            continue;
        }
        if ($code >= 200 && $code < 300) {
            if (!empty($cfg['cache_html'])) {
                http_cache_write($cfg, $url, $body);
            }
            $delay = (int)($cfg['sleep_between_requests'] ?? 0);
            if ($delay > 0) {
                sleep($delay);
            }
            return $body;
        }
        throw new RuntimeException("HTTP $code khi fetch $url");
    }
}

function http_download(array $cfg, string $url, string $destPath, ?string $referer = null): void
{
    if (is_file($destPath) && filesize($destPath) > 0) {
        return; // đã tải xong
    }
    if (!is_dir(dirname($destPath))) {
        mkdir(dirname($destPath), 0777, true);
    }
    $headers = $cfg['headers'];
    if ($referer) {
        $headers[] = 'referer: ' . $referer;
    }

    $maxAttempts = $cfg['max_retries'] ?? 3;
    $attempt = 0;
    while (true) {
        $attempt++;
        $tmp = $destPath . '.part';
        $fh  = fopen($tmp, 'wb');
        if (!$fh) {
            throw new RuntimeException("Không mở được $tmp để ghi");
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_COOKIE         => $cfg['cookie'] ?? '',
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $ok   = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok && $code >= 200 && $code < 300) {
            rename($tmp, $destPath);
            $delay = (int)($cfg['sleep_between_requests'] ?? 0);
            if ($delay > 0) {
                sleep($delay);
            }
            return;
        }
        @unlink($tmp);

        if ($code === 429 && $attempt < $maxAttempts) {
            $sleep = (int)($cfg['sleep_on_429'] ?? 90);
            fwrite(STDOUT, "[429] download sleep {$sleep}s rồi thử lại $url\n");
            sleep($sleep);
            continue;
        }
        if (!$ok && $attempt < $maxAttempts) {
            sleep(5);
            continue;
        }
        throw new RuntimeException("Tải $url thất bại (HTTP $code): $err");
    }
}

function http_cache_path(array $cfg, string $url): string
{
    return rtrim($cfg['cache_dir'], '/\\') . '/' . sha1($url) . '.html';
}

function http_cache_read(array $cfg, string $url): ?string
{
    $p = http_cache_path($cfg, $url);
    if (is_file($p)) {
        $body = file_get_contents($p);
        return $body !== false ? $body : null;
    }
    return null;
}

function http_cache_write(array $cfg, string $url, string $body): void
{
    if (!is_dir($cfg['cache_dir'])) {
        mkdir($cfg['cache_dir'], 0777, true);
    }
    file_put_contents(http_cache_path($cfg, $url), $body);
}
