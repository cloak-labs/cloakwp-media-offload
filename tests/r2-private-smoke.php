<?php
/** Opt-in live R2 smoke test. Run with WP-CLI on a local, disposable site. */
set_exception_handler(static function (Throwable $error): void {
    $status = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : null;
    $awsCode = method_exists($error, 'getAwsErrorCode') ? $error->getAwsErrorCode() : null;
    $awsMessage = method_exists($error, 'getAwsErrorMessage') ? $error->getAwsErrorMessage() : null;
    if ($awsMessage) { WP_CLI::log('R2 reported: ' . $awsMessage); }
    if (get_class($error) === RuntimeException::class) { WP_CLI::log($error->getMessage()); }
    WP_CLI::error('R2 smoke test failed (' . get_class($error) . ', status ' . ($status ?? 'unknown') . ', code ' . ($awsCode ?? 'unknown') . ').');
});
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Aws\S3\S3Client;
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Plugin\Integration;
use CloakWP\MediaOffload\Storage\S3Storage;

if (!defined('WP_ENV') || WP_ENV !== 'development' || !defined('WP_DB') || WP_DB !== 'local') {
    throw new RuntimeException('This smoke test requires a local WordPress database.');
}
$endpoint = getenv('R2_TEST_ENDPOINT');
$bucket = getenv('R2_TEST_PRIVATE_BUCKET');
$keyId = getenv('R2_PRIVATE_ACCESS_KEY_ID');
$secret = getenv('R2_PRIVATE_SECRET_ACCESS_KEY');
if (!$endpoint || !$bucket || !$keyId || !$secret || !preg_match('~^https://[a-f0-9]{32}\.r2\.cloudflarestorage\.com$~', $endpoint)) {
    throw new RuntimeException('R2 test configuration is missing or invalid.');
}
$client = new S3Client([
    'version' => 'latest', 'region' => 'auto', 'endpoint' => $endpoint,
    'credentials' => ['key' => $keyId, 'secret' => $secret],
]);
$storage = new S3Storage($client, $bucket, true);
$prefix = 'cloakwp-smoke-test/' . wp_generate_uuid4();
$object = $prefix . '/fixture.txt';
$source = tempnam(sys_get_temp_dir(), 'cloakwp-r2-source-');
$target = tempnam(sys_get_temp_dir(), 'cloakwp-r2-target-');
if ($source === false || $target === false) { throw new RuntimeException('Cannot create temporary files.'); }
$bytes = 'Synthetic private R2 test ' . wp_generate_uuid4();
$attempted = false;
$id = 0;
$attachmentState = null;
try {
    file_put_contents($source, $bytes);
    $attempted = true;
    $storage->put($object, $source, 'text/plain');
    $info = $storage->inspect($object);
    if (!$info || $info['size'] !== strlen($bytes) || $info['sha256'] !== hash('sha256', $bytes)) {
        throw new RuntimeException('R2 object metadata verification failed.');
    }
    $storage->download($object, $target);
    if (file_get_contents($target) !== $bytes) { throw new RuntimeException('R2 object download did not match.'); }
    file_put_contents($source, 'A different synthetic revision');
    $storage->put($object, $source, 'text/plain');
    $storage->download($object, $target);
    if (file_get_contents($target) !== $bytes) { throw new RuntimeException('The conditional write replaced an existing object.'); }
    $url = $endpoint . '/' . rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $object)));
    $response = wp_remote_get($url, ['timeout' => 15, 'redirection' => 0]);
    if (is_wp_error($response)) { throw new RuntimeException('Anonymous object access test failed to reach R2.'); }
    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if (preg_match('~<Code>([A-Za-z0-9]+)</Code>~', $body, $match)) { WP_CLI::log('Anonymous S3 response: HTTP ' . $status . ', ' . $match[1] . '.'); }
    if ($status < 400 || $status > 499) { throw new RuntimeException('The anonymous S3 API request did not return a client error.'); }
    WP_CLI::log('Private adapter put, conditional write, metadata and download passed; the anonymous S3 API request returned a client error.');
    WP_CLI::log('Check separately that the bucket has no public custom domain or r2.dev endpoint.');

    require_once __DIR__ . '/support/LocalStorage.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $public = new \CloakWP\MediaOffload\Tests\LocalStorage(sys_get_temp_dir() . '/cloakwp-smoke-unused-public');
    $config = new Config($public, 'https://media.example.test', $prefix, false, false, $storage);
    $offloader = new Offloader($config);
    (new Integration($offloader, $config))->register();
    add_filter('cloakwp/media-offload/private', static fn(bool $private, int $attachmentId): bool => $private || (bool) get_post_meta($attachmentId, '_r2_smoke_private', true), 10, 2);
    $image = imagecreatetruecolor(48, 48);
    ob_start(); imagepng($image); $imageBytes = ob_get_clean(); imagedestroy($image);
    $upload = wp_upload_bits('synthetic-r2-private-' . wp_generate_uuid4() . '.png', null, $imageBytes);
    if ($upload['error']) { throw new RuntimeException('Cannot create synthetic attachment.'); }
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Synthetic private R2 test', 'post_status' => 'inherit'], $upload['file']);
    if (!$id || is_wp_error($id)) { $id = 0; @unlink($upload['file']); throw new RuntimeException('Cannot register synthetic attachment.'); }
    update_post_meta($id, '_r2_smoke_private', 1);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
    $expectedHash = hash_file('sha256', $upload['file']);
    $attachmentState = $offloader->offload($id);
    if (empty($attachmentState['private']) || !str_contains((string) wp_get_attachment_url($id), 'action=cloakwp_media_private')) {
        throw new RuntimeException('Synthetic attachment did not receive a private URL.');
    }
    $offloader->download($id, $attachmentState['main'], $target);
    if (hash_file('sha256', $target) !== $expectedHash) { throw new RuntimeException('Private attachment download did not match.'); }
    $offloader->cleanup($id);
    if (file_exists($upload['file'])) { throw new RuntimeException('Verified local cleanup failed.'); }
    $offloader->restore($id);
    if (hash_file('sha256', $upload['file']) !== $expectedHash) { throw new RuntimeException('Verified private restore failed.'); }
    WP_CLI::log('WordPress private attachment offload, authenticated URL generation, cleanup and restore passed.');
} finally {
    if ($id) { wp_delete_attachment($id, true); }
    if ($id) {
        try {
            $testPrefix = $prefix . '/site-';
            $objects = $client->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $testPrefix]);
            if (!empty($objects['IsTruncated'])) { throw new RuntimeException('Synthetic object listing was truncated.'); }
            foreach (($objects['Contents'] ?? []) as $entry) {
                if (str_starts_with($entry['Key'], $testPrefix)) { $client->deleteObject(['Bucket' => $bucket, 'Key' => $entry['Key']]); }
            }
        } catch (Throwable $e) { WP_CLI::warning('Could not clean the synthetic attachment prefix: ' . $prefix); }
    }
    @unlink($source);
    @unlink($target);
    if ($attempted) {
        try { $client->deleteObject(['Bucket' => $bucket, 'Key' => $object]); }
        catch (Throwable $e) { WP_CLI::warning('Could not delete the synthetic test object: ' . $object); }
    }
}
