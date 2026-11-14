<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Plugin;
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Manifest;
use CloakWP\MediaOffload\Core\Offloader;

final class Integration
{
    private const JOB = 'cloakwp_media_offload_attachment';
    private const CLEANUP = 'cloakwp_media_offload_cleanup';
    private const RECOVER = 'cloakwp_media_offload_recover';
    private array $preparedForEdit = [];
    public function __construct(private readonly Offloader $offloader, private readonly Config $config) {}
    public function register(): void
    {
        add_filter('cloakwp/media-offload/offloader', [$this, 'offloader']);
        add_filter('wp_get_attachment_url', [$this, 'url'], 10, 2);
        add_filter('pre_attachment_url_to_postid', [$this, 'attachmentId'], 10, 2);
        add_filter('wp_calculate_image_srcset', [$this, 'srcset'], 10, 5);
        add_filter('get_attached_file', [$this, 'localFile'], 10, 2);
        add_filter('wp_get_original_image_path', [$this, 'localFile'], 10, 2);
        add_filter('cloakwp/image-transformations/attachment-source', [$this, 'transformationFile'], 10, 2);
        add_filter('image_downsize', [$this, 'privateImage'], 10, 3);
        add_filter('wp_get_original_image_url', [$this, 'originalUrl'], 10, 2);
        add_action(self::JOB, [$this, 'run'], 10, 2);
        add_action(self::CLEANUP, [$this, 'cleanup'], 10, 1);
        add_action(self::RECOVER, [$this, 'recover']);
        add_action('admin_post_cloakwp_media_private', [$this, 'privateDownload']);
        $batch = new Batch($this->offloader, $this);
        $batch->register();
        add_action(self::RECOVER, [$batch, 'recover']);
        (new LocalFiles($this->offloader))->register();
        (new SavedUrls($this->offloader))->register();
        if ($this->config->automatic && $this->config->writeEnabled) {
            add_action('add_attachment', [$this, 'schedule']);
            add_action('added_post_meta', [$this, 'metadata'], 100, 3);
            add_action('updated_post_meta', [$this, 'metadata'], 100, 3);
            add_action('deleted_post_meta', [$this, 'metadata'], 100, 3);
            add_action('wp_after_insert_post', [$this, 'attachmentChanged'], 10, 4);
        }
        if ($this->offloader->writesAllowed() && !wp_next_scheduled(self::RECOVER)
            && !wp_schedule_event(time() + 60, 'hourly', self::RECOVER) && !wp_next_scheduled(self::RECOVER)) {
            error_log('Media offload could not schedule queue recovery for site ' . get_current_blog_id() . '.');
        }
        if (is_admin()) { (new Screen($this->offloader, $this))->register(); }
    }
    public function offloader(mixed $existing): mixed { return $this->offloader->siteAllowed() ? ($existing ?? $this->offloader) : $existing; }
    public function metadata(mixed $metaId, int $id, string $key): void
    {
        if (in_array($key, ['_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes'], true)) {
            $this->schedule($id);
        }
    }

    public function attachmentChanged(int $id, \WP_Post $post, bool $update, ?\WP_Post $before): void
    {
        if ($update && $post->post_type === 'attachment' && $before
            && ($before->post_status !== $post->post_status || $before->post_parent !== $post->post_parent)) {
            $this->schedule($id);
        }
    }

    public function schedule(int $id): bool
    {
        if (!$this->offloader->writesAllowed() || get_post_type($id) !== 'attachment') { return false; }
        try {
            $this->offloader->recordPending($id);
            if ($this->queue($id, 0, 60)) { return true; }
            throw new \RuntimeException('Could not schedule the offload job. Queue recovery will retry it.');
        } catch (\Throwable $error) {
            $this->recordError($id, Offloader::errorMessage($error));
            return false;
        }
    }

    private function queue(int $id, int $attempt, int $delay): bool
    {
        $args = [$id, $attempt];
        return wp_next_scheduled(self::JOB, $args) || wp_schedule_single_event(time() + $delay, self::JOB, $args) === true
            || (bool) wp_next_scheduled(self::JOB, $args);
    }

