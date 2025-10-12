<?php
declare(strict_types=1);

namespace Gallerix;

use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;

class GalleryService
{
    private AzureClient $azure;
    private ConfigLoader $config;
    private string $dataContainer;

    public function __construct(AzureClient $azure, ConfigLoader $config)
    {
        $this->azure = $azure;
        $this->config = $config;
        $this->dataContainer = getenv('AZURE_CONTAINER_DATA') ?: 'data';
    }

    public function listGalleriesForUser(array $user): array
    {
        $galleries = $this->config->galleries();
        $result = [];
        foreach ($galleries as $gal) {
            $roles = $gal['roles']['view'] ?? [];
            if ($this->userHasAnyRole($user, $roles)) {
                $result[] = [
                    'name' => $gal['name'],
                    'title' => $gal['title'] ?? $gal['name'],
                    'description' => $gal['description'] ?? '',
                    'coverUrl' => $this->buildGalleryCoverUrl($gal['name'], $user['token'] ?? null)
                ];
            }
        }
        return $result;
    }

    public function listPublicGalleries(): array
    {
        $galleries = $this->config->galleries();
        $result = [];
        foreach ($galleries as $gal) {
            $roles = $gal['roles']['view'] ?? [];
            if (in_array('public', $roles, true)) {
                $result[] = [
                    'name' => $gal['name'],
                    'title' => $gal['title'] ?? $gal['name'],
                    'description' => $gal['description'] ?? '',
                    'coverUrl' => $this->buildGalleryCoverUrl($gal['name'])
                ];
            }
        }
        return $result;
    }

    public function getGalleryByName(string $name): ?array
    {
        $galleries = $this->config->galleries();
        foreach ($galleries as $gal) {
            if (($gal['name'] ?? '') === $name) return $gal;
        }
        return null;
    }

    public function listItems(string $galleryName, ?string $token = null): array
    {
        $client = $this->azure->getBlobClient();
        $prefix = rtrim($galleryName, '/') . '/';
        $opts = new ListBlobsOptions();
        $opts->setPrefix($prefix);
        $items = [];
        $result = $client->listBlobs($this->dataContainer, $opts);
    $publicBase = rtrim((string)(getenv('PUBLIC_BASE_URL') ?: ''), '/');
    foreach ($result->getBlobs() as $blob) {
            $name = $blob->getName();
            if (str_ends_with($name, '/')) continue; // skip folders
            $file = substr($name, strlen($prefix));
            if ($file === '' || str_contains($file, '/')) continue; // only direct children
            // Serve via auth-protected proxy endpoint image.php
            $path = '/image.php?g=' . rawurlencode($galleryName) . '&f=' . rawurlencode($file);
            if ($token) {
                $path .= '&t=' . rawurlencode($token);
            }
            $url = $publicBase ? ($publicBase . $path) : $path;
            $thumbPath = 'api/thumb.php?g=' . rawurlencode($galleryName) . '&f=' . rawurlencode($file);
            if ($token) { $thumbPath .= '&t=' . rawurlencode($token); }
            $thumbUrl = $publicBase ? ($publicBase . $thumbPath) : $thumbPath;
            $mime = $blob->getProperties()->getContentType();
            $ctype = (string)$mime;
            $type = (str_starts_with($ctype, 'video')) ? 'video' : ((stripos($ctype, 'application/pdf') === 0) ? 'pdf' : 'image');
            $items[] = [
                'name' => $file,
                'url' => $url,
                'type' => $type,
                'size' => $blob->getProperties()->getContentLength(),
                'contentType' => $mime,
                'thumbUrl' => $type === 'image' ? $thumbUrl : null,
            ];
        }
        return $items;
    }

