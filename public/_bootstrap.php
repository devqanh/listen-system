<?php
/** Bootstrap dùng chung cho UI/API: load config + mở SQLite. */

require __DIR__ . '/../crawler/db.php';

function app_pdo(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    $cfg = require __DIR__ . '/../crawler/config.php';
    $pdo = db_open($cfg);
    return $pdo;
}

function app_cfg(): array
{
    static $cfg = null;
    if ($cfg) {
        return $cfg;
    }
    $cfg = require __DIR__ . '/../crawler/config.php';
    return $cfg;
}

function app_audio_url(string $relPath): string
{
    return 'audio/' . $relPath;
}

/**
 * Cache-busting cho asset tĩnh: thêm ?v=<filemtime> để browser tự refresh khi file đổi.
 * Tránh tình huống user thấy lỗi vì JS/CSS cũ còn trong cache.
 */
function asset(string $relPath): string
{
    $abs = __DIR__ . '/' . ltrim($relPath, '/');
    $v   = is_file($abs) ? filemtime($abs) : 0;
    return $relPath . '?v=' . $v;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
