<?php
declare(strict_types=1);

namespace Gallerix;

class Router
{
    private AzureClient $azure;
    private ConfigLoader $config;
    private Auth $auth;
    private GalleryService $galleries;

    public function __construct()
    {
        $this->azure = new AzureClient();
        $this->config = new ConfigLoader($this->azure);
        $this->auth = new Auth($this->config);
        $this->galleries = new GalleryService($this->azure, $this->config);
    }

    public function handle(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if ($path === '/api/login' && $method === 'POST') {
            $this->postLogin();
            return;
        }
        if ($path === '/api/galleries' && $method === 'GET') {
            $this->getGalleries();
            return;
        }
        if (preg_match('#^/api/galleries/([^/]+)/items$#', $path, $m) && $method === 'GET') {
            $this->getGalleryItems($m[1]);
            return;
        }
        if (preg_match('#^/api/galleries/([^/]+)/upload$#', $path, $m) && $method === 'POST') {
            $this->postUpload($m[1]);
            return;
        }
        if ($path === '/api/me' && $method === 'GET') {
            $user = $this->auth->requireAuth();
            echo json_encode(['user' => $user]);
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
        echo json_encode($res);
    }

    private function getGalleries(): void
    {
        $user = $this->auth->requireAuth();
        $list = $this->galleries->listGalleriesForUser($user);
        echo json_encode(['galleries' => $list]);
    }

    private function getGalleryItems(string $name): void
    {
        $user = $this->auth->requireAuth();
        $gal = $this->galleries->getGalleryByName($name);
        if (!$gal) {
            http_response_code(404);
            echo json_encode(['error' => 'Gallery not found']);
            return;
        }
        if (!$this->auth->can($user, 'view', $gal)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        $items = $this->galleries->listItems($name);
        echo json_encode(['items' => $items, 'gallery' => ['name' => $name, 'title' => $gal['title'] ?? $name]]);
    }

    private function postUpload(string $name): void
    {
        $user = $this->auth->requireAuth();
        $gal = $this->galleries->getGalleryByName($name);
        if (!$gal) {
            http_response_code(404);
            echo json_encode(['error' => 'Gallery not found']);
            return;
        }
        if (!$this->auth->can($user, 'upload', $gal)) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return;
        }
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing file field "file"']);
            return;
        }
        $res = $this->galleries->upload($name, $_FILES['file']);
        echo json_encode($res);
    }
}
