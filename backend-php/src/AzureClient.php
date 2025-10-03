<?php
declare(strict_types=1);

namespace Gallerix;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Internal\Resources;

class AzureClient
{
    private BlobRestProxy $blobClient;

    public function __construct()
    {
        $conn = getenv('AZURE_STORAGE_CONNECTION_STRING');
        $endpoint = getenv('AZURE_STORAGE_BLOB_ENDPOINT');
        if ($conn) {
            $this->blobClient = BlobRestProxy::createBlobService($conn);
        } elseif ($endpoint) {
            $account = getenv('AZURE_STORAGE_ACCOUNT');
            $key = getenv('AZURE_STORAGE_KEY');
            $connStr = sprintf(
                'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;BlobEndpoint=%s',
                $account,
                $key,
                $endpoint
            );
            $this->blobClient = BlobRestProxy::createBlobService($connStr);
        } else {
            throw new \RuntimeException('Azure storage connection not configured');
        }
    }

    public function getBlobClient(): BlobRestProxy
    {
        return $this->blobClient;
    }
}
