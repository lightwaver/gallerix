<?php
declare(strict_types=1);

namespace Gallerix;

class AdminService
{
    public function __construct(private ConfigLoader $config) {}

    // Users
    public function listUsers(): array { return $this->config->users(); }
    public function upsertUser(array $user): array {
        $users = $this->config->users();
        $incoming = $user;
        $username = (string)($incoming['username'] ?? '');
        if ($username === '') { throw new \InvalidArgumentException('username is required'); }

        // Normalize roles
        if (isset($incoming['roles'])) {
            if (is_string($incoming['roles'])) {
                $incoming['roles'] = array_values(array_filter(array_map('trim', explode(',', $incoming['roles'])), fn($r) => $r !== ''));
            } elseif (!is_array($incoming['roles'])) {
                $incoming['roles'] = [];
            }
        }

        // Handle password: hash if plaintext provided; do not store plaintext
        $hasPlain = isset($incoming['password']) && trim((string)$incoming['password']) !== '';
        $hasHash = isset($incoming['passwordHash']) && trim((string)$incoming['passwordHash']) !== '';
        $newHash = null;
        if ($hasPlain) { $newHash = password_hash((string)$incoming['password'], PASSWORD_DEFAULT); }
        elseif ($hasHash) { $newHash = (string)$incoming['passwordHash']; }

        // Update existing or append new
        $found = false;
        foreach ($users as &$u) {
            if (strcasecmp($u['username'] ?? '', $username) === 0) {
                $found = true;
                // Merge updatable fields (roles and others)
                foreach ($incoming as $k => $v) {
                    if ($k === 'password' || $k === 'passwordHash' || $k === 'username') continue;
                    $u[$k] = $v;
                }
                // Password: only update if provided
                if ($newHash !== null) { $u['passwordHash'] = $newHash; }
                break;
            }
        }
        unset($u); // break reference

        if (!$found) {
            $record = ['username' => $username, 'roles' => $incoming['roles'] ?? []];
            if ($newHash !== null) { $record['passwordHash'] = $newHash; }
            $users[] = $record;
        }

        $this->config->saveUsers($users);
        // Return safe user object (omit password)
        $safe = ['username' => $username, 'roles' => $incoming['roles'] ?? ($found ? null : [])];
        if ($safe['roles'] === null) { unset($safe['roles']); }
        return $safe;
    }
    public function deleteUser(string $username): void {
        $users = array_values(array_filter($this->config->users(), fn($u) => strcasecmp($u['username'] ?? '', $username) !== 0));
        $this->config->saveUsers($users);
    }

    // Roles
    public function getRoles(): array { return $this->config->roles(); }
    public function setRoles(array $roles): array { $this->config->saveRoles($roles); return $roles; }

    // Galleries
    public function listGalleries(): array { return $this->config->galleries(); }
    public function upsertGallery(array $gallery): array {
        $gals = $this->config->galleries();
        $found = false;
        foreach ($gals as &$g) {
            if (($g['name'] ?? '') === ($gallery['name'] ?? '')) { $g = array_merge($g, $gallery); $found = true; break; }
        }
        if (!$found) $gals[] = $gallery;
        $this->config->saveGalleries($gals);
        return $gallery;
    }
    public function deleteGallery(string $name): void {
        $gals = array_values(array_filter($this->config->galleries(), fn($g) => ($g['name'] ?? '') !== $name));
        $this->config->saveGalleries($gals);
    }
}
