<?php
/** Run with WP-CLI on a disposable/local WordPress site. */
ini_set('display_errors', '1'); error_reporting(E_ALL);
require_once dirname(__DIR__) . '/vendor/autoload.php';
global $wpdb;
use CloakWP\MediaOffload\Contracts\Storage;
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Plugin\Command;
final class MigrationStorage implements Storage {
    public function put(string $key, string $path, string $mime): void {}
    public function inspect(string $key): ?array { return null; }
    public function download(string $key, string $path): void {}
}
function assert_migration(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$old = 'https://old.example.test/uploads/a.jpg';
$new = 'https://media.example.test/new/a.jpg';
$map = tempnam(sys_get_temp_dir(), 'offload-map-');
file_put_contents($map, wp_json_encode([$old => $new]));
$post = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'temporary migration test', 'post_content' => '<img src="' . $old . '">']);
$metaId = add_post_meta($post, 'media_test', ['url' => $old, 'json' => wp_json_encode(['src' => $old])]);
$protectedId = add_post_meta($post, '_media_test', $old);
$objectId = add_post_meta($post, 'object_test', (object) ['url' => $old]);
$optionName = 'offload_url_fixture_' . str_replace('-', '', wp_generate_uuid4());
add_option($optionName, ['url' => $old, 'json' => wp_json_encode(['src' => $old])], '', false);
$optionId = (int) $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $optionName));
$command = new Command(new Offloader(new Config(new MigrationStorage(), 'https://media.example.test', 'test')));
try {
    $command->rewrite_posts([], ['map' => $map, 'after' => $post - 1, 'limit' => 1]);
    assert_migration(str_contains(get_post($post)->post_content, $old), 'Dry run modified post');
    $command->rewrite_posts([], ['map' => $map, 'after' => $post - 1, 'limit' => 1, 'apply' => true]);
    assert_migration(str_contains(get_post($post)->post_content, $new), 'Post was not migrated');
    $command->rewrite_meta([], ['map' => $map, 'after' => $metaId - 1, 'limit' => 2]);
    assert_migration(get_post_meta($post, 'media_test', true)['url'] === $old, 'Dry run modified metadata');
    $command->rewrite_meta([], ['map' => $map, 'after' => $metaId - 1, 'limit' => 2, 'apply' => true]);
    $data = get_post_meta($post, 'media_test', true);
    assert_migration($data['url'] === $new && json_decode($data['json'], true)['src'] === $new, 'Serialized metadata was not migrated');
    assert_migration(get_post_meta($post, '_media_test', true) === $old, 'Protected metadata was modified');
    assert_migration(get_post_meta($post, 'object_test', true)->url === $old, 'Object metadata was modified');
    $command->rewrite_options([], ['map' => $map, 'after' => $optionId - 1, 'limit' => 1]);
    assert_migration(get_option($optionName)['url'] === $old, 'Dry run modified an option');
    $command->rewrite_options([], ['map' => $map, 'after' => $optionId - 1, 'limit' => 1, 'apply' => true]);
    $option = get_option($optionName);
    assert_migration($option['url'] === $new && json_decode($option['json'], true)['src'] === $new, 'Serialized option was not migrated');
    WP_CLI::success('Dry-run and applied post and serialized metadata migrations passed.');
} finally { delete_option($optionName); wp_delete_post($post, true); unlink($map); }
