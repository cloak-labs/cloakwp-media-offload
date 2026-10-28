<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Plugin;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Core\UrlRewriter;

final class Command
{
    public function __construct(private readonly Offloader $offloader) {}

    /**
     * Offload attachment IDs or all attachments in bounded batches.
     *
     * ## OPTIONS
     * [<id>...] : Attachment IDs.
     * [--all] : Process all attachment records.
     * [--after=<id>] : Resume after an attachment ID.
     * [--limit=<number>] : Maximum attachments to inspect (default 100).
     * [--dry-run] : Inventory without uploading.
     * [--allow-visibility-change] : Explicitly copy files when public/private classification changed.
     */
    public function run(array $args, array $assoc): void
    {
        $limit = max(1, min(1000, (int) ($assoc['limit'] ?? 100)));
        $after = max(0, (int) ($assoc['after'] ?? 0));
        if (!empty($assoc['all'])) {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit));
        } else { $ids = array_map('intval', $args); }
        if (!$ids) { \WP_CLI::log('No attachments found.'); return; }
        $failed = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                if (!$this->offloader->eligible($id)) { \WP_CLI::log("{$id}: excluded"); continue; }
                if (!empty($assoc['dry-run'])) { $files = $this->offloader->inventory($id); \WP_CLI::log("{$id}: " . count($files) . ' files'); }
                else { $state = $this->offloader->offload($id, !empty($assoc['allow-visibility-change'])); \WP_CLI::log("{$id}: offloaded " . count($state['files']) . ' files'); }
            } catch (\Throwable $e) { ++$failed; \WP_CLI::warning("{$id}: " . $e->getMessage()); }
        }
        \WP_CLI::log('Resume after ID ' . (int) end($ids));
        if ($failed) { \WP_CLI::error("{$failed} attachment(s) failed."); }
    }

    /** Show the saved offload state and last error for an attachment. */
    public function status(array $args): void
    {
        if (empty($args[0])) { \WP_CLI::error('Provide an attachment ID.'); }
        $id = (int) $args[0];
        $state = $this->offloader->state($id);
        $error = get_post_meta($id, Offloader::ERROR, true);
        \WP_CLI::log(wp_json_encode(['id' => $id, 'offloaded' => $state !== null, 'objects' => $state ? count($state['files']) : 0, 'error' => $error ?: null], JSON_PRETTY_PRINT));
    }

    /** Restore verified files for an offloaded attachment without overwriting local files. */
    public function restore(array $args): void
    {
        if (empty($args[0])) { \WP_CLI::error('Provide an attachment ID.'); }
        try { $count = $this->offloader->restore((int) $args[0]); \WP_CLI::success("Restored {$count} files."); }
        catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
    }

    /** Remove local files after checking the entire attachment and all remote objects. */
    public function cleanup(array $args): void
    {
        if (empty($args[0])) { \WP_CLI::error('Provide an attachment ID.'); }
        try { $count = $this->offloader->cleanup((int) $args[0]); \WP_CLI::success("Removed {$count} verified local files."); }
        catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
    }

    /**
     * Copy an attachment to a different prefix of the configured buckets.
     *
     * ## OPTIONS
     * <id> : Attachment ID.
     * --prefix=<prefix> : Target environment prefix.
     * [--apply] : Perform the copy and update this database's attachment manifest.
     */
    public function promote(array $args, array $assoc): void
    {
        if (empty($args[0]) || empty($assoc['prefix'])) { \WP_CLI::error('Provide an attachment ID and --prefix.'); }
        if (empty($assoc['apply'])) { \WP_CLI::log('Dry run: promotion requires --apply.'); return; }
        try { $state = $this->offloader->promote((int) $args[0], (string) $assoc['prefix']); \WP_CLI::success('Promoted to ' . $state['directory']); }
        catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
    }

    /**
     * Export a JSON map from legacy upload URLs to offloaded URLs.
     *
     * ## OPTIONS
     * [--after=<id>] : Resume after an attachment ID.
     * [--limit=<number>] : Maximum attachment records (default 1000).
     * [--source-base=<url>] : Original uploads base URL; defaults to this site's uploads URL.
     * [--out=<path>] : Write the JSON map to a file.
     */
    public function map(array $args, array $assoc): void
    {
        global $wpdb;
        $after = max(0, (int) ($assoc['after'] ?? 0));
        $limit = max(1, min(10000, (int) ($assoc['limit'] ?? 1000)));
        $base = (string) ($assoc['source-base'] ?? wp_get_upload_dir()['baseurl']);
        if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) { \WP_CLI::error('Invalid source base URL.'); }
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit));
        $map = [];
        foreach ($ids as $id) {
            foreach ($this->offloader->legacyUrls((int) $id, $base) as $old => $new) {
                if (isset($map[$old]) && $map[$old] !== $new) { \WP_CLI::error('Conflicting attachment URLs: ' . $old); }
                $map[$old] = $new;
            }
        }
        $json = wp_json_encode((object) $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!empty($assoc['out'])) {
            if (file_put_contents((string) $assoc['out'], $json . "\n", LOCK_EX) === false) { \WP_CLI::error('Could not write map.'); }
            \WP_CLI::log('Wrote ' . count($map) . ' URL mappings. Resume after ID ' . ($ids ? (int) end($ids) : $after));
        } else { \WP_CLI::log($json); }
    }

    /**
     * Rewrite mapped URLs in post content, in batches; defaults to a dry run.
     *
     * ## OPTIONS
     * --map=<path> : JSON file created by the map command.
     * [--after=<id>] : Resume after a post ID.
     * [--limit=<number>] : Maximum posts to scan (default 100).
     * [--apply] : Save changes with WordPress post APIs.
     */
    public function rewrite_posts(array $args, array $assoc): void
    {
        global $wpdb;
        $rewriter = $this->rewriter($assoc);
        $after = max(0, (int) ($assoc['after'] ?? 0));
        $limit = max(1, min(1000, (int) ($assoc['limit'] ?? 100)));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_type NOT IN ('attachment', 'revision') ORDER BY ID ASC LIMIT %d", $after, $limit));
        foreach ($rows as $row) {
            $new = $rewriter->text($row->post_content);
            if ($new === $row->post_content) { continue; }
            if (!empty($assoc['apply'])) {
                $current = get_post((int) $row->ID);
                if (!$current || $current->post_content !== $row->post_content) { \WP_CLI::error('Post changed while scanning: ' . $row->ID); }
                $saved = wp_update_post(wp_slash(['ID' => (int) $row->ID, 'post_content' => $new]), true);
                if (is_wp_error($saved)) { \WP_CLI::error($saved->get_error_message()); }
            }
            \WP_CLI::log(($assoc['apply'] ?? false ? 'Updated ' : 'Would update ') . 'post ' . $row->ID);
        }
        \WP_CLI::log('Resume after ID ' . ($rows ? (int) end($rows)->ID : $after));
    }

    /**
     * Rewrite mapped URLs in non-protected post metadata, preserving serialized arrays; defaults to dry run.
     *
     * ## OPTIONS
     * --map=<path> : JSON file created by the map command.
     * [--after=<id>] : Resume after a meta ID.
     * [--limit=<number>] : Maximum meta records to scan (default 100).
     * [--apply] : Save changes through WordPress metadata APIs.
     */
    public function rewrite_meta(array $args, array $assoc): void
    {
        global $wpdb;
        $rewriter = $this->rewriter($assoc);
        $after = max(0, (int) ($assoc['after'] ?? 0));
        $limit = max(1, min(1000, (int) ($assoc['limit'] ?? 100)));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d AND meta_key NOT LIKE %s ORDER BY meta_id ASC LIMIT %d", $after, $wpdb->esc_like('_') . '%', $limit));
        foreach ($rows as $row) {
            $serialized = is_serialized($row->meta_value);
            $value = $serialized ? @unserialize($row->meta_value, ['allowed_classes' => false]) : $row->meta_value;
            if (UrlRewriter::containsObject($value)) { \WP_CLI::warning('Skipped object-valued meta ' . $row->meta_id); continue; }
            $new = $rewriter->value($value);
            if ($new === $value) { continue; }
            if (!empty($assoc['apply'])) {
                $current = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", (int) $row->meta_id));
                if ($current !== $row->meta_value) { \WP_CLI::error('Meta changed while scanning: ' . $row->meta_id); }
                if (!update_metadata_by_mid('post', (int) $row->meta_id, $new)) { \WP_CLI::error('Could not update meta ' . $row->meta_id); }
            }
            \WP_CLI::log(($assoc['apply'] ?? false ? 'Updated ' : 'Would update ') . 'meta ' . $row->meta_id);
        }
        \WP_CLI::log('Resume after meta ID ' . ($rows ? (int) end($rows)->meta_id : $after));
    }

    /**
     * Rewrite mapped URLs in non-protected site options, preserving serialized arrays; defaults to dry run.
     *
     * ## OPTIONS
     * --map=<path> : JSON file created by the map command.
     * [--after=<id>] : Resume after an option ID.
     * [--limit=<number>] : Maximum option records to scan (default 100).
     * [--apply] : Save changes through WordPress option APIs.
     */
    public function rewrite_options(array $args, array $assoc): void
    {
        global $wpdb;
        $rewriter = $this->rewriter($assoc);
        $after = max(0, (int) ($assoc['after'] ?? 0));
        $limit = max(1, min(1000, (int) ($assoc['limit'] ?? 100)));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND option_name NOT LIKE %s AND option_name NOT IN ('home', 'siteurl') ORDER BY option_id ASC LIMIT %d", $after, $wpdb->esc_like('_') . '%', $limit));
        foreach ($rows as $row) {
            $serialized = is_serialized($row->option_value);
            $value = $serialized ? @unserialize($row->option_value, ['allowed_classes' => false]) : $row->option_value;
            if (UrlRewriter::containsObject($value)) { \WP_CLI::warning('Skipped object-valued option ' . $row->option_id); continue; }
            $new = $rewriter->value($value);
            if ($new === $value) { continue; }
            if (!empty($assoc['apply'])) {
                $current = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", (int) $row->option_id));
                if ($current !== $row->option_value) { \WP_CLI::error('Option changed while scanning: ' . $row->option_id); }
                if (!update_option($row->option_name, $new)) { \WP_CLI::error('Could not update option ' . $row->option_id); }
            }
            \WP_CLI::log(($assoc['apply'] ?? false ? 'Updated ' : 'Would update ') . 'option ' . $row->option_id . ' (' . $row->option_name . ')');
        }
        \WP_CLI::log('Resume after option ID ' . ($rows ? (int) end($rows)->option_id : $after));
    }

    private function rewriter(array $assoc): UrlRewriter
    {
        if (empty($assoc['map']) || !is_readable($assoc['map'])) { \WP_CLI::error('Provide a readable --map file.'); }
        $json = file_get_contents($assoc['map']);
        $map = json_decode((string) $json, true);
        if (!is_array($map) || json_last_error() !== JSON_ERROR_NONE) { \WP_CLI::error('The map must be a JSON object.'); }
        try { return new UrlRewriter($map); }
        catch (\Throwable $e) { \WP_CLI::error($e->getMessage()); }
    }
}
