<?php
declare(strict_types=1);

/** Exercises the upload, read, and recovery paths on a disposable WordPress site. */
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/support/LocalStorage.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Lease;
use CloakWP\MediaOffload\Core\Manifest;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Plugin\Integration;
use CloakWP\MediaOffload\Tests\LocalStorage;

function expect_shared(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function shared_failure(callable $action, string $message): void
{
    try { $action(); }
    catch (RuntimeException $error) {
        expect_shared(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

$root = sys_get_temp_dir() . '/cloakwp-shared-' . wp_generate_uuid4();
expect_shared(mkdir($root, 0700), 'Could not create fixture storage');
$storage = new LocalStorage($root);
$config = new Config($storage, 'https://media.example.test', 'shared/local', verifyPublicDelivery: true);
$offloader = new Offloader($config);
$integration = new Integration($offloader, $config);
$integration->register();

$denyDelivery = true;
$probe = static function (mixed $result, array $args, string $url) use (&$denyDelivery, $root): mixed {
    if (!str_starts_with($url, 'https://media.example.test/')) { return $result; }
    $file = $root . rawurldecode((string) parse_url($url, PHP_URL_PATH));
    clearstatcache(true, $file);
    if ($denyDelivery || !is_file($file)) { return ['response' => ['code' => 404], 'headers' => []]; }
    return ['response' => ['code' => 200], 'headers' => ['content-length' => (string) filesize($file)]];
};
add_filter('pre_http_request', $probe, 10, 3);

$ids = [];
$make = static function (array $upload, int $parent = 0) use (&$ids): int {
    expect_shared(!$upload['error'], 'Fixture upload failed');
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_status' => 'inherit'], $upload['file'], $parent, true);
    expect_shared(is_int($id) && $id > 0, 'Fixture attachment was not created');
    $ids[] = $id;
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
    return $id;
};

try {
    $image = imagecreatetruecolor(600, 400);
    imagefill($image, 0, 0, 0x54898e);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    $name = 'shared-' . wp_generate_uuid4() . '.png';
    $upload = wp_upload_bits($name, null, $bytes);
    $id = $make($upload);
    $localUrl = wp_get_attachment_url($id);
    shared_failure(fn() => $offloader->offload($id), 'Public delivery verification failed');
    expect_shared($offloader->state($id) === null && is_file($upload['file']), 'A delivery failure published or removed the attachment');
    $denyDelivery = false;

    $rejectManifest = static fn($value, $post, $key) => $key === Offloader::META ? false : $value;
    add_filter('update_post_metadata', $rejectManifest, 10, 3);
    try { shared_failure(fn() => $offloader->offload($id), 'Could not save'); }
    finally { remove_filter('update_post_metadata', $rejectManifest, 10); }
    expect_shared(get_post_meta($id, Offloader::ERROR, true) !== '', 'The failed manifest write was not recorded');
    $state = $offloader->offload($id);
    $cloudUrl = wp_get_attachment_url($id);
    expect_shared($cloudUrl !== $localUrl && Manifest::valid($state), 'Successful upload did not publish a valid cloud URL');
    $markup = '<!-- wp:image {"id":' . $id . '} --><figure><img src="' . esc_url($localUrl)
        . '" class="wp-image-' . $id . '" /></figure><!-- /wp:image -->';
    expect_shared(str_contains(do_blocks($markup), $cloudUrl), 'A saved Gutenberg image did not use the public URL');
    $structured = (new \CloakWP\MediaOffload\Plugin\SavedUrls($offloader))
        ->structuredBlock(['name' => 'core/image', 'attrs' => ['id' => $id, 'url' => $localUrl]]);
    expect_shared($structured['attrs']['url'] === $cloudUrl, 'The headless image attributes kept a local URL');

    $key = $state['files'][$state['main']]['key'];
    expect_shared(unlink($root . '/' . $key), 'Could not simulate a lost remote object');
    shared_failure(fn() => $offloader->verify($id, true), 'Remote verification failed');
    add_filter('cloakwp/media-offload/prefer-local', '__return_true');
    expect_shared(wp_get_attachment_url($id) === $localUrl, 'Verified local fallback was not used');
    remove_filter('cloakwp/media-offload/prefer-local', '__return_true');
    expect_shared($offloader->offload($id) === $state && $offloader->verify($id, true) === $state, 'Repair changed the saved URL or did not restore it');

    $legacy = $state;
    $legacy['version'] = 1;
    unset($legacy['relative'], $legacy['backups_hash'], $legacy['storage']);
    update_post_meta($id, Offloader::META, $legacy);
    expect_shared(wp_get_attachment_url($id) === $cloudUrl && $offloader->offload($id) == $state, 'A legacy manifest did not upgrade in place');
    update_post_meta($id, Offloader::META, ['directory' => 'invalid']);
    expect_shared(wp_get_attachment_url($id) === $localUrl, 'A malformed manifest did not fall back to the core URL');
    update_post_meta($id, Offloader::META, $state);

    foreach ($state['files'] as $file => $entry) { unlink(dirname($upload['file']) . '/' . $file); }
    expect_shared(wp_unique_filename(dirname($upload['file']), basename($upload['file'])) !== basename($upload['file']),
        'WordPress offered a filename reserved by the cloud-only attachment');
    $sameName = wp_upload_bits($name, null, $bytes);
    $sameNameId = $make($sameName);
    expect_shared($sameName['file'] !== $upload['file'], 'A cloud-only path was reused');
    expect_shared($offloader->restore($id) === count($state['files']), 'Cloud files could not be restored after a same-name upload');

    $collision = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_status' => 'inherit'], $upload['file']);
    expect_shared($collision > 0, 'Could not simulate an older shared path');
    $ids[] = $collision;
    wp_delete_attachment($collision, true);
    expect_shared(is_file($upload['file']), 'Deleting a duplicate record removed the other attachment’s original');

    $parent = wp_insert_post(['post_title' => 'Private fixture', 'post_status' => 'private']);
    $ids[] = $parent;
    $private = $make(wp_upload_bits('private-' . $name, null, $bytes), $parent);
    expect_shared($offloader->isPrivate($private) && wp_get_attachment_url($private) === false, 'Private parent was exposed');
    shared_failure(fn() => $offloader->offload($private), 'excluded');

    $denyQueue = static fn($value, $event) => $event->hook === 'cloakwp_media_offload_attachment' ? false : $value;
    add_filter('pre_schedule_event', $denyQueue, 10, 2);
    wp_clear_scheduled_hook('cloakwp_media_offload_attachment', [$sameNameId, 0]);
    try { expect_shared(!$integration->schedule($sameNameId), 'Failed schedule reported success'); }
    finally { remove_filter('pre_schedule_event', $denyQueue, 10); }
    expect_shared(get_post_meta($sameNameId, Offloader::PENDING, true) && get_post_meta($sameNameId, Offloader::ERROR, true), 'Failed scheduling lost its recovery record');
    update_post_meta($sameNameId, Offloader::PENDING, '000000000000:fixture');
    $integration->recover();
    expect_shared((bool) wp_next_scheduled('cloakwp_media_offload_attachment', [$sameNameId, 0]), 'Queue recovery did not reschedule the missing job');
    $integration->run($sameNameId);
    expect_shared($offloader->current($sameNameId) && !get_post_meta($sameNameId, Offloader::PENDING, true), 'Queue recovery did not publish the missing job');

    $lease = Lease::acquire('fixture');
    expect_shared($lease !== null, 'The first worker could not acquire its lock');
    try { expect_shared(Lease::acquire('fixture') === null, 'A second worker acquired the same lock'); }
    finally { $lease->release(); }
    WP_CLI::success('Shared media delivery, rollback, recovery, privacy, and path ownership passed.');
} finally {
    remove_filter('pre_http_request', $probe, 10);
    foreach (array_reverse($ids) as $id) {
        for ($attempt = 0; $attempt <= 4; ++$attempt) { wp_clear_scheduled_hook('cloakwp_media_offload_attachment', [$id, $attempt]); }
        wp_delete_post($id, true);
    }
    wp_clear_scheduled_hook('cloakwp_media_offload_recover');
    $contents = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($contents as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
    rmdir($root);
}
