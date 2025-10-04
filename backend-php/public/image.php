<?php
declare(strict_types=1);

// Suppress notices in output, log instead
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../vendor/autoload.php';
// Basic CORS for media (adjust origins as needed)
header('Vary: Origin');
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Access-Control-Allow-Credentials: true');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    http_response_code(204);
    exit;
}

use Dotenv\Dotenv;
use Gallerix\AzureClient;
use Gallerix\ConfigLoader;
use Gallerix\Auth;
use Gallerix\GalleryService;

// Load env
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Read inputs
$gallery = isset($_GET['g']) ? (string)$_GET['g'] : '';
$file = isset($_GET['f']) ? (string)$_GET['f'] : '';
if ($gallery === '' || $file === '') {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

try {
    $azure = new AzureClient();
    $config = new ConfigLoader($azure);
    $auth = new Auth($config);
    $gals = new GalleryService($azure, $config);

    // Extract token from Cookie, Authorization header, or query (?t=)
    $token = null;
    if (isset($_COOKIE['gallerix_token'])) {
        $token = 'Bearer ' . $_COOKIE['gallerix_token'];
    } elseif (!empty($_GET['t'])) {
        $token = 'Bearer ' . (string)$_GET['t'];
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = (string)$_SERVER['HTTP_AUTHORIZATION'];
    }

    if ($token) {
        // Temporarily inject header so requireAuth path works
        $_SERVER['HTTP_AUTHORIZATION'] = $token;
    }

    $gal = $gals->getGalleryByName($gallery);
    if (!$gal) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    // If gallery is public, allow without auth; otherwise require token and check roles
    $isPublic = in_array('public', ($gal['roles']['view'] ?? []), true);
    if (!$isPublic) {
        $user = $auth->requireAuth();
        if (!$auth->can($user, 'view', $gal)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }

    // Fetch blob and stream
    $client = $azure->getBlobClient();
    $container = getenv('AZURE_CONTAINER_DATA') ?: 'data';
    $blobName = rtrim($gallery, '/') . '/' . $file;
    $blob = $client->getBlob($container, $blobName);
    $props = $blob->getProperties();
    $ct = $props->getContentType() ?: 'application/octet-stream';
    $len = $props->getContentLength();

    header('Content-Type: ' . $ct);
    if ($len !== null) header('Content-Length: ' . $len);
    header('Cache-Control: private, max-age=0, no-cache');
    fpassthru($blob->getContentStream());
} catch (Throwable $e) {
    error_log('[Gallerix] media error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
}
