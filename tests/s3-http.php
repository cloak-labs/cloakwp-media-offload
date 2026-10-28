<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
use Aws\S3\S3Client;
use CloakWP\MediaOffload\Storage\S3Storage;
$client = new S3Client([
    'version' => 'latest', 'region' => 'auto', 'endpoint' => getenv('MEDIA_OFFLOAD_TEST_ENDPOINT'),
    'credentials' => ['key' => 'test', 'secret' => 'test'], 'use_path_style_endpoint' => true,
    'http' => ['connect_timeout' => 2, 'timeout' => 5], 'retries' => 0,
]);
$storage = new S3Storage($client, 'test-bucket');
$source = tempnam(sys_get_temp_dir(), 'offload-src-');
$target = tempnam(sys_get_temp_dir(), 'offload-dst-');
try {
    file_put_contents($source, 'real HTTP storage test');
    $storage->put('fixture/hello.txt', $source, 'text/plain');
    $storage->put('fixture/hello.txt', $source, 'text/plain'); // conditional conflict is idempotent
    $remote = $storage->inspect('fixture/hello.txt');
    if ($remote !== ['size' => filesize($source), 'sha256' => hash_file('sha256', $source)]) throw new RuntimeException('Unexpected object metadata');
    $storage->download('fixture/hello.txt', $target);
    if (file_get_contents($target) !== file_get_contents($source)) throw new RuntimeException('Download mismatch');
    if ($storage->inspect('fixture/missing.txt') !== null) throw new RuntimeException('Missing object did not return null');
    echo "S3 adapter HTTP transport passed.\n";
} finally { unlink($source); unlink($target); }
