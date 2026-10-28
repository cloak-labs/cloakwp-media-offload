<?php
/** Run with WP-CLI on a disposable/local WordPress site. */
ini_set('display_errors', '1'); error_reporting(E_ALL);
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
use CloakWP\MediaOffload\Contracts\Storage;
add_filter('cloakwp/media-offload/private', static fn(bool $private, int $id): bool => $private || (bool) get_post_meta($id, '_media_offload_test_private', true), 10, 2);
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Plugin\Integration;

final class FakeStorage implements Storage {
    public array $objects = [];
    public bool $bad = false;
    public bool $corrupt = false;
    public function put(string $key, string $path, string $mime): void { $this->objects[$key] = file_get_contents($path); }
    public function inspect(string $key): ?array { if (!isset($this->objects[$key])) return null; return ['size' => strlen($this->objects[$key]), 'sha256' => $this->bad ? 'invalid' : hash('sha256', $this->objects[$key])]; }
    public function download(string $key, string $path): void { file_put_contents($path, $this->corrupt ? 'corrupt' : $this->objects[$key]); }
}
function check_offload(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$storage = new FakeStorage();
$privateStorage = new FakeStorage();
$config = new Config($storage, 'https://media.example.test', 'integration/test', true, true, $privateStorage);
$offloader = new Offloader($config);
(new Integration($offloader, $config))->register();
$image = imagecreatetruecolor(600, 400);
ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
$upload = wp_upload_bits('cloakwp-offload-test-測試-' . wp_generate_uuid4() . '.png', null, $bytes);
if ($upload['error']) { throw new RuntimeException($upload['error']); }
$id = wp_insert_attachment(['post_mime_type' => wp_check_filetype($upload['file'])['type'], 'post_title' => 'temporary offload test', 'post_status' => 'inherit'], $upload['file']);
try {
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
    check_offload((bool) wp_next_scheduled('cloakwp_media_offload_attachment', [$id, 0]), 'Automatic upload job was not scheduled');
    $localUrl = wp_get_attachment_url($id);
    $storage->bad = true;
    try { $offloader->offload($id); throw new RuntimeException('Verification failure was accepted'); } catch (RuntimeException $e) { if ($e->getMessage() === 'Verification failure was accepted') throw $e; }
    check_offload($offloader->state($id) === null, 'Failed upload published state');
    check_offload(wp_get_attachment_url($id) === $localUrl, 'Failed upload changed URL');
    $storage->bad = false;
    do_action('cloakwp_media_offload_attachment', $id, 0);
    $state = $offloader->state($id);
    check_offload(is_array($state), 'Background job did not publish attachment');
    check_offload((bool) wp_next_scheduled('cloakwp_media_offload_cleanup', [$id]), 'Cleanup job was not scheduled');
    check_offload(count($state['files']) >= 2, 'Expected generated sizes');
    check_offload(str_starts_with(wp_get_attachment_url($id), 'https://media.example.test/'), 'Full URL not offloaded');
    check_offload(attachment_url_to_postid(wp_get_attachment_url($id)) === $id, 'Offloaded URL did not resolve to attachment');
    check_offload(attachment_url_to_postid(str_replace('media.example.test', 'other.example.test', wp_get_attachment_url($id))) === 0, 'Unrelated host resolved as an attachment');
    $thumb = wp_get_attachment_image_src($id, 'thumbnail');
    check_offload(is_array($thumb) && str_starts_with($thumb[0], 'https://media.example.test/'), 'Thumbnail URL not offloaded');
    $srcset = wp_get_attachment_image_srcset($id, 'medium');
    check_offload(is_string($srcset) && str_contains($srcset, 'media.example.test') && !str_contains($srcset, (string) wp_parse_url(home_url(), PHP_URL_HOST)), 'srcset was not offloaded');
    check_offload($offloader->offload($id)['directory'] === $state['directory'], 'Retry changed immutable key');
    $meta = wp_get_attachment_metadata($id, true);
    $changed = $meta; $changed['width'] = (int) $meta['width'] - 1;
    wp_update_attachment_metadata($id, $changed);
    check_offload(wp_get_attachment_url($id) === $localUrl, 'Changed metadata served an obsolete revision');
    wp_update_attachment_metadata($id, $meta);
    check_offload(str_starts_with(wp_get_attachment_url($id), 'https://media.example.test/'), 'Original revision was not restored');
    $main = $upload['file']; $original = file_get_contents($main);
    $files = $offloader->inventory($id);
    $storage->bad = true;
    try { $offloader->cleanup($id); throw new RuntimeException('Bad remote cleanup was accepted'); } catch (RuntimeException $e) { if ($e->getMessage() === 'Bad remote cleanup was accepted') throw $e; }
    check_offload(is_file($main), 'Failed cleanup removed a local file');
    $storage->bad = false;
    $storage->corrupt = true;
    try { $offloader->cleanup($id); throw new RuntimeException('Corrupt remote cleanup was accepted'); } catch (RuntimeException $e) { if ($e->getMessage() === 'Corrupt remote cleanup was accepted') throw $e; }
    check_offload(is_file($main), 'Corrupt remote cleanup removed local file');
    $storage->corrupt = false;
    $copy = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'temporary shared reference']);
    update_post_meta($copy, '_wp_attached_file', get_post_meta($id, '_wp_attached_file', true));
    try { $offloader->cleanup($id); throw new RuntimeException('Shared file cleanup was accepted'); } catch (RuntimeException $e) { if ($e->getMessage() === 'Shared file cleanup was accepted') throw $e; }
    wp_delete_post($copy, true);
    check_offload($offloader->cleanup($id) === count($files), 'Verified cleanup did not remove all files');
    check_offload(!is_file($main), 'Cleanup left main file behind');
    $storage->corrupt = true;
    try { $offloader->restore($id); throw new RuntimeException('Corrupt restore was accepted'); } catch (RuntimeException $e) { if ($e->getMessage() === 'Corrupt restore was accepted') throw $e; }
    check_offload(!is_file($main), 'Corrupt restore wrote the main file');
    $storage->corrupt = false;
    wp_update_image_subsizes($id);
    check_offload(is_file($main) && file_get_contents($main) === $original, 'Regeneration did not restore the file');
    file_put_contents($main, 'different');
    try { $offloader->restore($id); throw new RuntimeException('Overwrite was accepted'); } catch (RuntimeException $e) { if ($e->getMessage() === 'Overwrite was accepted') throw $e; }
    file_put_contents($main, $original);
    update_post_meta($id, '_media_offload_test_private', 1);
    check_offload(wp_get_attachment_url($id) === false, 'Changed visibility exposed a stale public URL');
    $default = new Offloader(new Config($storage, 'https://media.example.test', 'integration/test'));
    check_offload(!$default->eligible($id), 'Sensitive attachment was not excluded by default');
    check_offload($offloader->eligible($id) && $offloader->isPrivate($id), 'Private attachment classification failed');
    try { $offloader->offload($id); throw new RuntimeException('Implicit visibility change was accepted'); } catch (RuntimeException $e) { if ($e->getMessage() === 'Implicit visibility change was accepted') throw $e; }
    $private = $offloader->offload($id, true);
    check_offload(!empty($private['private']) && count($privateStorage->objects) > 0, 'Private files were not sent to private storage');
    check_offload(str_contains(wp_get_attachment_url($id), 'action=cloakwp_media_private'), 'Private file URL does not use authenticated delivery');
    check_offload(str_contains(wp_get_attachment_image_src($id, 'thumbnail')[0], 'action=cloakwp_media_private'), 'Private thumbnail URL does not use authenticated delivery');
    check_offload($offloader->legacyUrls($id, 'https://old.test/uploads') === [], 'Private URLs leaked into public URL map');
    delete_post_meta($id, '_media_offload_test_private');
    check_offload(wp_get_attachment_url($id) === false, 'Changed visibility exposed a private file URL');
    $public = $offloader->offload($id, true);
    $map = $offloader->legacyUrls($id, 'https://old.test/uploads');
    check_offload(count($map) === count($public['files']), 'Legacy URL map is incomplete');
    $promoted = $offloader->promote($id, 'integration/production');
    check_offload(str_starts_with($promoted['directory'], 'integration/production/'), 'Promotion did not use the target prefix');
    $offloader->cleanup($id);
    require_once ABSPATH . 'wp-admin/includes/image-edit.php';
    $requestBefore = $_REQUEST;
    try {
        $_REQUEST = ['do' => 'scale', 'fwidth' => 300, 'fheight' => 200, 'target' => 'all'];
        $edit = wp_save_image($id);
        check_offload(empty($edit->error), 'WordPress image editing failed after cleanup: ' . ($edit->error ?? ''));
        $edited = $offloader->offload($id);
        check_offload($edited['main'] !== $promoted['main'], 'Edited image did not create a new revision');
    } finally { $_REQUEST = $requestBefore; }
    WP_CLI::success('Offload, URL lookup, cleanup, recovery, privacy, migration map and promotion checks passed.');
} finally {
    for ($attempt = 0; $attempt <= 4; ++$attempt) { wp_clear_scheduled_hook('cloakwp_media_offload_attachment', [$id, $attempt]); }
    wp_clear_scheduled_hook('cloakwp_media_offload_cleanup', [$id]);
    wp_delete_attachment($id, true);
    if (is_file($upload['file'])) unlink($upload['file']);
}
