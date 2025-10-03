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
        $this->jwtSecret = getenv('JWT_SECRET') ?: 'change-me';
        $this->jwtIssuer = getenv('JWT_ISSUER') ?: 'gallerix';
        $this->jwtExpiresIn = (int)(getenv('JWT_EXPIRES_IN') ?: '86400');
    }

    public function login(string $username, string $password): ?array
    {
        $users = $this->config->users();
        foreach ($users as $user) {
            if (strcasecmp($user['username'] ?? '', $username) === 0) {
                $hash = $user['passwordHash'] ?? '';
                if (password_verify($password, $hash)) {
                    $roles = $user['roles'] ?? [];
                    $now = time();
                    $payload = [
                        'sub' => $username,
                        'roles' => $roles,
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
        $token = substr($auth, 7);
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            return [
                'username' => $decoded->sub ?? 'unknown',
                'roles' => $decoded->roles ?? []
            ];
        } catch (\Throwable $e) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token']);
            exit;
        }
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
