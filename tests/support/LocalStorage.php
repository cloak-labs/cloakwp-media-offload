<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Tests;
use CloakWP\MediaOffload\Contracts\Storage;
/** Local-only test double. Do not use this as production object storage. */
final class LocalStorage implements Storage {
    public function __construct(private readonly string $root) {}
    private function path(string $key): string {
        if ($key === '' || str_contains($key, '..') || str_starts_with($key, '/')) throw new \RuntimeException('Unsafe key');
        return $this->root . '/' . $key;
    }
    public function put(string $key, string $path, string $mime): void {
        $target = $this->path($key);
        if (is_file($target)) {
            if (hash_file('sha256', $target) !== hash_file('sha256', $path)) throw new \RuntimeException('Collision');
            return;
        }
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) throw new \RuntimeException('Cannot create store');
        if (!copy($path, $target)) throw new \RuntimeException('Cannot copy object');
    }
    public function inspect(string $key): ?array {
        $path = $this->path($key);
        return is_file($path) ? ['size' => filesize($path), 'sha256' => hash_file('sha256', $path)] : null;
    }
    public function download(string $key, string $path): void {
        if (!copy($this->path($key), $path)) throw new \RuntimeException('Cannot download object');
    }
}
