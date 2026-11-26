<?php
/** Opt-in local-only public R2/CDN test. Credentials are read from standard input. */
set_exception_handler(static function (Throwable $error): void {
    $status = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : null;
    $code = method_exists($error, 'getAwsErrorCode') ? $error->getAwsErrorCode() : null;
    if (get_class($error) === RuntimeException::class) { WP_CLI::log($error->getMessage()); }
    WP_CLI::error('Public R2 test failed (' . get_class($error) . ', status ' . ($status ?? 'unknown') . ', code ' . ($code ?? 'unknown') . ').');
});
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

use Aws\S3\S3Client;
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Plugin\Integration;
use CloakWP\MediaOffload\Storage\S3Storage;

if (!defined('WP_ENV') || WP_ENV !== 'development' || !defined('WP_DB') || WP_DB !== 'local') {
    throw new RuntimeException('A local WordPress database is required.');
}
$config = json_decode(stream_get_contents(STDIN), true);
$url = is_array($config) ? ($config['url'] ?? '') : '';
if (!is_array($config) || !preg_match('~^https://[a-f0-9]{32}\.r2\.cloudflarestorage\.com$~', $config['endpoint'] ?? '')
    || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
    || empty($config['bucket']) || empty($config['key']) || empty($config['secret'])) {
    throw new RuntimeException('Public R2 test configuration is invalid.');
}
$url = rtrim($url, '/');
$client = new S3Client(['version' => 'latest', 'region' => 'auto', 'endpoint' => $config['endpoint'], 'use_path_style_endpoint' => true,
    'credentials' => ['key' => $config['key'], 'secret' => $config['secret']]]);
$prefix = 'media/cloakwp-synthetic-test/' . wp_generate_uuid4();
$storage = new S3Storage($client, $config['bucket']);
$settings = new Config($storage, $config['url'], $prefix, false, false, verifyPublicDelivery: true);
$offloader = new Offloader($settings);
(new Integration($offloader, $settings))->register();
$id = 0;
$upload = null;
try {
    $image = imagecreatetruecolor(400, 300);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 210));
    ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
    $upload = wp_upload_bits('synthetic-public-r2-' . wp_generate_uuid4() . '.png', null, $bytes);
    if ($upload['error']) { throw new RuntimeException('Cannot create synthetic image.'); }
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Synthetic public R2 test'], $upload['file']);
    if (!$id || is_wp_error($id)) { $id = 0; throw new RuntimeException('Cannot register synthetic image.'); }
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
    $state = $offloader->offload($id);
    $url = wp_get_attachment_url($id);
    if (!is_string($url) || !str_starts_with($url, $settings->publicUrl . '/' . $prefix . '/')) { throw new RuntimeException('WordPress did not return the expected public URL.'); }
    $response = wp_remote_get($url, ['timeout' => 30, 'redirection' => 0]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { throw new RuntimeException('Public media domain did not serve the test image.'); }
    if (hash('sha256', wp_remote_retrieve_body($response)) !== $state['files'][$state['main']]['sha256']) { throw new RuntimeException('Public media bytes did not match.'); }
    $cache = (string) wp_remote_retrieve_header($response, 'cache-control');
    if (!str_contains($cache, 'immutable') || !str_contains($cache, 'max-age=31536000')) { throw new RuntimeException('Public media cache headers were not preserved.'); }
    if (attachment_url_to_postid($url) !== $id) { throw new RuntimeException('Public URL did not resolve to the attachment.'); }
    $sizes = wp_get_attachment_image_src($id, 'thumbnail');
    if (!is_array($sizes) || !str_starts_with($sizes[0], $settings->publicUrl . '/')) { throw new RuntimeException('Public thumbnail URL was not offloaded.'); }
    if (!empty($config['next_origin'])) {
        $probe = add_query_arg(['url' => $url, 'w' => 640, 'q' => 75], rtrim($config['next_origin'], '/') . '/_next/image');
        $optimized = wp_remote_get($probe, ['timeout' => 30, 'redirection' => 0]);
        if (is_wp_error($optimized) || wp_remote_retrieve_response_code($optimized) !== 200
            || !str_starts_with((string) wp_remote_retrieve_header($optimized, 'content-type'), 'image/')) {
            throw new RuntimeException('Next.js did not optimize the public test image.');
        }
        WP_CLI::log('Next.js optimized the synthetic public R2 image.');
    }
    WP_CLI::success('Public WordPress image, R2 upload, CDN delivery, cache headers and URL lookup passed.');
} finally {
    if ($id) { wp_delete_attachment($id, true); }
    if (is_array($upload) && !empty($upload['file']) && is_file($upload['file'])) { @unlink($upload['file']); }
    try {
        $objects = $client->listObjectsV2(['Bucket' => $config['bucket'], 'Prefix' => $prefix . '/']);
        if (!empty($objects['IsTruncated'])) { throw new RuntimeException('Synthetic object listing was truncated.'); }
        foreach (($objects['Contents'] ?? []) as $object) {
            if (str_starts_with($object['Key'], $prefix . '/')) { $client->deleteObject(['Bucket' => $config['bucket'], 'Key' => $object['Key']]); }
        }
    } catch (Throwable $error) { WP_CLI::warning('Could not remove synthetic public objects under ' . $prefix); }
}
