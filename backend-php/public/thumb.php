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
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;

// Load env
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

Cors::apply('GET, OPTIONS');

$gallery = isset($_GET['g']) ? (string)$_GET['g'] : '';
$file = isset($_GET['f']) ? (string)$_GET['f'] : '';
if (!MediaPolicy::isSafeSegment($gallery) || !MediaPolicy::isSafeSegment($file)) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}
// Thumbnails are always raster images we generated; still forbid sniffing and active content
MediaPolicy::sendSafeMediaHeaders('image/jpeg', $file);

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

    $client = $azure->getBlobClient();
    $dataContainer = getenv('AZURE_CONTAINER_DATA') ?: 'data';
    $thumbsContainer = getenv('AZURE_CONTAINER_THUMBS') ?: 'thumbs';
    $blobName = rtrim($gallery, '/') . '/' . $file;
    // Size selector: default 'thumb', optional 'preview' uses PREVIEW_MAX_SIZE and a different cache name
    $sizeParam = isset($_GET['s']) ? (string)$_GET['s'] : 'thumb';
    $isPreview = ($sizeParam === 'preview');
    $thumbName = $isPreview ? GalleryService::previewBlobName($blobName) : $blobName;

    // Try to serve existing thumb (new naming). If not found, try legacy preview/ prefix and copy to new name.
    try {
        $thumb = $client->getBlob($thumbsContainer, $thumbName);
        $props = $thumb->getProperties();
        $ct = $props->getContentType() ?: 'image/jpeg';
        $len = $props->getContentLength();
        header('Content-Type: ' . $ct);
        if ($len !== null) header('Content-Length: ' . $len);
        header('Cache-Control: private, max-age=86400');
        fpassthru($thumb->getContentStream());
        exit;
    } catch (\Throwable $e) {
        if ($isPreview) {
            // legacy path fallback
            $legacyName = 'preview/' . $blobName;
            try {
                $thumb = $client->getBlob($thumbsContainer, $legacyName);
                $props = $thumb->getProperties();
                $ct = $props->getContentType() ?: 'image/jpeg';
                $data = stream_get_contents($thumb->getContentStream());
                if ($data !== false) {
                    // copy to new naming for future hits
                    $opts = new CreateBlockBlobOptions();
                    $opts->setContentType($ct);
                    $client->createBlockBlob($thumbsContainer, $thumbName, $data, $opts);
                    header('Content-Type: ' . $ct);
                    header('Content-Length: ' . strlen($data));
                    header('Cache-Control: private, max-age=86400');
                    echo $data; exit;
                }
            } catch (\Throwable $e2) {
                // fall through to generate
            }
        }
        // proceed to generate
    }

    // Fetch original
    $orig = $client->getBlob($dataContainer, $blobName);
    $origProps = $orig->getProperties();
    $origCt = (string)($origProps->getContentType() ?: 'image/jpeg');

    // Only generate thumbs for images; otherwise, return a tiny transparent PNG as placeholder
    if (strpos($origCt, 'image') !== 0) {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGMAAQAABQABDQottAAAAABJRU5ErkJggg==');
        header('Content-Type: image/png');
        header('Content-Length: ' . strlen($png));
        header('Cache-Control: private, max-age=86400');
        echo $png;
        exit;
    }

    // Read original into memory
    $data = stream_get_contents($orig->getContentStream());
    if ($data === false) { throw new \RuntimeException('Failed to read source image'); }
    $im = @imagecreatefromstring($data);
    if (!$im) { throw new \RuntimeException('Unsupported image format'); }

    $srcW = imagesx($im); $srcH = imagesy($im);
    // Sizing and quality from env with sane defaults
    $maxEnv = $isPreview ? getenv('PREVIEW_MAX_SIZE') : getenv('THUMB_MAX_SIZE');
    $maxSize = (int) ($maxEnv !== false ? $maxEnv : ($isPreview ? 1200 : 360));
    if ($maxSize < 16) { $maxSize = 16; }
    if ($maxSize > 4096) { $maxSize = 4096; }
    $jpegQuality = (int) (getenv('THUMB_QUALITY') !== false ? getenv('THUMB_QUALITY') : 82);
    if ($jpegQuality < 1) { $jpegQuality = 1; }
    if ($jpegQuality > 100) { $jpegQuality = 100; }

    $maxW = $maxSize; $maxH = $maxSize;
    $scale = min($maxW / max(1,$srcW), $maxH / max(1,$srcH), 1.0);
    $dstW = (int)max(1, round($srcW * $scale));
    $dstH = (int)max(1, round($srcH * $scale));
    $dst = imagecreatetruecolor($dstW, $dstH);

    // Transparency for PNG/GIF
    $isPng = stripos($origCt, 'png') !== false;
    $isGif = stripos($origCt, 'gif') !== false;
    if ($isPng || $isGif) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $trans);
    }
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    // Encode
    $outType = $isPng ? 'image/png' : ($isGif ? 'image/gif' : 'image/jpeg');
    ob_start();
    if ($outType === 'image/png') {
        // Map 0-100 quality to PNG compression level 0-9 (higher quality -> lower compression)
        $pngCompression = (int) round((100 - $jpegQuality) / 10);
        if ($pngCompression < 0) { $pngCompression = 0; }
        if ($pngCompression > 9) { $pngCompression = 9; }
        imagepng($dst, null, $pngCompression);
    } elseif ($outType === 'image/gif') {
        imagegif($dst);
    } else {
        imagejpeg($dst, null, $jpegQuality);
    }
    $thumbData = ob_get_clean();
    imagedestroy($im); imagedestroy($dst);

    // Upload thumbnail
    $opts = new CreateBlockBlobOptions();
    $opts->setContentType($outType);
    $client->createBlockBlob($thumbsContainer, $thumbName, $thumbData, $opts);

    // Stream response
    header('Content-Type: ' . $outType);
    header('Content-Length: ' . strlen($thumbData));
    header('Cache-Control: private, max-age=86400');
    echo $thumbData;
} catch (Throwable $e) {
    error_log('[Gallerix] thumb error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Server error';
}
