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
    public function listGalleries(): array {
        return array_map([Authorizer::class, 'normalizeGallery'], $this->config->galleries());
    }

    /** Creates a gallery or updates an existing one (system admin). */
    public function upsertGallery(array $gallery): array {
        $name = (string)($gallery['name'] ?? '');
        if (!MediaPolicy::isSafeSegment($name)) { throw new \InvalidArgumentException('Invalid gallery name'); }
        $updated = $this->updateGallery($name, $gallery);
        if ($updated !== null) return $updated;
        if (!MediaPolicy::isValidNewGalleryName($name)) {
            throw new \InvalidArgumentException('Invalid gallery name (allowed: a-z, 0-9, "-", "_"; max 64 chars)');
        }
        $new = Authorizer::normalizeGallery(['name' => $name, 'title' => $name, 'description' => ''] + self::galleryChanges($gallery));
        $gals = $this->config->galleries();
        $gals[] = $new;
        $this->config->saveGalleries($gals);
        return $new;
    }

    /**
     * Applies title/description/public/roles changes to an existing gallery; other stored keys are kept.
     * Returns the updated gallery, or null if it does not exist.
     */
    public function updateGallery(string $name, array $input): ?array {
        $gals = $this->config->galleries();
        foreach ($gals as $i => $g) {
            if (($g['name'] ?? '') !== $name) continue;
            $gals[$i] = self::applyGalleryChanges($g, $input);
            $this->config->saveGalleries($gals);
            return $gals[$i];
        }
        return null;
    }

    /** Returns the gallery with the (whitelisted) changes applied, without saving. */
    public static function applyGalleryChanges(array $gallery, array $input): array {
        $current = Authorizer::normalizeGallery($gallery);
        $changes = self::galleryChanges($input);
        if (isset($changes['roles'])) $changes['roles'] = array_merge($current['roles'], $changes['roles']);
        return Authorizer::normalizeGallery(array_merge($current, $changes));
    }

    /** Whitelists and sanitizes the editable gallery fields of a request body. */
    public static function galleryChanges(array $input): array {
        $changes = [];
        if (array_key_exists('title', $input)) {
            $title = trim((string)$input['title']);
            if ($title !== '') $changes['title'] = mb_substr($title, 0, 200);
        }
        if (array_key_exists('description', $input)) $changes['description'] = mb_substr((string)$input['description'], 0, 2000);
        if (array_key_exists('public', $input)) $changes['public'] = filter_var($input['public'], FILTER_VALIDATE_BOOLEAN);
        if (is_array($input['roles'] ?? null)) {
            foreach ([Authorizer::VIEW, Authorizer::UPLOAD, Authorizer::MANAGE] as $level) {
                if (array_key_exists($level, $input['roles'])) {
                    $changes['roles'][$level] = Authorizer::cleanRoleList($input['roles'][$level]);
                }
            }
        }
        return $changes;
    }

    public function deleteGallery(string $name): void {
        $gals = array_values(array_filter($this->config->galleries(), fn($g) => ($g['name'] ?? '') !== $name));
        $this->config->saveGalleries($gals);
    }
}
