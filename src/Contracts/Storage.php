<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Contracts;

interface Storage
{
    /** Upload without overwriting an existing key with different bytes. */
    public function put(string $key, string $path, string $mime): void;
    /** Return the number of bytes stored, or null if absent. */
    public function inspect(string $key): ?array;
    /** Download to a local file; implementations must throw on failure. */
    public function download(string $key, string $path): void;
}
