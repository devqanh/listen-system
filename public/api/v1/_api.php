<?php
/**
 * API helper dùng chung cho v1 endpoints.
 *
 * Envelope chuẩn:
 *   Thành công: {"success": true, "data": ..., "meta": {...}}
 *   Lỗi:        {"success": false, "error": {"code": "BAD_REQUEST", "message": "..."}}
 *
 * CORS: cho phép tất cả origin vì data public read-only.
 * Authentication: optional. Đặt 1 dòng API key vào file `data/api.key` để bật.
 *   Khi bật, client phải gửi `X-API-Key: <key>` (header) hoặc `?api_key=<key>` (query).
 */

require_once dirname(__DIR__, 3) . '/crawler/db.php';

function api_init(string $method = 'GET'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    header('Cache-Control: public, max-age=60');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        api_error('METHOD_NOT_ALLOWED', "Endpoint chỉ chấp nhận $method", 405);
    }

    api_check_auth();
}

function api_check_auth(): void
{
    $keyFile = dirname(__DIR__, 3) . '/data/api.key';
    if (!is_file($keyFile)) {
        return; // không bật auth
    }
    $expected = trim((string)file_get_contents($keyFile));
    if ($expected === '') {
        return;
    }
    $got = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
    if (!hash_equals($expected, (string)$got)) {
        api_error('UNAUTHORIZED', 'Thiếu hoặc sai X-API-Key header / api_key query', 401);
    }
}

function api_pdo(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $cfg = require dirname(__DIR__, 3) . '/crawler/config.php';
    $pdo = db_open($cfg);
    return $pdo;
}

function api_ok($data, array $meta = []): never
{
    $out = ['success' => true, 'data' => $data];
    if ($meta) $out['meta'] = $meta;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $code, string $message, int $http = 400): never
{
    http_response_code($http);
    echo json_encode([
        'success' => false,
        'error'   => ['code' => $code, 'message' => $message],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_int(string $key, ?int $default = null, int $min = 0, int $max = PHP_INT_MAX): int
{
    $v = $_GET[$key] ?? null;
    if ($v === null || $v === '') {
        if ($default === null) api_error('BAD_REQUEST', "Thiếu tham số `$key`");
        return $default;
    }
    if (!ctype_digit((string)$v)) api_error('BAD_REQUEST', "`$key` phải là số nguyên");
    return max($min, min($max, (int)$v));
}

function api_str(string $key, ?string $default = null, int $maxLen = 200): string
{
    $v = $_GET[$key] ?? null;
    if ($v === null) {
        if ($default === null) api_error('BAD_REQUEST', "Thiếu tham số `$key`");
        return $default;
    }
    $v = (string)$v;
    if (strlen($v) > $maxLen) api_error('BAD_REQUEST', "`$key` quá dài (>$maxLen ký tự)");
    return $v;
}

function api_audio_url(string $relPath): string
{
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Tự suy ra base path của public/ từ vị trí api/v1/.
    $base  = preg_replace('#/api/v1/?$#', '', dirname($_SERVER['SCRIPT_NAME']));
    return $proto . '://' . $host . $base . '/audio/' . $relPath;
}
