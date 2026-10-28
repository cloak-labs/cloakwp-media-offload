<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Tests;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use CloakWP\MediaOffload\Storage\S3Storage;
use PHPUnit\Framework\TestCase;

final class S3StorageTest extends TestCase
{
    public function testUploadIncludesConditionalWriteAndChecksums(): void
    {
        $mock = new MockHandler([new Result([]), new Result(['ContentLength' => 3, 'Metadata' => ['sha256' => hash('sha256', 'abc')]])]);
        $client = new S3Client(['version' => 'latest', 'region' => 'auto', 'endpoint' => 'https://example.invalid', 'credentials' => ['key' => 'key', 'secret' => 'secret'], 'handler' => $mock]);
        $storage = new S3Storage($client, 'media');
        $path = tempnam(sys_get_temp_dir(), 'offload-test-');
        file_put_contents($path, 'abc');
        try {
            $storage->put('test/file.png', $path, 'image/png');
            $write = $mock->getLastCommand();
            self::assertSame('*', $write['IfNoneMatch']);
            self::assertSame(base64_encode(md5('abc', true)), $write['ContentMD5']);
            self::assertSame(hash('sha256', 'abc'), $write['Metadata']['sha256']);
            self::assertSame(['size' => 3, 'sha256' => hash('sha256', 'abc')], $storage->inspect('test/file.png'));
            $commands = $mock->getLastCommand();
            self::assertSame('HeadObject', $commands->getName());
        } finally { unlink($path); }
    }
}
