<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Core;

/** Attachment state is stored in post meta; objects are immutable and never deleted here. */
final class Offloader
{
    public const META = '_cloakwp_media_offload';
    public const ERROR = '_cloakwp_media_offload_error';

    public function __construct(private readonly Config $config) {}

    public function siteAllowed(): bool
    {
        return $this->config->siteIds === null || in_array((int) get_current_blog_id(), $this->config->siteIds, true);
    }

    public function eligible(int $id): bool
    {
        if (!$this->siteAllowed()) { return false; }
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment' || $post->post_status === 'trash') { return false; }
        if ($this->isPrivate($id) && !$this->config->privateStorage) { return false; }
        return (bool) apply_filters('cloakwp/media-offload/eligible', true, $id, $post);
    }

    public function isPrivate(int $id): bool
    {
        $post = get_post($id);
        if (!$post) { return true; }
        $private = $post->post_status === 'private';
        return (bool) apply_filters('cloakwp/media-offload/private', $private, $id, $post);
    }

    private function storageFor(bool $private): \CloakWP\MediaOffload\Contracts\Storage
    {
        if ($private) {
            return $this->config->privateStorage ?? throw new \RuntimeException('Private storage is not configured.');
        }
        return $this->config->storage;
    }

    private function attachedPath(int $id): mixed
    {
        return apply_filters('cloakwp/media-offload/local-path', get_attached_file($id, true), $id);
    }

    private function localBase(int $id): string
    {
        if ($this->isPrivate($id) && $this->config->privateDirectory !== null) {
            return $this->privateBase();
        }
        $uploads = wp_get_upload_dir();
        $base = realpath($uploads['basedir']);
        if (!$base) { throw new \RuntimeException('Upload directory does not exist.'); }
        return $base;
    }

