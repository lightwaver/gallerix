<?php
declare(strict_types=1);

namespace Gallerix;

use MicrosoftAzure\Storage\Blob\Models\AccessCondition;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

class ConfigLoader
{
    private AzureClient $azure;
    private string $configContainer;
    /** Per-request memo; each config blob is fetched from Azure at most once per request. */
    private array $cache = [];
    /** ETag of each blob as read in this request; writes are conditional on it (optimistic locking). */
    private array $etags = [];

    public function __construct(AzureClient $azure)
    {
        $this->azure = $azure;
        $this->configContainer = getenv('AZURE_CONTAINER_CONFIG') ?: 'config';
    }

    public function getJson(string $blobName): array
    {
        if (isset($this->cache[$blobName])) return $this->cache[$blobName];
        $client = $this->azure->getBlobClient();
        $blob = $client->getBlob($this->configContainer, $blobName);
        $this->etags[$blobName] = $blob->getProperties()->getETag();
        $content = $blob->getContentStream();
        $json = stream_get_contents($content);
        if ($json === false) {
            throw new \RuntimeException("Failed to read blob $blobName");
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid JSON in $blobName");
        }
        return $this->cache[$blobName] = $data;
    }

    public function users(): array { return $this->getJson('users.json'); }
    public function roles(): array { return $this->getJson('roles.json'); }
    public function galleries(): array { return $this->getJson('galleries.json'); }

    /**
     * Writes a config blob only if nobody changed it since we read it in this request.
     * @throws ConfigConflictException when another request wrote in between
     */
    public function putJson(string $blobName, array $data): void
    {
        if (!isset($this->etags[$blobName])) {
            $this->getJson($blobName); // establishes the ETag to write against
        }
        $client = $this->azure->getBlobClient();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Failed to encode JSON for $blobName");
        }
        $opts = new \MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions();
        $opts->setContentType('application/json');
        $opts->setAccessConditions(AccessCondition::ifMatch($this->etags[$blobName]));
        try {
            $result = $client->createBlockBlob($this->configContainer, $blobName, $json, $opts);
        } catch (ServiceException $e) {
            if ($e->getCode() === 412) {
                unset($this->cache[$blobName], $this->etags[$blobName]);
                throw new ConfigConflictException('The configuration was changed by someone else at the same time. Please reload and try again.');
            }
            throw $e;
        }
        $this->etags[$blobName] = $result->getETag();
        $this->cache[$blobName] = $data;
    }

    public function saveUsers(array $users): void { $this->putJson('users.json', $users); }
    public function saveRoles(array $roles): void { $this->putJson('roles.json', $roles); }
    public function saveGalleries(array $gals): void { $this->putJson('galleries.json', $gals); }
}
