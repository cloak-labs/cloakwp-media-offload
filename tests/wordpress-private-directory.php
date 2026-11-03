<?php
/** Run on a disposable local WordPress site with WP-CLI. */
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/support/LocalStorage.php';

use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Tests\LocalStorage;

if (!defined('WP_ENV') || WP_ENV !== 'development' || !defined('WP_DB') || WP_DB !== 'local') {
    throw new RuntimeException('A local WordPress database is required.');
}
$temp = sys_get_temp_dir() . '/cloak-private-' . wp_generate_uuid4();
mkdir($temp, 0700);
mkdir($temp . '/private', 0700);
mkdir($temp . '/private/site', 0700);
$path = $temp . '/private/site/fixture.pdf';
file_put_contents($path, 'synthetic private content');
$id = wp_insert_attachment(['post_mime_type' => 'application/pdf', 'post_title' => 'Synthetic private directory test', 'post_status' => 'private'], 'synthetic/private/fixture.pdf');
$resolved = $path;
$filter = static function (mixed $original, int $attachmentId) use (&$resolved, $id): mixed { return $attachmentId === $id ? $resolved : $original; };
add_filter('cloakwp/media-offload/local-path', $filter, 10, 2);
try {
    $config = new Config(new LocalStorage($temp . '/public'), 'https://media.example.test', 'private-test', false, false, new LocalStorage($temp . '/objects'), $temp . '/private');
    $offloader = new Offloader($config);
    $state = $offloader->offload($id);
    if (empty($state['private']) || $offloader->cleanup($id) !== 1 || file_exists($path)) { throw new RuntimeException('Private offload or cleanup failed.'); }
    if ($offloader->restore($id) !== 1 || file_get_contents($path) !== 'synthetic private content') { throw new RuntimeException('Private restore failed.'); }
    clearstatcache(true, $path);
    if ((fileperms($path) & 0777) !== 0600) { throw new RuntimeException('Private restore permissions are unsafe.'); }
    $scopedConfig = new Config(new LocalStorage($temp . '/public'), 'https://media.example.test', 'private-test', true, false, new LocalStorage($temp . '/objects'), $temp . '/private', [get_current_blog_id()]);
    $scoped = new Offloader($scopedConfig);
    $otherSites = get_sites(['fields' => 'ids', 'number' => 1, 'exclude' => [get_current_blog_id()]]);
    if ($otherSites) {
        switch_to_blog((int) $otherSites[0]);
        try {
            if ($scoped->siteAllowed() || $scoped->eligible($id) || $scoped->state($id) !== null
                || (new \CloakWP\MediaOffload\Plugin\Integration($scoped, $scopedConfig))->schedule($id)) {
                throw new RuntimeException('Site allowlist was bypassed after switching blogs.');
            }
        } finally { restore_current_blog(); }
    }
    $resolved = $temp . '/private/../escape.pdf';
    try { $offloader->restore($id); throw new RuntimeException('Traversal was accepted.'); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'Traversal was accepted.') { throw $error; } }
    symlink(sys_get_temp_dir(), $temp . '/private/link');
    $resolved = $temp . '/private/link/escape.pdf';
    try { $offloader->restore($id); throw new RuntimeException('Symlink was accepted.'); }
    catch (RuntimeException $error) { if ($error->getMessage() === 'Symlink was accepted.') { throw $error; } }
    WP_CLI::success('Protected private directory offload, cleanup, restore and path checks passed.');
} finally {
    remove_filter('cloakwp/media-offload/local-path', $filter, 10);
    wp_delete_attachment($id, true);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($temp);
}
