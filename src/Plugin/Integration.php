<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Plugin;
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;

final class Integration
{
    private const JOB = 'cloakwp_media_offload_attachment';
    private const CLEANUP = 'cloakwp_media_offload_cleanup';
    public function __construct(private readonly Offloader $offloader, private readonly Config $config) {}
    public function register(): void
    {
        add_filter('wp_get_attachment_url', [$this, 'url'], 10, 2);
        add_filter('pre_attachment_url_to_postid', [$this, 'attachmentId'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'srcset'], 10, 5);
        add_filter('get_attached_file', [$this, 'localFile'], 10, 2);
        add_filter('wp_get_original_image_path', [$this, 'localFile'], 10, 2);
        add_filter('image_downsize', [$this, 'privateImage'], 10, 3);
        add_filter('wp_get_original_image_url', [$this, 'originalUrl'], 10, 2);
        add_action(self::JOB, [$this, 'run'], 10, 2);
        add_action(self::CLEANUP, [$this, 'cleanup'], 10, 1);
        add_action('admin_post_cloakwp_media_private', [$this, 'privateDownload']);
        (new Batch($this->offloader, $this))->register();
        if ($this->config->automatic) {
            add_action('add_attachment', [$this, 'schedule']);
            add_filter('wp_update_attachment_metadata', [$this, 'metadata'], 100, 2);
        }
        if (is_admin()) { (new Screen($this->offloader, $this))->register(); }
    }
    public function metadata(mixed $metadata, int $id): mixed { $this->schedule($id); return $metadata; }
    public function schedule(int $id): bool
    {
        if (wp_next_scheduled(self::JOB, [$id, 0])) { return true; }
        return wp_schedule_single_event(time() + 60, self::JOB, [$id, 0]) === true;
    }
    public function run(int $id, int $attempt = 0): void
    {
        if (!$this->offloader->eligible($id)) { return; }
        try {
            $this->offloader->offload($id);
            if ($this->config->removeLocal && !wp_next_scheduled(self::CLEANUP, [$id])) {
                wp_schedule_single_event(time() + 300, self::CLEANUP, [$id]);
            }
        }
        catch (\Throwable $e) {
            if ($attempt < 4) { wp_schedule_single_event(time() + min(3600, 60 * (2 ** $attempt)), self::JOB, [$id, $attempt + 1]); }
        }
    }
    public function cleanup(int $id): void
    {
        if (!$this->config->removeLocal || !$this->offloader->eligible($id)) { return; }
        try { $this->offloader->cleanup($id); }
        catch (\Throwable $e) { update_post_meta($id, Offloader::ERROR, 'Cleanup: ' . $e->getMessage()); }
    }

