<?php
declare(strict_types=1);

// Prevent warnings/deprecations from polluting JSON responses; log instead
// Deprecations are excluded: the Azure storage SDK emits dozens per request on PHP 8.4+
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

use Gallerix\ConfigConflictException;
use Gallerix\Cors;
use Gallerix\Router;
use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

header('Content-Type: application/json');
Cors::apply('GET, POST, PUT, PATCH, DELETE, OPTIONS');

try {
    $router = new Router();
    $router->handle($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (ConfigConflictException $e) {
    http_response_code(409);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[Gallerix] Unhandled: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    $body = ['error' => 'Server error'];
    // Internal details only when explicitly debugging; they can leak paths, storage names or config state
    if (filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN)) {
        $body['details'] = $e->getMessage();
    }
    echo json_encode($body);
}
