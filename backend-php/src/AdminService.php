<?php
declare(strict_types=1);

namespace Gallerix;

class AdminService
{
    public function __construct(private ConfigLoader $config) {}

    // Users

    /** Users without password hashes; those never leave the server. */
    public function listUsers(): array {
        return array_map(fn($u) => self::publicUser($u), $this->config->users());
    }

    /**
     * Creates or updates a user. Only username, roles and password are accepted; a plaintext
     * password is hashed here, a pre-computed passwordHash must be a valid password_hash() result.
     */
    public function upsertUser(array $input): array {
        $username = trim((string)($input['username'] ?? ''));
        if ($username === '') { throw new \InvalidArgumentException('username is required'); }

        $roles = null;
        if (array_key_exists('roles', $input)) {
            $roles = Authorizer::cleanRoleList($input['roles']);
            foreach ($roles as $r) {
                if (str_starts_with($r, '@')) throw new \InvalidArgumentException('Role names must not start with "@" (reserved for @username)');
            }
        }

        $newHash = null;
        $password = (string)($input['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 8) throw new \InvalidArgumentException('Password must be at least 8 characters');
            $newHash = password_hash($password, PASSWORD_DEFAULT);
        } elseif (trim((string)($input['passwordHash'] ?? '')) !== '') {
            $newHash = trim((string)$input['passwordHash']);
            if (password_get_info($newHash)['algoName'] === 'unknown') throw new \InvalidArgumentException('passwordHash is not a valid password hash');
        }

        $users = $this->config->users();
        foreach ($users as $i => $u) {
            if (strcasecmp($u['username'] ?? '', $username) !== 0) continue;
            if ($roles !== null) $users[$i]['roles'] = $roles;
            if ($newHash !== null) $users[$i]['passwordHash'] = $newHash;
            $this->config->saveUsers($users);
            return self::publicUser($users[$i]);
        }

        // New user
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $username)) {
            throw new \InvalidArgumentException('Invalid username (allowed: letters, digits, ".", "_", "-"; max 64 chars)');
        }
        if ($newHash === null) throw new \InvalidArgumentException('A password is required for new users');
        $record = ['username' => $username, 'roles' => $roles ?? [], 'passwordHash' => $newHash];
        $users[] = $record;
        $this->config->saveUsers($users);
        return self::publicUser($record);
    }

    public function deleteUser(string $username): void {
        $users = array_values(array_filter($this->config->users(), fn($u) => strcasecmp($u['username'] ?? '', $username) !== 0));
        $this->config->saveUsers($users);
    }

    private static function publicUser(array $u): array {
        return [
            'username' => (string)($u['username'] ?? ''),
            'roles' => array_values($u['roles'] ?? []),
            'hasPassword' => !empty($u['passwordHash']),
        ];
    }

    // Roles
    public function getRoles(): array { return $this->config->roles(); }

    /** Replaces roles.json; every permission must be a list of role names / @usernames. */
    public function setRoles(array $roles): array {
        if (!is_array($roles['global'] ?? null)) throw new \InvalidArgumentException('roles.global must be an object');
        $global = [];
        foreach ($roles['global'] as $perm => $list) {
            if (!is_string($perm) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $perm)) throw new \InvalidArgumentException('Invalid permission name');
            $global[$perm] = Authorizer::cleanRoleList($list);
        }
        $clean = ['global' => $global];
        $this->config->saveRoles($clean);
        return $clean;
    }

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
