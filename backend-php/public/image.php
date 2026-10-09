<?php
declare(strict_types=1);

// Suppress notices in output, log instead
// Deprecations are excluded: the Azure storage SDK emits dozens per request on PHP 8.4+
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Gallerix\AzureClient;
use Gallerix\ConfigLoader;
use Gallerix\Auth;
use Gallerix\Authorizer;
use Gallerix\Cors;
use Gallerix\GalleryService;
use Gallerix\MediaPolicy;
use Gallerix\MediaSigner;

// Load env
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
$dotenv->safeLoad();
Cors::apply('GET, OPTIONS');

// Read inputs
$gallery = isset($_GET['g']) ? (string)$_GET['g'] : '';
$file = isset($_GET['f']) ? (string)$_GET['f'] : '';
if (!MediaPolicy::isSafeSegment($gallery) || !MediaPolicy::isSafeSegment($file)) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

try {
    $azure = new AzureClient();
    $config = new ConfigLoader($azure);
    $auth = new Auth($config);
    $gals = new GalleryService($azure, $config);

    $gal = $gals->getGalleryByName($gallery);
    if (!$gal) { http_response_code(404); echo 'Not found'; exit; }
    // Access: public gallery, a valid signed URL (issued by the API after its permission check),
    // or an Authorization header for API clients. Session tokens in cookies/query are no longer accepted.
    $authz = new Authorizer($config);
    $signed = (new MediaSigner())->verify($gallery, $file, (string)($_GET['e'] ?? ''), (string)($_GET['sig'] ?? ''));
    if (!$signed && !Authorizer::isPublic($gal)) {
        $user = $auth->requireAuth();
        if (!$authz->canGallery($user, Authorizer::VIEW, $gal)) { http_response_code(403); echo 'Forbidden'; exit; }
    }

    // Fetch blob and stream
    $client = $azure->getBlobClient();
    $container = getenv('AZURE_CONTAINER_DATA') ?: 'data';
    $blobName = rtrim($gallery, '/') . '/' . $file;
    $blob = $client->getBlob($container, $blobName);
    $props = $blob->getProperties();
    $len = $props->getContentLength();

    // Only allowlisted media types are served inline; anything else (e.g. legacy HTML/SVG uploads) is forced to download
    $ct = MediaPolicy::sendSafeMediaHeaders($props->getContentType(), $file);
    header('Content-Type: ' . $ct);
    if ($len !== null) header('Content-Length: ' . $len);
    header('Cache-Control: private, max-age=0, no-cache');
    fpassthru($blob->getContentStream());
} catch (Throwable $e) {
    error_log('[Gallerix] media error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
}
