<?php
declare(strict_types=1);

// Prevent warnings/deprecations from polluting JSON responses; log instead
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

use Gallerix\Router;
use Dotenv\Dotenv;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$router = new Router();
try {
    $router->handle($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    error_log('[Gallerix] Unhandled: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'details' => $e->getMessage(),
    ]);
}
