<?php
declare(strict_types=1);

// Suppress notices in output, log instead
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Gallerix\AzureClient;
use Gallerix\ConfigLoader;
use Gallerix\Auth;
use Gallerix\GalleryService;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;

// Load env
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Basic CORS for media
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

    // Token via cookie/header/query
    $token = null;
    if (isset($_COOKIE['gallerix_token'])) {
        $token = 'Bearer ' . $_COOKIE['gallerix_token'];
    } elseif (!empty($_GET['t'])) {
        $token = 'Bearer ' . (string)$_GET['t'];
    } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $token = (string)$_SERVER['HTTP_AUTHORIZATION'];
    }
    if ($token) { $_SERVER['HTTP_AUTHORIZATION'] = $token; }

    $gal = $gals->getGalleryByName($gallery);
    if (!$gal) { http_response_code(404); echo 'Not found'; exit; }
    $isPublic = in_array('public', ($gal['roles']['view'] ?? []), true);
    if (!$isPublic) {
        $user = $auth->requireAuth();
        if (!$auth->can($user, 'view', $gal)) { http_response_code(403); echo 'Forbidden'; exit; }
    }

    $client = $azure->getBlobClient();
    $dataContainer = getenv('AZURE_CONTAINER_DATA') ?: 'data';
    $thumbsContainer = getenv('AZURE_CONTAINER_THUMBS') ?: 'thumbs';
    $blobName = rtrim($gallery, '/') . '/' . $file;
    // Size selector: default 'thumb', optional 'preview' uses PREVIEW_MAX_SIZE and a different cache name
    $sizeParam = isset($_GET['s']) ? (string)$_GET['s'] : 'thumb';
    $isPreview = ($sizeParam === 'preview');
    // build suffix name for preview: file_preview.ext
    $suffixName = $blobName;
    if ($isPreview) {
        $dot = strrpos($blobName, '.');
        if ($dot === false) { $suffixName = $blobName . '_preview'; }
        else { $suffixName = substr($blobName, 0, $dot) . '_preview' . substr($blobName, $dot); }
    }
    $thumbName = $isPreview ? $suffixName : $blobName;

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
