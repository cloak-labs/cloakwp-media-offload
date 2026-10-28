<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use CloakWP\MediaOffload\Contracts\Storage;

final class S3Storage implements Storage
{
    public function __construct(private readonly S3Client $client, private readonly string $bucket, private readonly bool $private = false) {}
    public function identity(): string { return (string) $this->client->getEndpoint() . '|' . $this->bucket; }
    public function isPrivate(): bool { return $this->private; }

    public function put(string $key, string $path, string $mime): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) { throw new \RuntimeException('Cannot open file for upload.'); }
        try {
            // Content-addressed revision keys prevent legitimate overwrites.
            $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $stream,
                'ContentType' => $mime, 'CacheControl' => $this->private ? 'private, no-store' : 'public, max-age=31536000, immutable',
                'IfNoneMatch' => '*', 'ContentMD5' => base64_encode(md5_file($path, true)), 'Metadata' => ['sha256' => hash_file('sha256', $path)]]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() !== 412) { throw $e; }
            // Existing objects are checked by the service before publishing.
        } finally { fclose($stream); }
    }

    public function inspect(string $key): ?array
    {
        try { $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]); return ['size' => (int) $result['ContentLength'], 'sha256' => (string) ($result['Metadata']['sha256'] ?? '')]; }
        catch (S3Exception $e) { if ($e->getStatusCode() === 404) { return null; } throw $e; }
    }

    public function download(string $key, string $path): void
    {
        $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $path]);
    }
}
