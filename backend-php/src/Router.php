<?php
declare(strict_types=1);

namespace Gallerix;

class Router
{
    private AzureClient $azure;
    private ConfigLoader $config;
    private Auth $auth;
    private Authorizer $authz;
    private GalleryService $galleries;
    private AdminService $admin;

    public function __construct()
    {
        $this->azure = new AzureClient();
        $this->config = new ConfigLoader($this->azure);
        $this->auth = new Auth($this->config);
        $this->authz = new Authorizer($this->config);
        $this->galleries = new GalleryService($this->azure, $this->config);
        $this->admin = new AdminService($this->config);
    }

    public function handle(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path === '/api/login' && $method === 'POST') {
            $this->postLogin();
            return;
        }
        if ($path === '/api/logout' && $method === 'POST') {
            $this->postLogout();
            return;
        }
        if ($path === '/api/galleries' && $method === 'GET') {
            $this->getGalleries();
            return;
        }
        if ($path === '/api/public-galleries' && $method === 'GET') {
            $this->getPublicGalleries();
            return;
        }
        if ($path === '/api/galleries' && $method === 'POST') {
            $this->postCreateGallery();
            return;
        }
        if (preg_match('#^/api/galleries/([^/]+)/items$#', $path, $m) && $method === 'GET') {
            $this->getGalleryItems(rawurldecode($m[1]));
            return;
        }
        if (preg_match('#^/api/galleries/([^/]+)/items/([^/]+)$#', $path, $m) && $method === 'DELETE') {
            $this->deleteGalleryItem(rawurldecode($m[1]), rawurldecode($m[2]));
            return;
        }
        if (preg_match('#^/api/galleries/([^/]+)/upload$#', $path, $m) && $method === 'POST') {
            $this->postUpload(rawurldecode($m[1]));
            return;
        }
        if (preg_match('#^/api/galleries/([^/]+)$#', $path, $m) && $method === 'PATCH') {
            $this->patchGallery(rawurldecode($m[1]));
            return;
        }
        if ($path === '/api/me' && $method === 'GET') {
            $user = $this->auth->requireAuth();
            echo json_encode(['user' => $user + $this->authz->userCapabilities($user)]);
            return;
        }

        // Admin endpoints
        if (str_starts_with($path, '/api/admin')) {
            $this->handleAdmin($method, $path);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }

