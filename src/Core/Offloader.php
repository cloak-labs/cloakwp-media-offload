<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Core;

use CloakWP\MediaOffload\Contracts\Storage;
use CloakWP\MediaOffload\Storage\S3Storage;

/** Attachment state is stored in post meta; objects are immutable and never deleted here. */
final class Offloader
{
    public const META = '_cloakwp_media_offload';
    public const ERROR = '_cloakwp_media_offload_error';
    public const PENDING = '_cloakwp_media_offload_pending';

    public function __construct(private readonly Config $config) {}

    public function siteAllowed(): bool
    {
        return $this->config->siteIds === null || in_array((int) get_current_blog_id(), $this->config->siteIds, true);
    }

    public function writesAllowed(): bool
    {
        return $this->siteAllowed() && $this->config->writeEnabled;
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
        $seen = [$id => true];
        $parent = $post;
        while (!$private && $parent && $parent->post_parent) {
            $parentId = (int) $parent->post_parent;
            if (isset($seen[$parentId])) {
                $private = true;
                break;
            }
            $seen[$parentId] = true;
            $parent = get_post($parentId);
            $private = $parent && ($parent->post_status === 'private' || $parent->post_password !== ''
                || get_post_meta($parentId, '_wp_trash_meta_status', true) === 'private');
        }
        $private = $private || !empty($post->post_password);
        return (bool) apply_filters('cloakwp/media-offload/private', $private, $id, $post);
    }

    private function storageFor(bool $private): Storage
    {
        if ($private) {
            return $this->config->privateStorage ?? throw new \RuntimeException('Private storage is not configured.');
        }
        return $this->config->storage;
    }

    private function readStorage(array $state): Storage
    {
        $storage = $this->storageFor($state['private']);
        $identity = $state['storage'] ?? '';
        if ($identity === '' && !$state['private'] && isset($this->config->publicSources[$state['url']])) {
            return $this->config->publicSources[$state['url']];
        }
        if ($identity === '' || ($storage instanceof S3Storage && $storage->identity() === $identity)) {
            return $storage;
        }
        if (!$state['private'] && isset($this->config->publicSources[$identity])) {
            return $this->config->publicSources[$identity];
        }
        throw new \RuntimeException('No read adapter is configured for this attachment’s source bucket.');
    }

    private function attachedPath(int $id): mixed
    {
        return AttachmentFiles::attached($id);
    }

    public function assertLocalOwnership(int $id): void
    {
        if (!$this->siteAllowed()) { return; }
        $path = $this->attachedPath($id);
        if (!is_string($path) || $path === '') { return; }
        $names = AttachmentFiles::names(basename($path), wp_get_attachment_metadata($id, true),
            get_post_meta($id, '_wp_attachment_backup_sizes', true));
        AttachmentFiles::assertExclusive($id, dirname($path), $names);
    }

    private function localBase(int $id, bool $create = false): string
    {
        if ($this->isPrivate($id) && $this->config->privateDirectory !== null) {
            return $this->privateBase();
        }
        $uploads = wp_get_upload_dir();
        $base = rtrim($uploads['basedir'], '/');
        if (!is_dir($base) && (!$create || !wp_mkdir_p($base))) {
            throw new \RuntimeException('Upload directory does not exist.');
        }
        return $base;
    }

    private function relativePath(int $id): string
    {
        $path = get_post_meta($id, '_wp_attached_file', true);
        if (!is_string($path)) {
            throw new \RuntimeException('Attachment has no local file path.');
        }
        $base = rtrim(wp_get_upload_dir()['basedir'], '/') . '/';
        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if (!Manifest::path($path)) {
            throw new \RuntimeException('Attachment does not have a portable file path.');
        }
        return $path;
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
        $realBase = realpath($base);
        if (!$real || !$realBase || !str_starts_with($real, $realBase . DIRECTORY_SEPARATOR)) { throw new \RuntimeException('Unsafe attachment directory.'); }
        return $real;
    }

