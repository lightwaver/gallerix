<?php
declare(strict_types=1);

namespace Gallerix;

class ConfigLoader
{
    private AzureClient $azure;
    private string $configContainer;

    public function __construct(AzureClient $azure)
    {
        $this->azure = $azure;
        $this->configContainer = getenv('AZURE_CONTAINER_CONFIG') ?: 'config';
    }

    public function getJson(string $blobName): array
    {
        $client = $this->azure->getBlobClient();
        $content = $client->getBlob($this->configContainer, $blobName)->getContentStream();
        $json = stream_get_contents($content);
        if ($json === false) {
            throw new \RuntimeException("Failed to read blob $blobName");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON in $blobName");
        }
        return $data;
    }

    public function users(): array { return $this->getJson('users.json'); }
    public function roles(): array { return $this->getJson('roles.json'); }
    public function galleries(): array { return $this->getJson('galleries.json'); }
}
