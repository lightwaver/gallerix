<?php
declare(strict_types=1);

namespace Gallerix;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth
{
    private ConfigLoader $config;
    private string $jwtSecret;
    private string $jwtIssuer;
    private int $jwtExpiresIn;

    public function __construct(ConfigLoader $config)
    {
        $this->config = $config;
        $this->jwtSecret = self::secret();
        $this->jwtIssuer = getenv('JWT_ISSUER') ?: 'gallerix';
        $this->jwtExpiresIn = (int)(getenv('JWT_EXPIRES_IN') ?: '86400');
    }

    public static function secret(): string
    {
        $secret = (string)getenv('JWT_SECRET');
        // php-jwt >= 7 rejects HS256 keys shorter than 256 bit; fail with a clear message instead
        if (strlen($secret) < 32 || $secret === 'change-me') {
            throw new \RuntimeException('JWT_SECRET must be set to a random value of at least 32 bytes (e.g. `openssl rand -base64 48`)');
        }
        return $secret;
    }

    public function login(string $username, string $password): ?array
    {
        $users = $this->config->users();
        foreach ($users as $user) {
            if (strcasecmp($user['username'] ?? '', $username) === 0) {
                $hash = $user['passwordHash'] ?? '';
                if (password_verify($password, $hash)) {
                    $username = (string)$user['username'];
                    $roles = $user['roles'] ?? [];
                    $now = time();
                    $payload = [
                        'sub' => $username,
                        'pv' => self::passwordVersion($hash),
                        'iat' => $now,
                        'nbf' => $now,
                        'exp' => $now + $this->jwtExpiresIn,
                        'iss' => $this->jwtIssuer,
                    ];
                    $token = JWT::encode($payload, $this->jwtSecret, 'HS256');
                    return ['token' => $token, 'user' => ['username' => $username, 'roles' => $roles]];
                }
            }
        }
        return null;
    }

    public function requireAuth(): array
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($auth, 'Bearer ')) {
            http_response_code(401);
            echo json_encode(['error' => 'Missing token']);
            exit;
        }
        $user = $this->userFromToken(substr($auth, 7));
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token']);
            exit;
        }
        return $user;
    }

    /**
     * Attempts to read and decode the Authorization token if present.
     * Returns the user array on success, or null if no/invalid token.
     */
    public function optionalAuth(): ?array
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($auth, 'Bearer ')) {
            return null;
        }
        return $this->userFromToken(substr($auth, 7));
    }

    /**
     * Validates the JWT and resolves the user against the current users.json, so deleted users,
     * changed roles and changed passwords take effect immediately instead of at token expiry.
     */
    private function userFromToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        } catch (\Throwable $e) {
            return null;
        }
        if (($decoded->iss ?? null) !== $this->jwtIssuer) return null;
        $sub = (string)($decoded->sub ?? '');
        foreach ($this->config->users() as $u) {
            if (strcasecmp($u['username'] ?? '', $sub) !== 0) continue;
            if (!hash_equals(self::passwordVersion((string)($u['passwordHash'] ?? '')), (string)($decoded->pv ?? ''))) {
                return null;
            }
            return [
                'username' => (string)$u['username'],
                'roles' => array_values($u['roles'] ?? []),
            ];
        }
        return null;
    }

    /** Short fingerprint of the password hash; changing the password invalidates existing tokens. */
    private static function passwordVersion(string $hash): string
    {
        return substr(hash_hmac('sha256', $hash, self::secret()), 0, 16);
    }

    public function hasRole(array $user, string $role): bool
    {
        return in_array($role, $user['roles'] ?? [], true);
    }

    public function can(array $user, string $permission, ?array $gallery = null): bool
    {
        $rolesDef = $this->config->roles();
        $userRoles = $user['roles'] ?? [];
        $neededRoles = [];
        if ($permission === 'admin') {
            $neededRoles = ['admin'];
        } elseif ($gallery) {
            $galRoles = $gallery['roles'] ?? [];
            $neededRoles = $galRoles[$permission] ?? [];
        } else {
            // global view list
            $neededRoles = $rolesDef['global'][$permission] ?? [];
        }
        if (empty($neededRoles)) return false;
        foreach ($userRoles as $r) {
            if (in_array($r, $neededRoles, true)) return true;
        }
        return false;
    }
}