    /** Create private scratch files under the configured protected directory. */
    public function temporaryFile(bool $private): string
    {
        if (!$this->siteAllowed()) { throw new \RuntimeException('Media offload is not enabled for this site.'); }
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
        $base = realpath($this->localBase($id));
        $dir = dirname($file);
        $meta = wp_get_attachment_metadata($id, true);
        if (!$base) { throw new \RuntimeException('Upload directory does not exist.'); }
        $names = AttachmentFiles::names(basename($file), $meta, get_post_meta($id, '_wp_attachment_backup_sizes', true));
        $out = [];
        foreach ($names as $name) {
            $path = $dir . '/' . $name;
            clearstatcache(true, $path);
            if (is_link($path)) { throw new \RuntimeException('Refusing to offload a symlink: ' . basename($path)); }
            $real = realpath($path);
            if (!$real || !is_file($real) || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Missing or unsafe attachment file: ' . basename($path));
            }
            $hash = hash_file('sha256', $real);
            $bytes = filesize($real);
            if ($hash === false || $bytes === false) { throw new \RuntimeException('Cannot inspect attachment file: ' . $name); }
            $out[$name] = ['path' => $real, 'size' => $bytes, 'sha256' => $hash];
        }
        ksort($out);
        return $out;
    }

    public function offload(int $id, bool $allowVisibilityChange = false, bool $refresh = false): array
    {
        if (!$this->writesAllowed()) { throw new \RuntimeException('Media offload writes are disabled for this site.'); }
        if (!$this->eligible($id)) { throw new \RuntimeException('Attachment is excluded from public offloading.'); }
        $lease = Lease::acquire('attachment:' . $id);
        if (!$lease) { throw new \RuntimeException('Attachment is already being processed.'); }
        try {
            $previous = $this->state($id);
            $path = (string) $this->attachedPath($id);
            $directoryOnDisk = dirname($path);
            $relative = $this->relativePath($id);
            $metadata = wp_get_attachment_metadata($id, true);
            if (!$metadata && wp_attachment_is_image($id) && get_post_mime_type($id) !== 'image/svg+xml') {
                throw new \RuntimeException('WordPress has not finished generating image metadata.');
            }
            $backups = get_post_meta($id, '_wp_attachment_backup_sizes', true);
            $private = $this->isPrivate($id);
            if ($previous && !empty($previous['private']) !== $private && !$allowVisibilityChange) {
                throw new \RuntimeException('Attachment visibility changed; an explicit visibility migration is required.');
            }
            $names = AttachmentFiles::names(basename($path), $metadata, $backups);
            $current = $previous && $this->current($id);
            if ($current && !$refresh && !array_diff($names, array_keys($previous['files']))) {
                foreach ($names as $name) {
                    $local = $directoryOnDisk . '/' . $name;
                    clearstatcache(true, $local);
                    if (is_link($local) || (is_file($local) && hash_file('sha256', $local) !== $previous['files'][$name]['sha256'])) {
                        throw new \RuntimeException('Local file differs from the recorded revision: ' . $name . '. Use --refresh after verifying an intentional replacement.');
                    }
                }
                $this->verifyObjects($previous, $directoryOnDisk);
                wp_cache_delete($id, 'post_meta');
                clean_post_cache($id);
                if ($this->stillCurrent($previous, $id)) {
                    $lease->assertHeld();
                    if ($this->state($id) !== $previous) {
                        throw new \RuntimeException('Attachment manifest changed during verification; retry it.');
                    }
                    if ($previous['version'] === 1) {
                        $previous['version'] = 2;
                        $previous['relative'] = $relative;
                        $previous['backups_hash'] = hash('sha256', serialize($backups));
                        $source = $this->readStorage($previous);
                        $previous['storage'] = $source instanceof S3Storage ? $source->identity() : '';
                        $this->persist($id, $previous);
                    }
                    delete_post_meta($id, self::ERROR);
                    return $previous;
                }
                throw new \RuntimeException('Attachment changed during verification; retry it.');
            }
            AttachmentFiles::assertExclusive($id, $directoryOnDisk, $names);
            if ($current && array_diff($names, array_keys($previous['files']))) {
                // Old versions did not include every WordPress companion. Restore
                // the known files first and require the missing companions on disk.
                $this->restoreFiles($id, $previous, $lease);
            } elseif ($current && !is_file($path)) {
                $this->restoreFiles($id, $previous, $lease);
            }
            $files = $this->inventory($id);
            $storage = $this->storageFor($private);
            $revisionParts = [];
            foreach ($files as $name => $entry) { $revisionParts[$name] = $entry['sha256']; }
            $revision = hash('sha256', wp_json_encode($revisionParts));
            $blog = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
            $subdirectory = $private && $this->config->privateDirectory !== null ? '.' : dirname($relative);
            $directory = $this->config->prefix . '/site-' . $blog . '/attachment-' . $id . '/' . $revision
                . ($subdirectory !== '.' ? '/' . $subdirectory : '');
            $manifest = [];
            foreach ($files as $name => $entry) {
                $key = $directory . '/' . $name;
                $this->putObject($storage, $key, $entry['path'], $entry['sha256']);
                $remote = $storage->inspect($key);
                if (!$remote || $remote['size'] !== $entry['size'] || !hash_equals($entry['sha256'], $remote['sha256'])) {
                    throw new \RuntimeException('Remote verification failed: ' . $name);
                }
                if (hash_file('sha256', $entry['path']) !== $entry['sha256']) { throw new \RuntimeException('File changed during upload: ' . $name); }
                $manifest[$name] = ['key' => $key, 'size' => $entry['size'], 'sha256' => $entry['sha256']];
            }
            $state = ['version' => 2, 'url' => $this->config->publicUrl, 'directory' => $directory,
                'main' => basename($path), 'files' => $manifest, 'private' => $private, 'relative' => $relative,
                'metadata_hash' => hash('sha256', serialize($metadata)), 'backups_hash' => hash('sha256', serialize($backups)),
                'storage' => $storage instanceof S3Storage ? $storage->identity() : ''];
            $this->verifyDelivery($state);
            $lease->assertHeld();
            wp_cache_delete($id, 'post_meta');
            clean_post_cache($id);
            if (!$this->eligible($id) || !$this->stillCurrent($state, $id)
                || $files !== $this->inventory($id)) {
                throw new \RuntimeException('Attachment changed during upload; retry it.');
            }
            AttachmentFiles::assertExclusive($id, $directoryOnDisk, $names);
            $this->persist($id, $state);
            delete_post_meta($id, self::ERROR);
            return $state;
        } catch (\Throwable $e) {
            if (get_post_type($id) === 'attachment') {
                update_post_meta($id, self::ERROR, wp_slash(self::errorMessage($e)));
            }
            throw $e;
        } finally { $lease->release(); }
    }

