<?php
/** Creates one disposable local attachment and offloads it to the test store. */
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/support/LocalStorage.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
add_filter('cloakwp/media-offload/private', static fn(bool $private, int $id): bool => $private || (bool) get_post_meta($id, '_media_offload_test_private', true), 10, 2);
$storage = new \CloakWP\MediaOffload\Tests\LocalStorage('/tmp/cloakwp-offload-http-public-test');
$private = new \CloakWP\MediaOffload\Tests\LocalStorage('/tmp/cloakwp-offload-http-test');
$config = new \CloakWP\MediaOffload\Core\Config($storage, 'https://media.example.test', 'http-test', false, false, $private);
$offloader = new \CloakWP\MediaOffload\Core\Offloader($config);
$image = imagecreatetruecolor(48, 48);
imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 210));
ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
$upload = wp_upload_bits('offload-private-http-' . wp_generate_uuid4() . '.png', null, $bytes);
if ($upload['error']) throw new RuntimeException($upload['error']);
$id = wp_insert_attachment(['post_mime_type' => wp_check_filetype($upload['file'])['type'], 'post_title' => 'temporary private HTTP test', 'post_status' => 'inherit'], $upload['file']);
update_post_meta($id, '_media_offload_test_private', 1);
wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
$state = $offloader->offload($id);
WP_CLI::log(wp_json_encode(['id' => $id, 'file' => $state['main']]));
