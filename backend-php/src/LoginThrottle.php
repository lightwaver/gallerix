<?php
declare(strict_types=1);

namespace Gallerix;

/**
 * Limits failed login attempts per client IP and per IP+username (fixed window).
 * State lives in small files in the temp directory, which is sufficient for a single server.
 * Per-username limits are tied to the IP so an attacker cannot lock out a user from everywhere.
 */
class LoginThrottle
{
    private string $dir;
    private int $window;
    private int $maxPerAccount;
    private int $maxPerIp;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (sys_get_temp_dir() . '/gallerix-login-throttle');
        $this->window = max(60, (int)(getenv('LOGIN_THROTTLE_WINDOW') ?: '900'));
        $this->maxPerAccount = max(1, (int)(getenv('LOGIN_MAX_ATTEMPTS') ?: '5'));
        $this->maxPerIp = max($this->maxPerAccount, (int)(getenv('LOGIN_MAX_ATTEMPTS_PER_IP') ?: '30'));
    }

    /** Seconds until the next attempt is allowed, 0 if allowed now. */
    public function retryAfter(string $ip, string $username): int
    {
        $wait = 0;
        foreach ($this->keys($ip, $username) as $key => $max) {
            $state = $this->read($key);
            if ($state['count'] >= $max) {
                $wait = max($wait, $state['start'] + $this->window - time());
            }
        }
        return max(0, $wait);
    }

    public function recordFailure(string $ip, string $username): void
    {
        foreach (array_keys($this->keys($ip, $username)) as $key) {
            $this->update($key, function (array $s) { $s['count']++; return $s; });
        }
    }

    /** Successful login: forget failures for this IP+username (the IP-wide counter keeps running). */
    public function reset(string $ip, string $username): void
    {
        @unlink($this->path($this->accountKey($ip, $username)));
    }

    /** @return array<string,int> key => max attempts */
    private function keys(string $ip, string $username): array
    {
        return [
            'ip:' . $ip => $this->maxPerIp,
            $this->accountKey($ip, $username) => $this->maxPerAccount,
        ];
    }

    private function accountKey(string $ip, string $username): string
    {
        return 'acct:' . $ip . '|' . strtolower(trim($username));
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key);
    }

    private function read(string $key): array
    {
        $raw = @file_get_contents($this->path($key));
        return $this->fresh($this->decode($raw));
    }

    private function decode(string|false $raw): ?array
    {
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    /** Resets expired windows and fills defaults. */
    private function fresh(?array $state): array
    {
        if (!is_array($state) || !isset($state['start'], $state['count']) || $state['start'] + $this->window <= time()) {
            return ['start' => time(), 'count' => 0];
        }
        return $state;
    }

    private function update(string $key, callable $fn): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            error_log('[Gallerix] login throttle: cannot create ' . $this->dir);
            return;
        }
        $fh = @fopen($this->path($key), 'c+');
        if (!$fh) return;
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $state = $fn($this->fresh($this->decode($raw)));
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)json_encode($state));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
