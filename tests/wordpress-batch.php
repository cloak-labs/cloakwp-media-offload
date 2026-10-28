<?php
/** Local-only test of resumable scan bookkeeping using temporary attachments. */
ini_set('display_errors', '1'); error_reporting(E_ALL);
require_once dirname(__DIR__) . '/vendor/autoload.php';
use CloakWP\MediaOffload\Contracts\Storage;
add_filter('cloakwp/media-offload/private', static fn(bool $private, int $id): bool => $private || (bool) get_post_meta($id, '_media_offload_test_private', true), 10, 2);
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Plugin\Batch;
use CloakWP\MediaOffload\Plugin\Integration;
final class BatchStorage implements Storage {
    public function put(string $key, string $path, string $mime): void {}
    public function inspect(string $key): ?array { return null; }
    public function download(string $key, string $path): void {}
}
function assert_batch(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$old = get_option(Batch::OPTION, null);
if (is_array($old) && ($old['status'] ?? '') === 'running') throw new RuntimeException('Existing scan is running; refusing test.');
$offloader = new Offloader(new Config(new BatchStorage(), 'https://media.example.test', 'test', false));
$integration = new Integration($offloader, new Config(new BatchStorage(), 'https://media.example.test', 'test', false));
$batch = new Batch($offloader, $integration);
$ids = [];
try {
    for ($i = 0; $i < 3; ++$i) { $ids[] = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'temporary batch fixture ' . $i]); }
    update_post_meta($ids[1], '_media_offload_test_private', 1);
    $state = ['id' => wp_generate_uuid4(), 'status' => 'running', 'after' => $ids[0] - 1, 'scanned' => 0, 'queued' => 0, 'excluded' => 0, 'already_current' => 0];
    update_option(Batch::OPTION, $state, false);
    $batch->run();
    $result = get_option(Batch::OPTION);
    assert_batch($result['status'] === 'finished' && $result['scanned'] === 3 && $result['queued'] === 2 && $result['excluded'] === 1, 'Batch counts were wrong');
    assert_batch((bool) wp_next_scheduled('cloakwp_media_offload_attachment', [$ids[0], 0]), 'First attachment was not queued');
    assert_batch(!wp_next_scheduled('cloakwp_media_offload_attachment', [$ids[1], 0]), 'Excluded attachment was queued');
    $result['status'] = 'running'; update_option(Batch::OPTION, $result, false);
    $batch->cancel();
    assert_batch(get_option(Batch::OPTION)['status'] === 'cancelled', 'Cancellation failed');
    $batch->start();
    $resumed = get_option(Batch::OPTION);
    assert_batch($resumed['status'] === 'running' && $resumed['after'] === $result['after'], 'Resume lost the cursor');
    WP_CLI::success('Batch scan, exclusions, cancellation and resume passed.');
} finally {
    wp_clear_scheduled_hook('cloakwp_media_offload_batch');
    foreach ($ids as $id) { wp_clear_scheduled_hook('cloakwp_media_offload_attachment', [$id, 0]); wp_delete_post($id, true); }
    if ($old === null) delete_option(Batch::OPTION); else update_option(Batch::OPTION, $old, false);
}