    public function recover(): void
    {
        if (!$this->offloader->writesAllowed()) { return; }
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
            WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND m.meta_key = %s AND m.meta_value < %s
            ORDER BY p.ID LIMIT 50", Offloader::PENDING, sprintf('%012d:', time())
        ));
        if ($wpdb->last_error) { throw new \RuntimeException('Could not read pending media offload work.'); }
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!$this->queue($id, 0, 1)) {
                $this->recordError($id, 'Could not recover the pending offload job.');
            }
        }
    }

    public function run(int $id, int $attempt = 0): void
    {
        if (!$this->offloader->writesAllowed()) { return; }
        $pending = (string) get_post_meta($id, Offloader::PENDING, true);
        if (!$this->offloader->eligible($id)) {
            $this->offloader->completePending($id, $pending);
            if (get_post_type($id) === 'attachment') { delete_post_meta($id, Offloader::ERROR); }
            return;
        }
        try {
            if ($pending === '') { $pending = $this->offloader->recordPending($id); }
            $this->offloader->offload($id);
            $this->offloader->completePending($id, $pending);
            if ($this->config->removeLocal && !wp_next_scheduled(self::CLEANUP, [$id])) {
                if (!wp_schedule_single_event(time() + 300, self::CLEANUP, [$id])) {
                    $this->recordError($id, 'Could not schedule local cleanup. Files were retained.');
                }
            }
        }
        catch (\Throwable $e) {
            if (get_post_type($id) !== 'attachment') { return; }
            $message = Offloader::errorMessage($e);
            $this->recordError($id, $message);
            $this->offloader->deferPending($id, $pending, 3600);
            if ($attempt < 4 && !$this->queue($id, $attempt + 1, 60 * (2 ** $attempt))) {
                $this->recordError($id, 'Could not schedule the offload retry. ' . $message);
            }
        }
    }

    private function recordError(int $id, string $message): void
    {
        if (!update_post_meta($id, Offloader::ERROR, wp_slash($message))
            && get_post_meta($id, Offloader::ERROR, true) !== $message) {
            error_log('Media offload could not record an error for attachment ' . $id . ' on site ' . get_current_blog_id() . '.');
        }
    }

    public function cleanup(int $id): void
    {
        if (!$this->config->removeLocal || !$this->offloader->writesAllowed() || !$this->offloader->eligible($id)) { return; }
        try { $this->offloader->cleanup($id); }
        catch (\Throwable $e) { $this->recordError($id, 'Cleanup: ' . Offloader::errorMessage($e)); }
    }

    /** Rehydrate only known editing operations, never ordinary attachment listings. */
    public function localFile(mixed $path, int $id): mixed
    {
        if (!is_string($path) || !$this->offloader->siteAllowed()) { return $path; }
        $needsFile = false;
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20) as $frame) {
            if (in_array($frame['function'] ?? '', ['wp_save_image', 'wp_restore_image', '_load_image_to_edit_path', 'wp_update_image_subsizes', 'edit_media_item', 'edit_media'], true)) {
                $needsFile = true; break;
            }
        }
        if (!apply_filters('cloakwp/media-offload/needs-local-file', $needsFile, $id, $path)) { return $path; }
        $key = get_current_blog_id() . ':' . $id;
        if (isset($this->preparedForEdit[$key]) && is_file($path)) { return $path; }
        try {
            if ($this->offloader->current($id)) { $this->offloader->restore($id); }
            else { $this->offloader->assertLocalOwnership($id); }
            $this->preparedForEdit[$key] = true;
        } catch (\Throwable $e) {
            $this->recordError($id, 'Restore: ' . Offloader::errorMessage($e));
            return false;
        }
        return $path;
    }

    public function transformationFile(mixed $path, int $id): mixed
    {
        if ($this->offloader->current($id) && !$this->offloader->isPrivate($id)) {
            // Verify even if the path exists. A previous copy may have reused it.
            $this->offloader->restore($id);
        } else {
            $this->offloader->assertLocalOwnership($id);
        }
        return $path;
    }
    public function url(mixed $url, int $id): mixed
    {
        if (!$this->offloader->siteAllowed()) { return $url; }
        $state = $this->offloader->state($id);
        if (!$state) { return $this->offloader->isPrivate($id) ? '' : $url; }
        if (!$this->offloader->current($id)) {
            return !empty($state['private']) || $this->offloader->isPrivate($id) ? '' : $url;
        }
        if ($this->offloader->preferLocal($id, $state)) { return $url; }
        return !empty($state['private']) ? $this->privateUrl($id, $state['main']) : Manifest::url($state, $state['main']);
    }
    private function privateUrl(int $id, string $name): string
    {
        return add_query_arg(['action' => 'cloakwp_media_private', 'attachment_id' => $id, 'file' => $name], admin_url('admin-post.php'));
    }
    public function originalUrl(mixed $url, int $id): mixed
    {
        if (!$this->offloader->siteAllowed()) { return $url; }
        $state = $this->offloader->state($id);
        if (!$state || empty($state['private']) || !$this->offloader->current($id)) {
            return (!empty($state['private']) || $this->offloader->isPrivate($id)) ? '' : $url;
        }
        $meta = wp_get_attachment_metadata($id, true);
        $name = is_array($meta) && !empty($meta['original_image']) ? basename((string) $meta['original_image']) : $state['main'];
        return isset($state['files'][$name]) ? $this->privateUrl($id, $name) : '';
    }
    public function privateImage(mixed $result, int $id, mixed $size): mixed
    {
        if ($result || !$this->offloader->siteAllowed()) { return $result; }
        $state = $this->offloader->state($id);
        if (!$state || empty($state['private']) || !$this->offloader->current($id) || !wp_attachment_is_image($id)) { return $result; }
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
        if (!$post || $post->post_type !== 'attachment' || !$state || empty($state['private']) || !$this->offloader->current($id)
            || !is_user_logged_in() || !current_user_can('edit_post', $capId)
            || $name === '' || basename($name) !== $name || !isset($state['files'][$name])) {
            wp_die('File not found.', '', ['response' => 404]);
        }
        try { $temp = $this->offloader->temporaryFile(true); }
        catch (\Throwable $e) { wp_die('File unavailable.', '', ['response' => 503]); }
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
            $this->recordError($id, 'Private download: ' . Offloader::errorMessage($e));
            @unlink($temp);
            wp_die('File unavailable.', '', ['response' => 503]);
        } finally { @unlink($temp); }
        exit;
    }
    public function attachmentId(mixed $found, string $url): mixed
    {
        if (($found !== null && $found !== 0) || !$this->offloader->siteAllowed()) { return $found; }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !preg_match_all('~/site-[0-9]+/attachment-([0-9]+)/[a-f0-9]{64}(?=/)~', $path, $matches)) { return $found; }
        $candidate = preg_split('/[?#]/', $url, 2)[0];
        $name = rawurldecode(basename($path));
        foreach (array_reverse($matches[1]) as $id) {
            $state = $this->offloader->state((int) $id);
            if ($state && !$state['private'] && isset($state['files'][$name]) && $this->offloader->current((int) $id)
                && hash_equals(Manifest::url($state, $name), $candidate)) {
                return (int) $id;
            }
        }
        return $found;
    }
    public function srcset(array $sources, array $size, string $src, array $meta, int $id): array
    {
        if (!$this->offloader->siteAllowed()) { return $sources; }
        $state = $this->offloader->state($id);
        if (!empty($state['private']) || $this->offloader->isPrivate($id)) { return []; }
        if (!$state || !$this->offloader->current($id)) { return $sources; }
        if ($this->offloader->preferLocal($id, $state)) { return $sources; }
        foreach ($sources as &$source) {
            $name = rawurldecode(basename((string) parse_url($source['url'], PHP_URL_PATH)));
            if (isset($state['files'][$name])) { $source['url'] = Manifest::url($state, $name); }
        }
        unset($source);
        return $sources;
    }
}