    public function upload(string $galleryName, array $file): array
    {
        $err = $file['error'] ?? UPLOAD_ERR_OK;
        if ($err !== UPLOAD_ERR_OK) {
            $msg = $this->uploadErrorToMessage($err);
            // Add useful limits context
            $uploadMax = ini_get('upload_max_filesize') ?: 'unknown';
            $postMax = ini_get('post_max_size') ?: 'unknown';
            $msg .= sprintf(' (upload_max_filesize=%s, post_max_size=%s, code=%s)', $uploadMax, $postMax, (string)$err);
            error_log('[Gallerix] Upload failed: ' . $msg);
            throw new \RuntimeException($msg);
        }
        $client = $this->azure->getBlobClient();
        $blobName = rtrim($galleryName, '/') . '/' . $file['name'];
        $options = new CreateBlockBlobOptions();
        if (!empty($file['type'])) $options->setContentType($file['type']);
        $content = fopen($file['tmp_name'], 'rb');
        $client->createBlockBlob($this->dataContainer, $blobName, $content, $options);
        return ['ok' => true, 'name' => $file['name']];
    }

    /**
     * Delete all blobs for a gallery from data and thumbs containers.
     */
    public function deleteGalleryContents(string $galleryName): void
    {
        $client = $this->azure->getBlobClient();
        $thumbsContainer = getenv('AZURE_CONTAINER_THUMBS') ?: 'thumbs';
        $prefix = rtrim($galleryName, '/') . '/';

        foreach ([$this->dataContainer, $thumbsContainer] as $container) {
            try {
                $opts = new ListBlobsOptions();
                $opts->setPrefix($prefix);
                $token = null;
                do {
                    if ($token) { $opts->setContinuationToken($token); }
                    $result = $client->listBlobs($container, $opts);
                    foreach ($result->getBlobs() as $blob) {
                        $name = $blob->getName();
                        try { $client->deleteBlob($container, $name); }
                        catch (\Throwable $e) { error_log('[Gallerix] delete blob failed: ' . $container . '/' . $name . ' - ' . $e->getMessage()); }
                    }
                    $token = $result->getContinuationToken();
                } while ($token);
            } catch (\Throwable $e) {
                // Container may not exist or listing failed; log and continue
                error_log('[Gallerix] list/delete failed for container ' . $container . ': ' . $e->getMessage());
            }
        }
    }

    private function uploadErrorToMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server limit (upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk on the server',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error',
        };
    }

    private function userHasAnyRole(array $user, array $roles): bool
    {
        $userRoles = $user['roles'] ?? [];
        foreach ($userRoles as $r) {
            if (in_array($r, $roles, true)) return true;
        }
        return false;
    }

    /**
     * Returns a preview-sized cover URL for the first image in the gallery, or null if none.
     */
    private function buildGalleryCoverUrl(string $galleryName, ?string $authToken = null): ?string
    {
        try {
            $client = $this->azure->getBlobClient();
            $prefix = rtrim($galleryName, '/') . '/';
            $opts = new ListBlobsOptions();
            $opts->setPrefix($prefix);
            $cont = null;
            do {
                if ($cont) { $opts->setContinuationToken($cont); }
                $result = $client->listBlobs($this->dataContainer, $opts);
                foreach ($result->getBlobs() as $blob) {
                    $name = $blob->getName();
                    if (str_ends_with($name, '/')) continue; // skip folders
                    $file = substr($name, strlen($prefix));
                    if ($file === '' || str_contains($file, '/')) continue; // only direct children
                    $mime = (string)$blob->getProperties()->getContentType();
                    if (str_starts_with($mime, 'image')) {
                        $publicBase = rtrim((string)(getenv('PUBLIC_BASE_URL') ?: ''), '/');
                        $path = 'api/thumb.php?g=' . rawurlencode($galleryName) . '&f=' . rawurlencode($file) . '&s=preview';
                        if (!empty($authToken)) { $path .= '&t=' . rawurlencode($authToken); }
                        return $publicBase ? ($publicBase . $path) : $path;
                    }
                }
                $cont = $result->getContinuationToken();
            } while ($cont);
        } catch (\Throwable $e) {
            // ignore failures and return no cover
        }
        return null;
    }
}