    private function postLogin(): void
    {
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];
        $username = (string)($input['username'] ?? '');
        $password = (string)($input['password'] ?? '');
        if ($username === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username and password required']);
            return;
        }
        $res = $this->auth->login($username, $password);
        if (!$res) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
            return;
        }
        $res['user'] += $this->authz->userCapabilities($res['user']);
        echo json_encode($res);
    }

    private function postLogout(): void
    {
        // Media is served via signed URLs now; expire the legacy media cookie set by older versions
        setcookie('gallerix_token', '', [
            'expires' => 1,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        echo json_encode(['ok' => true]);
    }

    private function getGalleries(): void
    {
        $user = $this->auth->requireAuth();
        $list = $this->galleries->listGalleries(fn($g) => $this->authz->canGallery($user, Authorizer::VIEW, $g));
        echo json_encode(['galleries' => $list, 'canCreate' => $this->authz->canCreateGallery($user)]);
    }

    private function getPublicGalleries(): void
    {
        // No auth required
        $list = $this->galleries->listGalleries(fn($g) => Authorizer::isPublic($g));
        echo json_encode(['galleries' => $list]);
    }

    private function getGalleryItems(string $name): void
    {
        $gal = $this->galleries->getGalleryByName($name);
        if (!$gal) {
            http_response_code(404);
            echo json_encode(['error' => 'Gallery not found']);
            return;
        }
        // Public galleries: a logged-in user is still detected to decide upload/manage permissions
        $user = $gal['public'] ? $this->auth->optionalAuth() : $this->auth->requireAuth();
        $perms = $this->authz->galleryPermissions($user, $gal);
        if (!$perms['canView']) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        $items = $this->galleries->listItems($name);
        echo json_encode(['items' => $items, 'gallery' => $this->galleryInfo($gal, $perms)]);
    }

    /** Gallery metadata for the frontend; role lists are only exposed to gallery managers. */
    private function galleryInfo(array $gal, array $perms): array
    {
        $info = [
            'name' => $gal['name'],
            'title' => $gal['title'] ?? $gal['name'],
            'description' => $gal['description'] ?? '',
            'public' => $gal['public'],
        ] + $perms;
        if ($perms['canManage']) $info['roles'] = $gal['roles'];
        return $info;
    }

    /** Gallery managers: edit title, description, visibility and roles. */
    private function patchGallery(string $name): void
    {
        $user = $this->auth->requireAuth();
        $gal = $this->requireGalleryPermission($user, $name, Authorizer::MANAGE);
        if (!$gal) return;
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];
        // Refuse changes that would take away the editor's own manage access (system admins can't lock out)
        if (!$this->authz->canGallery($user, Authorizer::MANAGE, AdminService::applyGalleryChanges($gal, $input))) {
            http_response_code(400);
            echo json_encode(['error' => 'This change would remove your own admin access to the gallery']);
            return;
        }
        $updated = $this->admin->updateGallery($name, $input);
        echo json_encode(['gallery' => $this->galleryInfo($updated, $this->authz->galleryPermissions($user, $updated))]);
    }

    /** Gallery managers: delete a single file (and its cached thumbnails). */
    private function deleteGalleryItem(string $name, string $file): void
    {
        $user = $this->auth->requireAuth();
        if (!$this->requireGalleryPermission($user, $name, Authorizer::MANAGE)) return;
        if (!MediaPolicy::isSafeSegment($file) || !$this->galleries->deleteItem($name, $file)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            return;
        }
        echo json_encode(['ok' => true]);
    }

    /** Returns the gallery if the user has the permission, otherwise responds 404/403 and returns null. */
    private function requireGalleryPermission(array $user, string $name, string $permission): ?array
    {
        $gal = $this->galleries->getGalleryByName($name);
        if (!$gal) {
            http_response_code(404);
            echo json_encode(['error' => 'Gallery not found']);
            return null;
        }
        if (!$this->authz->canGallery($user, $permission, $gal)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return null;
        }
        return $gal;
    }

    private function postUpload(string $name): void
    {
        $user = $this->auth->requireAuth();
        if (!$this->requireGalleryPermission($user, $name, Authorizer::UPLOAD)) return;
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing file field "file"']);
            return;
        }
        try {
            $res = $this->galleries->upload($name, $_FILES['file']);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }
        echo json_encode($res);
    }

    private function handleAdmin(string $method, string $path): void
    {
        $user = $this->auth->requireAuth();
        if (!$this->authz->isSystemAdmin($user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin only']);
            return;
        }
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];
        // Users
        if ($path === '/api/admin/users' && $method === 'GET') { echo json_encode(['users' => $this->admin->listUsers()]); return; }
        if ($path === '/api/admin/users' && $method === 'POST') { echo json_encode(['user' => $this->admin->upsertUser($input)]); return; }
        if (preg_match('#^/api/admin/users/([^/]+)$#', $path, $m)) {
            if ($method === 'DELETE') { $this->admin->deleteUser(rawurldecode($m[1])); echo json_encode(['ok' => true]); return; }
        }
        // Roles
        if ($path === '/api/admin/roles' && $method === 'GET') { echo json_encode(['roles' => $this->admin->getRoles()]); return; }
        if ($path === '/api/admin/roles' && $method === 'PUT') { echo json_encode(['roles' => $this->admin->setRoles($input)]); return; }
        // Galleries
        if ($path === '/api/admin/galleries' && $method === 'GET') { echo json_encode(['galleries' => $this->admin->listGalleries()]); return; }
        if ($path === '/api/admin/galleries' && ($method === 'POST' || $method === 'PUT')) { echo json_encode(['gallery' => $this->admin->upsertGallery($input)]); return; }
        if (preg_match('#^/api/admin/galleries/([^/]+)$#', $path, $m)) {
            if ($method === 'DELETE') {
                $name = rawurldecode($m[1]);
                try {
                    // Clean blobs from storage (data and thumbs)
                    $this->galleries->deleteGalleryContents($name);
                } catch (\Throwable $e) {
                    error_log('[Gallerix] delete gallery contents failed for ' . $name . ': ' . $e->getMessage());
                }
                $this->admin->deleteGallery($name);
                echo json_encode(['ok' => true]); return;
            }
        }
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }

    private function postCreateGallery(): void
    {
        $user = $this->auth->requireAuth();
        if (!$this->authz->canCreateGallery($user)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        $input = json_decode(file_get_contents('php://input') ?: 'null', true) ?: [];
        $title = trim((string)($input['title'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $desc = (string)($input['description'] ?? '');
        if ($name === '' && $title !== '') {
            $name = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '');
            $name = trim(substr(trim($name, '-'), 0, 64), '-');
        }
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Gallery name or title required']);
            return;
        }
        if (!MediaPolicy::isValidNewGalleryName($name)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid gallery name (allowed: a-z, 0-9, "-", "_"; max 64 chars)']);
            return;
        }
        // Creating must never modify an existing gallery (would let creators rewrite its roles)
        if ($this->galleries->getGalleryByName($name)) {
            http_response_code(409);
            echo json_encode(['error' => 'A gallery with this name already exists']);
            return;
        }
        // Defaults: the creator's roles may view and upload, only the creator manages.
        // Explicit role lists from the request extend these defaults; system admins always have full access.
        $userRoles = Authorizer::cleanRoleList($user['roles'] ?? []);
        $inputRoles = is_array($input['roles'] ?? null) ? $input['roles'] : [];
        $defaults = [
            Authorizer::VIEW => $userRoles,
            Authorizer::UPLOAD => $userRoles,
            Authorizer::MANAGE => [Authorizer::userPrincipal($user)],
        ];
        $roles = [];
        foreach ($defaults as $level => $base) {
            $roles[$level] = Authorizer::cleanRoleList(array_merge($base, Authorizer::cleanRoleList($inputRoles[$level] ?? [])));
        }
        $gallery = [
            'name' => $name,
            'title' => $title !== '' ? $title : $name,
            'description' => $desc,
            'public' => filter_var($input['public'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'roles' => $roles,
        ];
        $res = $this->admin->upsertGallery($gallery);
        echo json_encode(['gallery' => $res]);
    }
}