    private function persist(int $id, array $state): void
    {
        if (!Manifest::valid($state)) {
            throw new \RuntimeException('The generated attachment manifest is invalid.');
        }
        update_post_meta($id, self::META, wp_slash($state));
        wp_cache_delete($id, 'post_meta');
        if ($this->state($id) !== $state) {
            throw new \RuntimeException('Could not save the verified attachment manifest.');
        }
    }

    public static function errorMessage(\Throwable $error): string
    {
        if ($error instanceof \Aws\Exception\AwsException) {
            return sprintf('Object storage request failed (%s, HTTP %s).',
                $error->getAwsErrorCode() ?: 'transport error', $error->getStatusCode() ?? 'unavailable');
        }
        return $error->getMessage();
    }

    private function verifyObjects(array $state, ?string $repairFrom = null, bool $publicHttp = false): void
    {
        $storage = $this->readStorage($state);
        foreach ($state['files'] as $name => $entry) {
            $remote = $storage->inspect($entry['key']);
            // Repair the original immutable URL only from matching retained bytes,
            // and only when the source adapter is also this environment's writer.
            if (!$remote && $repairFrom !== null && $storage === $this->storageFor($state['private'])) {
                $path = $repairFrom . '/' . $name;
                if (!is_link($path) && is_file($path) && hash_file('sha256', $path) === $entry['sha256']) {
                    $this->putObject($storage, $entry['key'], $path, $entry['sha256']);
                    $remote = $storage->inspect($entry['key']);
                }
            }
            if (!$remote || $remote['size'] !== $entry['size'] || !hash_equals($entry['sha256'], $remote['sha256'])) {
                throw new \RuntimeException('Remote verification failed: ' . $name);
            }
        }
        $this->verifyDelivery($state, $publicHttp);
    }

