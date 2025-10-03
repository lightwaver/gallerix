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
                    'description' => $gal['description'] ?? ''
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

    public function listItems(string $galleryName): array
    {
        $client = $this->azure->getBlobClient();
        $prefix = rtrim($galleryName, '/') . '/';
        $opts = new ListBlobsOptions();
        $opts->setPrefix($prefix);
        $items = [];
        $result = $client->listBlobs($this->dataContainer, $opts);
        foreach ($result->getBlobs() as $blob) {
            $name = $blob->getName();
            if (str_ends_with($name, '/')) continue; // skip folders
            $file = substr($name, strlen($prefix));
            if ($file === '' || str_contains($file, '/')) continue; // only direct children
            $url = $blob->getUrl();
            $mime = $blob->getProperties()->getContentType();
            $type = str_starts_with((string)$mime, 'video') ? 'video' : 'image';
            $items[] = [
                'name' => $file,
                'url' => $url,
                'type' => $type,
                'size' => $blob->getProperties()->getContentLength(),
                'contentType' => $mime,
            ];
        }
        return $items;
    }

    public function upload(string $galleryName, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . ($file['error'] ?? 'unknown'));
        }
        $client = $this->azure->getBlobClient();
        $blobName = rtrim($galleryName, '/') . '/' . $file['name'];
        $options = new CreateBlockBlobOptions();
        if (!empty($file['type'])) $options->setContentType($file['type']);
        $content = fopen($file['tmp_name'], 'rb');
        $client->createBlockBlob($this->dataContainer, $blobName, $content, $options);
        return ['ok' => true, 'name' => $file['name']];
    }

    private function userHasAnyRole(array $user, array $roles): bool
    {
        $userRoles = $user['roles'] ?? [];
        foreach ($userRoles as $r) {
            if (in_array($r, $roles, true)) return true;
        }
        return false;
    }
}