    private function privateBase(): string
    {
        $base = $this->config->privateDirectory !== null ? realpath($this->config->privateDirectory) : false;
        if (!$base || !is_dir($base)) { throw new \RuntimeException('Private directory is unavailable.'); }
        $webRoots = [ABSPATH, WP_CONTENT_DIR, dirname(rtrim(ABSPATH, '/\\'))];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) { $webRoots[] = (string) $_SERVER['DOCUMENT_ROOT']; }
        foreach ($webRoots as $webRoot) {
            $real = realpath($webRoot);
            if ($real && ($base === $real || str_starts_with($base, $real . DIRECTORY_SEPARATOR))) {
                throw new \RuntimeException('Private directory must be outside the web root.');
            }
        }
        return $base;
    }

    private function safeDirectory(string $base, string $dir, int $mode = 0700): string
    {
        if (!str_starts_with($dir, $base . DIRECTORY_SEPARATOR)) { throw new \RuntimeException('Unsafe attachment directory.'); }
        $relative = substr($dir, strlen($base) + 1);
        $current = $base;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') { throw new \RuntimeException('Unsafe attachment directory.'); }
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current) || (!is_dir($current) && !@mkdir($current, $mode) && !is_dir($current))) {
                throw new \RuntimeException('Cannot create a safe attachment directory.');
            }
        }
        $real = realpath($current);
        if (!$real || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) { throw new \RuntimeException('Unsafe attachment directory.'); }
        return $real;
    }

    /** Create private scratch files under the configured protected directory. */
    public function temporaryFile(bool $private): string
    {
        $dir = sys_get_temp_dir();
        if ($private && $this->config->privateDirectory !== null) {
            $root = $this->privateBase();
            $dir = $this->safeDirectory($root, $root . DIRECTORY_SEPARATOR . '.scratch');
        }
        $temp = tempnam($dir, 'cloakwp-');
        if ($temp === false) { throw new \RuntimeException('Cannot create a temporary file.'); }
        @chmod($temp, 0600);
        return $temp;
    }

    /** Inventory only files explicitly identified by WordPress metadata. */
    public function inventory(int $id): array
    {
        if (!$this->siteAllowed()) { throw new \RuntimeException('Media offload is not enabled for this site.'); }
        $file = $this->attachedPath($id);
        if (!is_string($file) || $file === '') { throw new \RuntimeException('Attachment has no local file path.'); }
        $base = $this->localBase($id);
        $dir = dirname($file);
        $meta = wp_get_attachment_metadata($id, true);
        $meta = is_array($meta) ? $meta : [];
        $files = [$file];
        if (!empty($meta['original_image'])) { $files[] = $dir . '/' . basename((string) $meta['original_image']); }
        if (!empty($meta['thumb'])) { $files[] = $dir . '/' . basename((string) $meta['thumb']); }
        foreach (($meta['sizes'] ?? []) as $size) {
            if (!empty($size['file'])) { $files[] = $dir . '/' . basename((string) $size['file']); }
        }
        $backups = get_post_meta($id, '_wp_attachment_backup_sizes', true);
        if (is_array($backups)) {
            foreach ($backups as $size) { if (!empty($size['file'])) { $files[] = $dir . '/' . basename((string) $size['file']); } }
        }
        $out = [];
        foreach (array_unique($files) as $path) {
            if (is_link($path)) { throw new \RuntimeException('Refusing to offload a symlink: ' . basename($path)); }
            $real = realpath($path);
            if (!$real || !is_file($real) || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Missing or unsafe attachment file: ' . basename($path));
            }
            $name = basename($real);
            if (isset($out[$name]) && $out[$name]['path'] !== $real) { throw new \RuntimeException('Duplicate attachment filename.'); }
            $hash = hash_file('sha256', $real);
            $bytes = filesize($real);
            if ($hash === false || $bytes === false) { throw new \RuntimeException('Cannot inspect attachment file: ' . $name); }
            $out[$name] = ['path' => $real, 'size' => $bytes, 'sha256' => $hash];
        }
        ksort($out);
        return $out;
    }

    public function offload(int $id, bool $allowVisibilityChange = false): array
    {
        if (!$this->eligible($id)) { throw new \RuntimeException('Attachment is excluded from public offloading.'); }
        $lock = '_cloakwp_offload_lock_' . $id;
        $token = wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { throw new \RuntimeException('Attachment is already being processed; inspect its lock if a prior job crashed.'); }
        try {
            $previous = $this->state($id);
            if ($previous && !is_file((string) $this->attachedPath($id))) { $this->restore($id); }
            $files = $this->inventory($id);
            $metadata = wp_get_attachment_metadata($id, true);
            $private = $this->isPrivate($id);
            if ($previous && !empty($previous['private']) !== $private && !$allowVisibilityChange) {
                throw new \RuntimeException('Attachment visibility changed; an explicit visibility migration is required.');
            }
            $storage = $this->storageFor($private);
            $revisionParts = [];
            foreach ($files as $name => $entry) { $revisionParts[$name] = $entry['sha256']; }
            $revision = hash('sha256', wp_json_encode($revisionParts));
            $blog = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
            $relative = !$private || $this->config->privateDirectory === null ? (is_array($metadata) && !empty($metadata['file']) ? trim(str_replace('\\', '/', dirname((string) $metadata['file'])), './') : '') : '';
            if ($relative !== '' && (str_contains($relative, '..') || str_starts_with($relative, '/'))) { throw new \RuntimeException('Unsafe attachment metadata path.'); }
            $directory = trim($this->config->prefix, '/') . '/site-' . $blog . '/attachment-' . $id . '/' . $revision . ($relative !== '' ? '/' . $relative : '');
            $manifest = [];
            foreach ($files as $name => $entry) {
                $key = $directory . '/' . $name;
                $type = wp_check_filetype($name);
                $storage->put($key, $entry['path'], $type['type'] ?: 'application/octet-stream');
                $remote = $storage->inspect($key);
                if (!$remote || $remote['size'] !== $entry['size'] || !hash_equals($entry['sha256'], $remote['sha256'])) {
                    throw new \RuntimeException('Remote verification failed: ' . $name);
                }
                if (hash_file('sha256', $entry['path']) !== $entry['sha256']) { throw new \RuntimeException('File changed during upload: ' . $name); }
                $manifest[$name] = ['key' => $key, 'size' => $entry['size'], 'sha256' => $entry['sha256']];
            }
            if ($metadata !== wp_get_attachment_metadata($id, true) || $files !== $this->inventory($id) || $private !== $this->isPrivate($id)) {
                throw new \RuntimeException('Attachment changed during upload; retry it.');
            }
            $main = basename((string) $this->attachedPath($id));
            $state = ['version' => 1, 'url' => rtrim($this->config->publicUrl, '/'), 'directory' => $directory,
                'main' => $main, 'files' => $manifest, 'private' => $private, 'metadata_hash' => hash('sha256', serialize($metadata))];
            update_post_meta($id, self::META, $state);
            delete_post_meta($id, self::ERROR);
            return $state;
        } catch (\Throwable $e) {
            update_post_meta($id, self::ERROR, $e->getMessage());
            throw $e;
        } finally { if (get_option($lock) === $token) { delete_option($lock); } }
    }

    public function state(int $id): ?array
    {
        if (!$this->siteAllowed()) { return null; }
        $state = get_post_meta($id, self::META, true);
        if (!is_array($state) || empty($state['directory']) || empty($state['url']) || empty($state['main'])) { return null; }
        return $state;
    }

    public function current(int $id): bool
    {
        $state = $this->state($id);
        return $state && isset($state['metadata_hash'])
            && hash_equals($state['metadata_hash'], hash('sha256', serialize(wp_get_attachment_metadata($id, true))))
            && basename((string) $this->attachedPath($id)) === $state['main']
            && !empty($state['private']) === $this->isPrivate($id);
    }

    /** Restore verified files to the original uploads location without overwriting. */
    public function restore(int $id): int
    {
        $state = $this->state($id);
        if (!$state) { throw new \RuntimeException('Attachment has not been offloaded.'); }
        $path = $this->attachedPath($id);
        if (!is_string($path) || $path === '') { throw new \RuntimeException('Attachment path is missing.'); }
        $base = $this->localBase($id);
        $dir = dirname($path);
        $protected = !empty($state['private']);
        $baseMode = fileperms($base);
        $directoryMode = $protected ? 0700 : ($baseMode === false ? 0755 : ($baseMode & 0777));
        $real = $dir === $base ? $base : $this->safeDirectory($base, $dir, $directoryMode);
        $count = 0;
        foreach ($state['files'] as $name => $entry) {
            if (basename($name) !== $name) { throw new \RuntimeException('Unsafe restore filename.'); }
            $dest = $real . DIRECTORY_SEPARATOR . $name;
            if (file_exists($dest)) {
                if (!is_file($dest) || hash_file('sha256', $dest) !== $entry['sha256']) { throw new \RuntimeException('Refusing to overwrite a different local file: ' . $name); }
                continue;
            }
            $temp = tempnam($real, '.offload-');
            if ($temp === false) { throw new \RuntimeException('Cannot create temporary file.'); }
            try {
                $this->storageFor(!empty($state['private']))->download($entry['key'], $temp);
                if (filesize($temp) !== $entry['size'] || hash_file('sha256', $temp) !== $entry['sha256']) { throw new \RuntimeException('Downloaded file failed verification: ' . $name); }
                $dirMode = fileperms($real);
                $fileMode = $protected ? 0600 : ($dirMode === false ? 0644 : ($dirMode & 0666));
                if (!chmod($temp, $fileMode)) { throw new \RuntimeException('Cannot set restored file permissions: ' . $name); }
                if (!link($temp, $dest)) { throw new \RuntimeException('Cannot safely restore file: ' . $name); }
                ++$count;
            } finally { @unlink($temp); }
        }
        return $count;
    }

    /** Remove only a complete, unchanged, remotely verified attachment. */
    public function cleanup(int $id): int
    {
        $lock = '_cloakwp_offload_lock_' . $id;
        $token = wp_generate_uuid4();
        if (!add_option($lock, $token, '', false)) { throw new \RuntimeException('Attachment is already being processed.'); }
        try {
            $state = $this->state($id);
            if (!$state || !isset($state['metadata_hash']) || !hash_equals($state['metadata_hash'], hash('sha256', serialize(wp_get_attachment_metadata($id, true))))) {
                throw new \RuntimeException('Offloaded state is missing or out of date.');
            }
            if (!empty($state['private']) !== $this->isPrivate($id)) { throw new \RuntimeException('Attachment visibility changed.'); }
            $files = $this->inventory($id);
            if (array_keys($files) !== array_keys($state['files'])) { throw new \RuntimeException('Attachment files changed since offloading.'); }
            global $wpdb;
            $storage = $this->storageFor(!empty($state['private']));
            foreach ($files as $name => $entry) {
                $saved = $state['files'][$name];
                if ($entry['sha256'] !== $saved['sha256'] || $entry['size'] !== $saved['size']) { throw new \RuntimeException('Local file changed: ' . $name); }
                $remote = $storage->inspect($saved['key']);
                if (!$remote || $remote['size'] !== $saved['size'] || !hash_equals($saved['sha256'], $remote['sha256'])) { throw new \RuntimeException('Remote verification failed: ' . $name); }
                $temp = $this->temporaryFile(!empty($state['private']));
                try { $this->download($id, $name, $temp); }
                finally { @unlink($temp); }
                $like = '%' . $wpdb->esc_like($name) . '%';
                $shared = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes') AND meta_value LIKE %s LIMIT 1", $id, $like));
                if ($shared) { throw new \RuntimeException('Another attachment may reference this file: ' . $name); }
            }
            if ($state !== $this->state($id) || $files !== $this->inventory($id)) { throw new \RuntimeException('Attachment changed during cleanup.'); }
            $count = 0;
            foreach ($files as $entry) {
                if (hash_file('sha256', $entry['path']) !== $entry['sha256'] || !unlink($entry['path'])) { throw new \RuntimeException('Could not safely remove a local file.'); }
                ++$count;
            }
            return $count;
        } finally { if (get_option($lock) === $token) { delete_option($lock); } }
    }

    /** Download one object to a caller-owned temporary path and validate its bytes. */
    public function download(int $id, string $name, string $path): void
    {
        $state = $this->state($id);
        if (!$state || empty($state['files'][$name])) { throw new \RuntimeException('File is not in the attachment manifest.'); }
        $entry = $state['files'][$name];
        $this->storageFor(!empty($state['private']))->download($entry['key'], $path);
        clearstatcache(true, $path);
        if (filesize($path) !== $entry['size'] || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) { throw new \RuntimeException('Downloaded file failed verification.'); }
    }

    /** Copy into a different prefix of the same configured buckets, then switch this database's manifest. */
    public function promote(int $id, string $prefix): array
    {
        if (!$this->state($id)) { throw new \RuntimeException('Attachment has not been offloaded.'); }
        $this->restore($id);
        $c = $this->config;
        return (new self(new Config($c->storage, $c->publicUrl, trim($prefix, '/'), false, false, $c->privateStorage, $c->privateDirectory, $c->siteIds)))->offload($id);
    }

    /** Map legacy WordPress upload URLs to verified public object URLs. */
    public function legacyUrls(int $id, string $sourceBase): array
    {
        $state = $this->state($id);
        if (!$state || !empty($state['private']) || !isset($state['metadata_hash'])
            || !hash_equals($state['metadata_hash'], hash('sha256', serialize(wp_get_attachment_metadata($id, true))))) { return []; }
        $attached = get_post_meta($id, '_wp_attached_file', true);
        if (!is_string($attached) || $attached === '' || str_starts_with($attached, '/') || str_contains($attached, '..')) { return []; }
        $dir = str_replace('\\', '/', dirname($attached));
        $dir = $dir === '.' ? '' : trim($dir, '/') . '/';
        $map = [];
        foreach ($state['files'] as $name => $entry) {
            $old = rtrim($sourceBase, '/') . '/' . $dir . $name;
            $new = rtrim($state['url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $entry['key'])));
            $map[$old] = $new;
        }
        return $map;
    }
}
