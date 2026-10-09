<?php
declare(strict_types=1);

namespace Gallerix;

/**
 * Builds and verifies short-lived signed media URLs, so no session token ever appears in a URL.
 * A signature grants read access to exactly one file of one gallery until it expires.
 */
class MediaSigner
{
    private string $key;
    private int $ttl;

    public function __construct()
    {
        // Derived key: a leaked media signature can never be used to forge JWTs and vice versa
        $this->key = hash_hmac('sha256', 'gallerix-media-url', Auth::secret(), true);
        $this->ttl = max(300, (int)(getenv('MEDIA_URL_TTL') ?: '7200'));
    }

    /**
     * Returns "/image.php?..." or "/thumb.php?..." with signature, prefixed by PUBLIC_BASE_URL.
     * Expiry is rounded up to the hour so URLs stay stable (and browser-cacheable) for a while.
     */
    public function url(string $script, string $gallery, string $file, array $extra = []): string
    {
        $exp = (int)(ceil((time() + $this->ttl) / 3600) * 3600);
        $query = array_merge(['g' => $gallery, 'f' => $file], $extra, [
            'e' => $exp,
            'sig' => $this->sign($gallery, $file, $exp),
        ]);
        $publicBase = rtrim((string)(getenv('PUBLIC_BASE_URL') ?: ''), '/');
        return $publicBase . '/' . $script . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function verify(string $gallery, string $file, string $exp, string $sig): bool
    {
        if ($sig === '' || !ctype_digit($exp) || (int)$exp < time()) return false;
        return hash_equals($this->sign($gallery, $file, (int)$exp), $sig);
    }

    private function sign(string $gallery, string $file, int $exp): string
    {
        $mac = hash_hmac('sha256', $gallery . "\n" . $file . "\n" . $exp, $this->key, true);
        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }
}
