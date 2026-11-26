<?php
declare(strict_types=1);

namespace CloakWP\MediaOffload\Core;

/** The filenames WordPress associates with an attachment, including edit history. */
final class AttachmentFiles
{
    public static function names(string $main, mixed $metadata, mixed $backups, bool $strict = true): array
    {
        $metadata = is_array($metadata) ? $metadata : [];
        $names = [$main];
        foreach (['original_image', 'thumb', 'source_image', 'animated_video', 'animated_video_poster'] as $field) {
            if (!empty($metadata[$field])) {
                $names[] = $metadata[$field];
            }
        }
        $sizes = is_array($metadata['sizes'] ?? null) ? $metadata['sizes'] : [];
        foreach (array_merge(array_values($sizes), is_array($backups) ? array_values($backups) : []) as $size) {
            if (is_array($size) && !empty($size['file'])) {
                $names[] = $size['file'];
            }
        }
        $valid = array_filter($names, [Manifest::class, 'filename']);
        if ($strict && count($valid) !== count($names)) {
            throw new \RuntimeException('Unsafe attachment filename.');
        }
        $names = array_values(array_unique($valid));
        sort($names, SORT_STRING);
        return $names;
    }

    public static function attached(int $id): mixed
    {
        return apply_filters('cloakwp/media-offload/local-path', get_attached_file($id, true), $id);
    }

    /**
     * Find references by relative uploads directory. A file can be another
     * record's original, derivative, or edit backup. Include records with no manifest.
     */
    public static function owners(string $directory): array
    {
        global $wpdb;
        $uploads = rtrim(wp_get_upload_dir()['basedir'], '/');
        $directory = rtrim($directory, '/');
        if ($directory !== $uploads && !str_starts_with($directory, $uploads . '/')) {
            return [];
        }
        $relative = ltrim(substr($directory, strlen($uploads)), '/');
        $clause = $relative === '' ? "m.meta_value NOT LIKE '%%/%%'" : '(m.meta_value LIKE %s AND m.meta_value NOT LIKE %s)';
        $sql = "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
            WHERE p.post_type = 'attachment' AND m.meta_key = '_wp_attached_file'
            AND (($clause) OR m.meta_value LIKE %s)";
        $args = $relative === '' ? [] : [$wpdb->esc_like($relative . '/') . '%', $wpdb->esc_like($relative . '/') . '%/%'];
        $args[] = $wpdb->esc_like($directory . '/') . '%';
        $ids = array_unique(array_map('intval', $wpdb->get_col($wpdb->prepare($sql, ...$args))));
        if ($wpdb->last_error) {
            throw new \RuntimeException('Could not check local attachment path ownership.');
        }
        if ($ids) {
            update_meta_cache('post', $ids);
        }
        $files = [];
        foreach ($ids as $id) {
            $path = get_attached_file($id, true);
            if (!is_string($path) || dirname($path) !== $directory) {
                continue;
            }
            $names = self::names(basename($path), wp_get_attachment_metadata($id, true),
                get_post_meta($id, '_wp_attachment_backup_sizes', true), false);
            // A prior revision can reserve another name in this same directory.
            $saved = get_post_meta($id, Offloader::META, true);
            if (Manifest::valid($saved) && ($saved['version'] === 1 || dirname($saved['relative']) === ($relative === '' ? '.' : $relative))) {
                $names = array_unique(array_merge($names, array_keys($saved['files'])));
            }
            foreach ($names as $name) {
                $files[strtolower($name)][] = $id;
            }
        }
        return $files;
    }

    public static function isUploadsDirectory(string $directory): bool
    {
        $base = rtrim(wp_get_upload_dir()['basedir'], '/');
        $directory = rtrim($directory, '/');
        return $directory === $base || str_starts_with($directory, $base . '/');
    }

    public static function assertExclusive(int $id, string $directory, array $names): void
    {
        $owners = self::owners($directory);
        foreach ($names as $name) {
            foreach ($owners[strtolower($name)] ?? [] as $owner) {
                if ($owner !== $id) {
                    throw new \RuntimeException('Another attachment may reference this file: ' . $name);
                }
            }
        }
    }
}
