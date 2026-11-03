<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Tests;
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Contracts\Storage;
use PHPUnit\Framework\TestCase;
use Aws\S3\S3Client;
use CloakWP\MediaOffload\Storage\S3Storage;
final class ConfigTest extends TestCase
{
    public function testRejectsInsecurePublicUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config($this->createStub(Storage::class), 'http://example.test', 'site/prod');
    }
    public function testRejectsCredentialsInPublicUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config($this->createStub(Storage::class), 'https://user:secret@example.test', 'site/prod');
    }
    public function testRejectsUnsafePrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config($this->createStub(Storage::class), 'https://example.test', '../bad');
    }
    public function testPrivateS3CannotReusePublicBucket(): void
    {
        $client = new S3Client(['version' => 'latest', 'region' => 'auto', 'endpoint' => 'https://s3.example.test', 'credentials' => ['key' => 'key', 'secret' => 'secret']]);
        $this->expectException(\InvalidArgumentException::class);
        new Config(new S3Storage($client, 'same'), 'https://example.test', 'site/prod', true, false, new S3Storage($client, 'same', true));
    }
    public function testRejectsAnEmptySiteAllowlist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config($this->createStub(Storage::class), 'https://example.test', 'site/prod', true, false, null, null, []);
    }
}
