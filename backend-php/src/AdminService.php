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
        $found = false;
        foreach ($users as &$u) {
            if (strcasecmp($u['username'] ?? '', $user['username'] ?? '') === 0) {
                $u = array_merge($u, $user);
                $found = true; break;
            }
        }
        if (!$found) $users[] = $user;
        $this->config->saveUsers($users);
        return $user;
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
