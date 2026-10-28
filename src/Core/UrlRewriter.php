<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Core;

/** Rewrites only complete known URLs, including escaped URLs in JSON text. */
final class UrlRewriter
{
    private array $map;
    public function __construct(array $map)
    {
        $this->map = [];
        foreach ($map as $old => $new) {
            if (!is_string($old) || !is_string($new) || !filter_var($old, FILTER_VALIDATE_URL) || !filter_var($new, FILTER_VALIDATE_URL)
                || !in_array(parse_url($old, PHP_URL_SCHEME), ['http', 'https'], true) || parse_url($new, PHP_URL_SCHEME) !== 'https') {
                throw new \InvalidArgumentException('The URL map must contain valid HTTP(S) source URLs and HTTPS destinations.');
            }
            $this->map[$old] = $new;
            $this->map[str_replace('/', '\\/', $old)] = str_replace('/', '\\/', $new);
        }
        uksort($this->map, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    }
    public function text(string $value): string
    {
        foreach ($this->map as $old => $new) {
            $value = preg_replace_callback('~' . preg_quote($old, '~') . '(?=$|[\\s"\'<>),\\]?#&])~u', static fn(): string => $new, $value) ?? $value;
        }
        return $value;
    }
    public function value(mixed $value): mixed
    {
        if (is_string($value)) { return $this->text($value); }
        if (is_array($value)) { foreach ($value as $key => $item) { $value[$key] = $this->value($item); } }
        return $value;
    }
    public static function containsObject(mixed $value): bool
    {
        if (is_object($value)) { return true; }
        if (is_array($value)) { foreach ($value as $item) { if (self::containsObject($item)) { return true; } } }
        return false;
    }
}
