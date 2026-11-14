<?php
declare(strict_types=1);

namespace CloakWP\MediaOffload\Core;

final class Manifest
{
    public static function valid(mixed $state): bool
    {
        if (!is_array($state) || !in_array($state['version'] ?? null, [1, 2], true)
            || !self::path($state['directory'] ?? null)
            || !is_string($state['url'] ?? null) || !filter_var($state['url'], FILTER_VALIDATE_URL)
            || parse_url($state['url'], PHP_URL_SCHEME) !== 'https'
            || parse_url($state['url'], PHP_URL_USER) !== null || parse_url($state['url'], PHP_URL_PASS) !== null
            || parse_url($state['url'], PHP_URL_QUERY) !== null || parse_url($state['url'], PHP_URL_FRAGMENT) !== null
            || !is_bool($state['private'] ?? null) || !self::digest($state['metadata_hash'] ?? null)
            || !self::filename($state['main'] ?? null) || !is_array($state['files'] ?? null)
            || !isset($state['files'][$state['main']])) {
            return false;
        }
        if ($state['version'] === 2 && (!self::path($state['relative'] ?? null)
            || basename($state['relative']) !== $state['main']
            || !self::digest($state['backups_hash'] ?? null)
            || !is_string($state['storage'] ?? null) || strlen($state['storage']) > 1024)) {
            return false;
        }
        foreach ($state['files'] as $name => $entry) {
            if (!self::filename($name) || !is_array($entry)
                || ($entry['key'] ?? null) !== $state['directory'] . '/' . $name
                || !is_int($entry['size'] ?? null) || $entry['size'] < 0
                || !self::digest($entry['sha256'] ?? null)) {
                return false;
            }
        }
        return true;
    }

    public static function filename(mixed $name): bool
    {
        return is_string($name) && $name !== '' && $name !== '.' && $name !== '..'
            && !str_contains($name, '/') && !str_contains($name, '\\')
            && !preg_match('/[\x00-\x1f\x7f]/', $name);
    }

    public static function path(mixed $path): bool
    {
        return is_string($path) && !array_filter(explode('/', $path), static fn($name) => !self::filename($name));
    }

    private static function digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    public static function url(array $state, string $name): string
    {
        return rtrim($state['url'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $state['files'][$name]['key'])));
    }
}