    private function verifyDelivery(array $state, bool $force = false): void
    {
        if ((!$this->config->verifyPublicDelivery && !$force) || $state['private']) {
            return;
        }
        foreach ($state['files'] as $name => $entry) {
            $response = wp_safe_remote_head(Manifest::url($state, $name), [
                'timeout' => 5, 'redirection' => 0, 'headers' => ['Accept-Encoding' => 'identity'],
            ]);
            $length = wp_remote_retrieve_header($response, 'content-length');
            if (wp_remote_retrieve_response_code($response) !== 200 || (string) $length !== (string) $entry['size']) {
                throw new \RuntimeException('Public delivery verification failed: ' . $name);
            }
        }
    }

    private function putObject(Storage $storage, string $key, string $path, string $sha256): void
    {
        $type = wp_check_filetype(basename($path));
        $mime = $type['type'] ?: 'application/octet-stream';
        if ($storage instanceof S3Storage) {
            $storage->put($key, $path, $mime, $sha256);
        } else {
            $storage->put($key, $path, $mime);
        }
    }

    public function state(int $id): ?array
    {
        if (!$this->siteAllowed()) { return null; }
        $state = get_post_meta($id, self::META, true);
        return Manifest::valid($state) ? $state : null;
    }

    public function current(int $id): bool
    {
        $state = $this->state($id);
        return $state && $this->stillCurrent($state, $id);
    }

    public function urlFor(int $id, string $filename): ?string
    {
        $state = $this->state($id);
        if (!$state || $state['private'] || !isset($state['files'][$filename]) || !$this->stillCurrent($state, $id)
            || $this->preferLocal($id, $state)) {
            return null;
        }
        return Manifest::url($state, $filename);
    }

    private function stillCurrent(array $state, int $id): bool
    {
        if (!hash_equals($state['metadata_hash'], hash('sha256', serialize(wp_get_attachment_metadata($id, true))))
            || basename((string) $this->attachedPath($id)) !== $state['main']
            || $state['private'] !== $this->isPrivate($id)) {
            return false;
        }
        if ($state['version'] === 1) { return true; }
        try {
            return $state['relative'] === $this->relativePath($id)
                && hash_equals($state['backups_hash'], hash('sha256', serialize(get_post_meta($id, '_wp_attachment_backup_sizes', true))));
        } catch (\RuntimeException) {
            return false;
        }
    }

    /** Restore verified files to the original uploads location without overwriting. */
    public function restore(int $id): int
    {
        $state = $this->state($id);
        if (!$state || !$this->stillCurrent($state, $id)) { throw new \RuntimeException('Attachment has no current offload manifest.'); }
        $lease = Lease::acquire('attachment:' . $id);
        if (!$lease) { throw new \RuntimeException('Attachment is already being processed.'); }
        try { return $this->restoreFiles($id, $state, $lease); }
        finally { $lease->release(); }
    }

