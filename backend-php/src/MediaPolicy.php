<?php
declare(strict_types=1);

namespace Gallerix;

/**
 * Central rules for gallery/file names and which media types may be stored and served.
 */
class MediaPolicy
{
    public const ALLOWED_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
        'image/heic', 'image/heif', 'image/bmp', 'image/tiff',
        'video/mp4', 'video/x-m4v', 'video/quicktime', 'video/webm',
        'video/3gpp', 'video/x-matroska', 'video/x-msvideo', 'video/mpeg',
        'application/pdf',
    ];

    private const EXTENSION_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif',
        'bmp' => 'image/bmp', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        '3gp' => 'video/3gpp', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo',
        'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg', 'pdf' => 'application/pdf',
    ];

    /**
     * A single path segment that cannot escape its gallery prefix: no slashes, no dot segments,
     * no control characters.
     */
    public static function isSafeSegment(string $s): bool
    {
        if ($s === '' || $s === '.' || $s === '..' || strlen($s) > 255) return false;
        if (str_contains($s, '/') || str_contains($s, '\\')) return false;
        return !preg_match('/[\x00-\x1F\x7F]/', $s);
    }

    /** Stricter rule for newly created gallery names (URL slug). */
    public static function isValidNewGalleryName(string $name): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name);
    }

    /** Turns a client-supplied upload filename into a safe single segment, or null if nothing usable remains. */
    public static function sanitizeFilename(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name, " .");
        if (strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = mb_strcut(pathinfo($name, PATHINFO_FILENAME), 0, 190);
            $name = $ext !== '' ? $base . '.' . $ext : $base;
        }
        return self::isSafeSegment($name) ? $name : null;
    }

    /**
     * Determines the content type from the file contents (fileinfo), falling back to the extension
     * when fileinfo is unavailable. Never trusts the client-supplied type.
     */
    public static function detectType(string $path, string $filename): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $type = $finfo ? finfo_file($finfo, $path) : false;
            if ($finfo) finfo_close($finfo);
            if (is_string($type) && $type !== '' && $type !== 'application/octet-stream') {
                return strtolower($type);
            }
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::EXTENSION_TYPES[$ext] ?? null;
    }

    public static function isAllowedType(?string $type): bool
    {
        return $type !== null && in_array(strtolower($type), self::ALLOWED_TYPES, true);
    }

    /**
     * Emits headers that keep a served blob from being interpreted as active content on our origin.
     * Returns the content type that should be sent (non-allowlisted types become octet-stream).
     */
    public static function sendSafeMediaHeaders(?string $storedType, string $filename): string
    {
        $ct = strtolower(trim(explode(';', (string)$storedType)[0]));
        header('X-Content-Type-Options: nosniff');
        if (!self::isAllowedType($ct)) {
            $ct = 'application/octet-stream';
            header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        }
        // PDFs are shown in an iframe; the browser PDF viewer refuses to load in a sandboxed document.
        if ($ct !== 'application/pdf') {
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        return $ct;
    }
}
