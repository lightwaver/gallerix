<?php
declare(strict_types=1);

namespace Gallerix;

/**
 * CORS for the API and media endpoints. Same-origin deployments (frontend + /api on one host) need no
 * CORS at all; other frontend origins must be listed in CORS_ALLOWED_ORIGINS (comma separated).
 * No cookies are used, so credentials are never allowed.
 */
final class Cors
{
    /** Sends CORS headers for allowed origins and terminates OPTIONS preflight requests. */
    public static function apply(string $methods): void
    {
        header('Vary: Origin');
        $origin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
        if ($origin !== '' && in_array($origin, self::allowedOrigins(), true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: ' . $methods);
            header('Access-Control-Max-Age: 600');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    private static function allowedOrigins(): array
    {
        $list = array_map(fn($o) => rtrim(trim($o), '/'), explode(',', (string)getenv('CORS_ALLOWED_ORIGINS')));
        return array_values(array_filter($list, fn($o) => $o !== ''));
    }
}
