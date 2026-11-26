<?php
declare(strict_types=1);

namespace CloakWP\MediaOffload\Plugin;

use CloakWP\MediaOffload\Core\AttachmentFiles;
use CloakWP\MediaOffload\Core\Offloader;

final class LocalFiles
{
    private array $deleting = [];

    public function __construct(private readonly Offloader $offloader) {}

    public function register(): void
    {
        add_filter('wp_unique_filename', [$this, 'uniqueFilename'], PHP_INT_MAX, 5);
        add_action('delete_attachment', [$this, 'captureDeletion'], 10);
        add_filter('wp_delete_file', [$this, 'protectReferencedFile'], PHP_INT_MAX);
    }

    public function captureDeletion(int $id): void
    {
        $state = $this->offloader->state($id);
        $path = AttachmentFiles::attached($id);
        if (!$state || !$this->offloader->current($id) || !is_string($path)) {
            return;
        }
        foreach ($state['files'] as $name => $entry) {
            $this->deleting[get_current_blog_id() . ':' . dirname($path) . '/' . $name] = $entry['sha256'];
        }
    }

    public function uniqueFilename(string $filename, string $extension, string $directory, mixed $callback, array $alternate): string
    {
        if (!$this->offloader->siteAllowed() || !AttachmentFiles::isUploadsDirectory($directory)) {
            return $filename;
        }
        $owners = AttachmentFiles::owners($directory);
        $disk = @scandir($directory) ?: [];
        $reserved = array_values(array_unique(array_merge(array_map('strtolower', $disk), array_keys($owners))));
        $alternates = array_diff(array_keys($alternate), [$extension]);
        // Core checks the filesystem before it writes. Separate simultaneous
        // uploads even if neither request can see the other's file yet.
        $addSuffix = $callback === null && (bool) array_intersect(['_wp_handle_upload', 'wp_upload_bits'],
            array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12), 'function'));
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $length = max(0, 220 - strlen($extension));
        $stem = mb_strcut($stem, 0, $length, 'UTF-8');
        do {
            $name = $addSuffix ? $stem . '-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 16) . $extension : $filename;
            $candidateStem = pathinfo($name, PATHINFO_FILENAME);
            $candidates = [$name];
            foreach ($alternates as $otherExtension) {
                $candidates[] = $candidateStem . $otherExtension;
            }
            if (!$this->conflicts($candidates, $reserved)) {
                return $name;
            }
            $addSuffix = true;
        } while (true);
    }

    private function conflicts(array $names, array $reserved): bool
    {
        foreach ($names as $name) {
            if (in_array(strtolower($name), $reserved, true) || _wp_check_existing_file_names($name, $reserved)) {
                return true;
            }
        }
        return false;
    }

    public function protectReferencedFile(mixed $file): mixed
    {
        if (!is_string($file) || $file === '' || !$this->offloader->siteAllowed()
            || !AttachmentFiles::isUploadsDirectory(dirname($file))) {
            return $file;
        }
        // Core removes the deleted record and its metadata before deleting its
        // files. Remaining claims belong to another attachment. Core also calls
        // this path while replacing sizes, where its own old claim may remain.
        if (!in_array('wp_delete_attachment_files', array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16), 'function'), true)) {
            return $file;
        }
        $key = get_current_blog_id() . ':' . $file;
        $expected = $this->deleting[$key] ?? null;
        unset($this->deleting[$key]);
        if ($expected !== null && (is_link($file) || (is_file($file) && hash_file('sha256', $file) !== $expected))) {
            return '';
        }
        try {
            $owners = AttachmentFiles::owners(dirname($file));
            return isset($owners[strtolower(basename($file))]) ? '' : $file;
        } catch (\RuntimeException) {
            return '';
        }
    }
}