    private function restoreFiles(int $id, array $state, Lease $lease): int
    {
        if (!$this->stillCurrent($state, $id)) { throw new \RuntimeException('Attachment changed before restore.'); }
        $path = $this->attachedPath($id);
        if (!is_string($path) || $path === '') { throw new \RuntimeException('Attachment path is missing.'); }
        $base = $this->localBase($id, true);
        $dir = dirname($path);
        AttachmentFiles::assertExclusive($id, $dir, array_keys($state['files']));
        $storage = $this->readStorage($state);
        $protected = !empty($state['private']);
        $baseMode = fileperms($base);
        $directoryMode = $protected ? 0700 : ($baseMode === false ? 0755 : ($baseMode & 0777));
        $real = $dir === $base ? realpath($base) : $this->safeDirectory($base, $dir, $directoryMode);
        if ($real === false) { throw new \RuntimeException('Cannot resolve the uploads directory.'); }
        $count = 0;
        foreach ($state['files'] as $name => $entry) {
            $dest = $real . DIRECTORY_SEPARATOR . $name;
            clearstatcache(true, $dest);
            if (is_link($dest)) { throw new \RuntimeException('Refusing to restore through a symlink: ' . $name); }
            if (file_exists($dest)) {
                if (!is_file($dest) || hash_file('sha256', $dest) !== $entry['sha256']) {
                    throw new \RuntimeException('Refusing to overwrite a different local file: ' . $name);
                }
                continue;
            }
            $temp = tempnam($real, '.offload-');
            if ($temp === false) { throw new \RuntimeException('Cannot create temporary file.'); }
            try {
                $this->downloadEntry($storage, $entry, $temp);
                $dirMode = fileperms($real);
                $fileMode = $protected ? 0600 : ($dirMode === false ? 0644 : ($dirMode & 0666));
                if (!chmod($temp, $fileMode)) { throw new \RuntimeException('Cannot set restored file permissions: ' . $name); }
                $lease->assertHeld();
                wp_cache_delete($id, 'post_meta');
                if (!$this->stillCurrent($state, $id) || $this->state($id) !== $state || !@link($temp, $dest)) {
                    throw new \RuntimeException('Cannot safely restore file: ' . $name);
                }
                ++$count;
            } finally { @unlink($temp); }
        }
        return $count;
    }

    /** Remove only a complete, unchanged, remotely verified attachment. */
    public function cleanup(int $id): int
    {
        if (!$this->writesAllowed()) { throw new \RuntimeException('Media offload writes are disabled for this site.'); }
        $lease = Lease::acquire('attachment:' . $id);
        if (!$lease) { throw new \RuntimeException('Attachment is already being processed.'); }
        try {
            $state = $this->state($id);
            if (!$state || !$this->stillCurrent($state, $id)) {
                throw new \RuntimeException('Offloaded state is missing or out of date.');
            }
            $files = $this->inventory($id);
            if (array_keys($files) !== array_keys($state['files'])) { throw new \RuntimeException('Attachment files changed since offloading.'); }
            $directory = dirname((string) $this->attachedPath($id));
            AttachmentFiles::assertExclusive($id, $directory, array_keys($files));
            $storage = $this->readStorage($state);
            foreach ($files as $name => $entry) {
                $saved = $state['files'][$name];
                if ($entry['sha256'] !== $saved['sha256'] || $entry['size'] !== $saved['size']) { throw new \RuntimeException('Local file changed: ' . $name); }
                $remote = $storage->inspect($saved['key']);
                if (!$remote || $remote['size'] !== $saved['size'] || !hash_equals($saved['sha256'], $remote['sha256'])) { throw new \RuntimeException('Remote verification failed: ' . $name); }
                $temp = $this->temporaryFile(!empty($state['private']));
                try { $this->downloadEntry($storage, $saved, $temp); }
                finally { @unlink($temp); }
            }
            $lease->assertHeld();
            wp_cache_delete($id, 'post_meta');
            AttachmentFiles::assertExclusive($id, $directory, array_keys($files));
            if ($state !== $this->state($id) || !$this->stillCurrent($state, $id) || $files !== $this->inventory($id)) {
                throw new \RuntimeException('Attachment changed during cleanup.');
            }
            $count = 0;
            foreach ($files as $entry) {
                if (hash_file('sha256', $entry['path']) !== $entry['sha256'] || !unlink($entry['path'])) { throw new \RuntimeException('Could not safely remove a local file.'); }
                ++$count;
            }
            return $count;
        } finally { $lease->release(); }
    }