    /** Rehydrate only known editing operations, never ordinary attachment listings. */
    public function localFile(mixed $path, int $id): mixed
    {
        if (!is_string($path) || is_file($path) || !$this->offloader->state($id)) { return $path; }
        $needsFile = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (in_array($frame['function'] ?? '', ['wp_save_image', 'wp_restore_image', '_load_image_to_edit_path', 'wp_update_image_subsizes', 'edit_media_item'], true)) {
                $needsFile = true; break;
            }
        }
        if (!apply_filters('cloakwp/media-offload/needs-local-file', $needsFile, $id, $path)) { return $path; }
        try { $this->offloader->restore($id); }
        catch (\Throwable $e) { update_post_meta($id, Offloader::ERROR, 'Restore: ' . $e->getMessage()); }
        return $path;
    }
    public function url(string $url, int $id): string
    {
        $state = $this->offloader->state($id);
        if (!$state) { return $this->offloader->isPrivate($id) ? '' : $url; }
        if (!$this->current($state, $id)) {
            return !empty($state['private']) || $this->offloader->isPrivate($id) ? '' : $url;
        }
        return !empty($state['private']) ? $this->privateUrl($id, $state['main']) : $this->objectUrl($state, $state['main']);
    }
    private function current(array $state, int $id): bool
    {
        return $this->offloader->current($id);
    }
    private function privateUrl(int $id, string $name): string
    {
        return add_query_arg(['action' => 'cloakwp_media_private', 'attachment_id' => $id, 'file' => $name], admin_url('admin-post.php'));
    }
    public function originalUrl(string $url, int $id): string
    {
        $state = $this->offloader->state($id);
        if (!$state || empty($state['private']) || !$this->current($state, $id)) {
            return (!empty($state['private']) || $this->offloader->isPrivate($id)) ? '' : $url;
        }
        $meta = wp_get_attachment_metadata($id, true);
        $name = is_array($meta) && !empty($meta['original_image']) ? basename((string) $meta['original_image']) : $state['main'];
        return isset($state['files'][$name]) ? $this->privateUrl($id, $name) : '';
    }
    public function privateImage(mixed $result, int $id, mixed $size): mixed
    {
        if ($result) { return $result; }
        $state = $this->offloader->state($id);
        if (!$state || empty($state['private']) || !$this->current($state, $id) || !wp_attachment_is_image($id)) { return $result; }
        $meta = wp_get_attachment_metadata($id);
        $intermediate = image_get_intermediate_size($id, $size);
        $name = is_array($intermediate) ? $intermediate['file'] : $state['main'];
        if (!isset($state['files'][$name])) { return $result; }
        return [$this->privateUrl($id, $name), (int) ($intermediate['width'] ?? $meta['width'] ?? 0), (int) ($intermediate['height'] ?? $meta['height'] ?? 0), is_array($intermediate)];
    }
    public function privateDownload(): void
    {
        $id = isset($_GET['attachment_id']) ? absint($_GET['attachment_id']) : 0;
        $name = isset($_GET['file']) && is_string($_GET['file']) ? wp_unslash($_GET['file']) : '';
        $state = $this->offloader->state($id);
        $post = get_post($id);
        $capId = $post && $post->post_parent ? (int) $post->post_parent : $id;
        if (!$post || !$state || empty($state['private']) || !$this->current($state, $id)
            || !is_user_logged_in() || !current_user_can('edit_post', $capId)
            || $name === '' || basename($name) !== $name || !isset($state['files'][$name])) {
            wp_die('File not found.', '', ['response' => 404]);
        }
        $temp = tempnam(sys_get_temp_dir(), 'cloakwp-private-');
        if ($temp === false) { wp_die('File unavailable.', '', ['response' => 503]); }
        try {
            $this->offloader->download($id, $name, $temp);
            $type = wp_check_filetype($name);
            $mime = $type['type'] ?: 'application/octet-stream';
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0', true);
            header('Content-Type: ' . $mime);
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: ' . (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' ? 'inline' : 'attachment') . '; filename="' . sanitize_file_name($name) . '"');
            header('Content-Length: ' . filesize($temp));
            readfile($temp);
        } catch (\Throwable $e) {
            update_post_meta($id, Offloader::ERROR, 'Private download: ' . $e->getMessage());
            @unlink($temp);
            wp_die('File unavailable.', '', ['response' => 503]);
        } finally { @unlink($temp); }
        exit;
    }
    private function objectUrl(array $state, string $name): string
    {
        $segments = explode('/', $state['directory'] . '/' . $name);
        return rtrim($state['url'], '/') . '/' . implode('/', array_map('rawurlencode', $segments));
    }
    public function attachmentId(mixed $found, string $url): mixed
    {
        if ($found !== null) { return $found; }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('~/site-[0-9]+/attachment-([0-9]+)/[a-f0-9]{64}/~', $path, $matches)) { return null; }
        $id = (int) $matches[1];
        $state = $this->offloader->state($id);
        if (!$state || !$this->current($state, $id)) { return null; }
        $name = rawurldecode(basename($path));
        if (!isset($state['files'][$name])) { return null; }
        $candidate = preg_split('/[?#]/', $url, 2)[0];
        return hash_equals($this->objectUrl($state, $name), $candidate) ? $id : null;
    }
    public function srcset(array $sources, array $size, string $src, array $meta, int $id): array
    {
        $state = $this->offloader->state($id);
        if (!empty($state['private']) || $this->offloader->isPrivate($id)) { return []; }
        if (!$state || !empty($state['private']) || !$this->current($state, $id)) { return $sources; }
        foreach ($sources as &$source) {
            $name = rawurldecode(basename((string) parse_url($source['url'], PHP_URL_PATH)));
            if (isset($state['files'][$name])) { $source['url'] = $this->objectUrl($state, $name); }
        }
        unset($source);
        return $sources;
    }
}
