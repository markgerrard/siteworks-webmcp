<?php

namespace App\Services\Site\SiteClone;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

class SiteCloneSpacesCopier
{
    /**
     * @return list<string>
     */
    public function listKeys(string $prefix): array
    {
        return Storage::disk('s3')->allFiles($prefix);
    }

    public function copyObject(string $oldKey, string $newKey): ?string
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = Storage::disk('s3');
        /** @var S3Client $client */
        $client = $disk->getClient();
        $bucket = config('filesystems.disks.s3.bucket');

        try {
            $client->copyObject([
                'Bucket' => $bucket,
                'CopySource' => rawurlencode("{$bucket}/{$oldKey}"),
                'Key' => $newKey,
                'ACL' => 'public-read',
                'MetadataDirective' => 'COPY',
            ]);
        } catch (AwsException $e) {
            return trim($e->getAwsErrorCode().' '.$e->getAwsErrorMessage());
        }

        return null;
    }

    public function deleteObject(string $key): ?string
    {
        /** @var AwsS3V3Adapter $disk */
        $disk = Storage::disk('s3');
        /** @var S3Client $client */
        $client = $disk->getClient();

        try {
            $client->deleteObject([
                'Bucket' => config('filesystems.disks.s3.bucket'),
                'Key' => $key,
            ]);
        } catch (AwsException $e) {
            return trim($e->getAwsErrorCode().' '.$e->getAwsErrorMessage());
        }

        return null;
    }
}