    /** Download one object to a caller-owned temporary path and validate its bytes. */
    public function download(int $id, string $name, string $path): void
    {
        $state = $this->state($id);
        if (!$state || empty($state['files'][$name])) { throw new \RuntimeException('File is not in the attachment manifest.'); }
        $this->downloadEntry($this->readStorage($state), $state['files'][$name], $path);
    }

    private function downloadEntry(Storage $storage, array $entry, string $path): void
    {
        $storage->download($entry['key'], $path);
        clearstatcache(true, $path);
        if (filesize($path) !== $entry['size'] || !hash_equals($entry['sha256'], (string) hash_file('sha256', $path))) { throw new \RuntimeException('Downloaded file failed verification.'); }
    }

    /** Copy into a different prefix of the same configured buckets, then switch this database's manifest. */
    public function promote(int $id, string $prefix): array
    {
        if (!$this->writesAllowed()) { throw new \RuntimeException('Media offload writes are disabled for this site.'); }
        if (!$this->state($id)) { throw new \RuntimeException('Attachment has not been offloaded.'); }
        $this->restore($id);
        $c = $this->config;
        return (new self(new Config($c->storage, $c->publicUrl, trim($prefix, '/'), false, false,
            $c->privateStorage, $c->privateDirectory, $c->siteIds, $c->publicSources, $c->verifyPublicDelivery)))->offload($id, false, true);
    }

    /** Map legacy WordPress upload URLs to verified public object URLs. */
    public function legacyUrls(int $id, string $sourceBase): array
    {
        $state = $this->state($id);
        if (!$state || $state['private'] || !$this->stillCurrent($state, $id)) { return []; }
        try { $attached = $this->relativePath($id); }
        catch (\RuntimeException) { return []; }
        $dir = str_replace('\\', '/', dirname($attached));
        $dir = $dir === '.' ? '' : trim($dir, '/') . '/';
        $map = [];
        foreach ($state['files'] as $name => $entry) {
            $old = rtrim($sourceBase, '/') . '/' . $dir . $name;
            $map[$old] = Manifest::url($state, $name);
        }
        return $map;
    }

    public function verify(int $id, bool $publicHttp = false): array
    {
        $state = $this->state($id);
        if (!$state || !$this->stillCurrent($state, $id)) {
            throw new \RuntimeException('Attachment has no current offload manifest.');
        }
        $names = AttachmentFiles::names($state['main'], wp_get_attachment_metadata($id, true),
            get_post_meta($id, '_wp_attachment_backup_sizes', true));
        if (array_diff($names, array_keys($state['files']))) {
            throw new \RuntimeException('The manifest is missing attachment files.');
        }
        $this->verifyObjects($state, null, $publicHttp);
        return $state;
    }

    /** Only advertise a local fallback when its complete revision is available. */
    public function preferLocal(int $id, array $state): bool
    {
        if ($state['private'] || !apply_filters('cloakwp/media-offload/prefer-local', $this->config->preferLocalDelivery, $id)) {
            return false;
        }
        try {
            $files = $this->inventory($id);
            foreach ($state['files'] as $name => $entry) {
                if (($files[$name]['sha256'] ?? null) !== $entry['sha256']) { return false; }
            }
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function recordPending(int $id): string
    {
        $token = sprintf('%012d:%s', time() + 120, wp_generate_uuid4());
        if (!update_post_meta($id, self::PENDING, $token) && get_post_meta($id, self::PENDING, true) !== $token) {
            throw new \RuntimeException('Could not record pending offload work.');
        }
        return $token;
    }

    public function deferPending(int $id, string $token, int $seconds): void
    {
        if ($token !== '') {
            $next = sprintf('%012d:%s', time() + $seconds, wp_generate_uuid4());
            update_post_meta($id, self::PENDING, $next, $token);
        }
    }

    public function completePending(int $id, string $token): void
    {
        if ($token !== '') {
            delete_post_meta($id, self::PENDING, $token);
        }
    }
}
