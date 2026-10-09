<?php
declare(strict_types=1);

namespace Gallerix;

/**
 * Single place for all permission decisions.
 *
 * Global (roles.json -> global):
 *   admin          roles that are system admins: settings + full access to every gallery
 *                  (the role "admin" is always a system admin, so nobody can lock themselves out)
 *   createGallery  roles that may create new galleries
 *
 * Per gallery (galleries.json -> roles), hierarchical: admin ⊇ upload ⊇ view
 *   view    see the gallery and its files
 *   upload  additionally upload files
 *   admin   additionally edit the gallery (title, description, visibility, roles) and delete files
 *
 * All role lists (global and per gallery) may also contain user principals ("@alice") to grant a single user access.
 * A gallery with "public": true is viewable without login.
 */
class Authorizer
{
    public const VIEW = 'view';
    public const UPLOAD = 'upload';
    public const MANAGE = 'admin';

    /** Gallery role lists that grant a permission (higher levels include the lower ones). */
    private const GRANTED_BY = [
        self::VIEW => [self::VIEW, self::UPLOAD, self::MANAGE],
        self::UPLOAD => [self::UPLOAD, self::MANAGE],
        self::MANAGE => [self::MANAGE],
    ];

    public function __construct(private ConfigLoader $config) {}

    public function isSystemAdmin(?array $user): bool
    {
        if (!$user) return false;
        $adminRoles = array_merge(['admin'], $this->globalRoles('admin'));
        return $this->hasAnyRole($user, $adminRoles);
    }

    public function canCreateGallery(?array $user): bool
    {
        if (!$user) return false;
        return $this->isSystemAdmin($user) || $this->hasAnyRole($user, $this->globalRoles('createGallery'));
    }

    public function canGallery(?array $user, string $permission, array $gallery): bool
    {
        if (!isset(self::GRANTED_BY[$permission])) return false;
        if ($permission === self::VIEW && self::isPublic($gallery)) return true;
        if (!$user) return false;
        if ($this->isSystemAdmin($user)) return true;
        $roles = self::normalizeGallery($gallery)['roles'];
        foreach (self::GRANTED_BY[$permission] as $level) {
            if ($this->hasAnyRole($user, $roles[$level])) return true;
        }
        return false;
    }

    /** User principal entry for role lists, e.g. "@alice". */
    public static function userPrincipal(array $user): string
    {
        return '@' . strtolower((string)($user['username'] ?? ''));
    }

    /** Everything a gallery role list entry can match for this user: their roles and "@username". */
    private static function principals(array $user): array
    {
        // A role literally named "@x" must never impersonate user x
        $roles = array_filter($user['roles'] ?? [], fn($r) => is_string($r) && !str_starts_with($r, '@'));
        return array_merge(array_values($roles), [self::userPrincipal($user)]);
    }

    /** Permission flags for the frontend. */
    public function galleryPermissions(?array $user, array $gallery): array
    {
        return [
            'canView' => $this->canGallery($user, self::VIEW, $gallery),
            'canUpload' => $this->canGallery($user, self::UPLOAD, $gallery),
            'canManage' => $this->canGallery($user, self::MANAGE, $gallery),
        ];
    }

    /** Capability flags of a user for the frontend (navigation etc.). */
    public function userCapabilities(array $user): array
    {
        return [
            'isAdmin' => $this->isSystemAdmin($user),
            'canCreateGallery' => $this->canCreateGallery($user),
        ];
    }

    public static function isPublic(array $gallery): bool
    {
        return ($gallery['public'] ?? false) === true
            || in_array('public', (array)($gallery['roles'][self::VIEW] ?? []), true);
    }

    /**
     * Canonical gallery shape: boolean "public" flag and clean view/upload/admin role lists.
     * Converts the legacy "public" pseudo-role in roles.view into the flag.
     */
    public static function normalizeGallery(array $gallery): array
    {
        $public = self::isPublic($gallery);
        $roles = is_array($gallery['roles'] ?? null) ? $gallery['roles'] : [];
        foreach ([self::VIEW, self::UPLOAD, self::MANAGE] as $level) {
            $roles[$level] = self::cleanRoleList($roles[$level] ?? [], ['public']);
        }
        $gallery['roles'] = $roles;
        $gallery['public'] = $public;
        return $gallery;
    }

    /** Trims, de-duplicates and drops empty / non-string / excluded entries. */
    public static function cleanRoleList(mixed $list, array $exclude = []): array
    {
        if (is_string($list)) $list = explode(',', $list);
        if (!is_array($list)) return [];
        $out = [];
        foreach ($list as $r) {
            if (!is_string($r)) continue;
            $r = trim($r);
            if ($r === '' || in_array($r, $exclude, true)) continue;
            $out[] = $r;
        }
        return array_values(array_unique($out));
    }

    private function globalRoles(string $permission): array
    {
        return self::cleanRoleList($this->config->roles()['global'][$permission] ?? []);
    }

    /** True if any entry of the role list is one of the user's roles or their "@username" principal. */
    private function hasAnyRole(array $user, array $list): bool
    {
        $principals = self::principals($user);
        foreach ($list as $entry) {
            if (in_array(str_starts_with($entry, '@') ? strtolower($entry) : $entry, $principals, true)) return true;
        }
        return false;
    }
}
