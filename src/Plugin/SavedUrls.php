<?php
declare(strict_types=1);

namespace CloakWP\MediaOffload\Plugin;

use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Core\AttachmentFiles;
use CloakWP\MediaOffload\Core\Manifest;

/** Rewrite local upload references at read time so saved content stays portable. */
final class SavedUrls
{
    private array $matches = [];

    public function __construct(private readonly Offloader $offloader) {}

    public function register(): void
    {
        add_filter('render_block', [$this, 'block'], 9, 2);
        add_filter('the_content', [$this, 'html'], 11);
        add_filter('acf/format_value/type=url', [$this, 'field']);
        add_filter('acf/format_value/type=link', [$this, 'field']);
        add_filter('acf/format_value/type=wysiwyg', [$this, 'field']);
        add_filter('cloakwp/block', [$this, 'structuredBlock'], 20);
    }

    public function block(string $html, array $block): string
    {
        return $this->html($html, (int) ($block['attrs']['id'] ?? $block['attrs']['mediaId'] ?? 0));
    }

    public function field(mixed $value): mixed
    {
        if (is_array($value) && is_string($value['url'] ?? null)) {
            $value['url'] = $this->url($value['url']);
        } elseif (is_string($value)) {
            return str_contains($value, '<') ? $this->html($value) : $this->url($value);
        }
        return $value;
    }

    public function structuredBlock(array $block): array
    {
        if (!$this->offloader->siteAllowed() || !is_array($block['attrs'] ?? null)) {
            return $block;
        }
        $id = (int) ($block['attrs']['id'] ?? $block['attrs']['mediaId'] ?? 0);
        foreach (['src', 'url', 'href', 'poster', 'blob'] as $key) {
            if (is_string($block['attrs'][$key] ?? null)) {
                $block['attrs'][$key] = $this->url($block['attrs'][$key], $id);
            }
        }
        return $block;
    }

    public function html(string $html, int $attachmentId = 0): string
    {
        if (!$this->offloader->siteAllowed() || !str_contains($html, $this->uploadsPath() . '/')) {
            return $html;
        }
        $tags = new \WP_HTML_Tag_Processor($html);
        while ($tags->next_tag()) {
            $id = $attachmentId;
            $class = $tags->get_attribute('class');
            if (is_string($class) && preg_match('/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $class, $match)) {
                $id = (int) $match[1];
            }
            foreach (['src', 'href', 'poster'] as $attribute) {
                $value = $tags->get_attribute($attribute);
                if (is_string($value) && ($url = $this->url($value, $id)) !== $value) {
                    if ($url === '') { $tags->remove_attribute($attribute); }
                    else { $tags->set_attribute($attribute, $url); }
                }
            }
            $srcset = $tags->get_attribute('srcset');
            if (is_string($srcset)) {
                $private = false;
                $new = preg_replace_callback('/(?:^|,\s*)([^\s,]+)(?=\s+\d+(?:\.\d+)?[wx]|\s*,|$)/',
                    function (array $match) use ($id, &$private): string {
                        $url = $this->url($match[1], $id);
                        $private = $private || $url === '';
                        return substr($match[0], 0, strlen($match[0]) - strlen($match[1])) . $url;
                    }, $srcset);
                if ($private) { $tags->remove_attribute('srcset'); }
                elseif ($new !== null && $new !== $srcset) {
                    $tags->set_attribute('srcset', $new);
                }
            }
        }
        return $tags->get_updated_html();
    }

    private function uploadsPath(): string
    {
        return rtrim((string) wp_parse_url(wp_get_upload_dir()['baseurl'], PHP_URL_PATH), '/');
    }

    private function url(string $url, int $id = 0): string
    {
        if (!$this->offloader->siteAllowed()) {
            return $url;
        }
        $base = wp_get_upload_dir()['baseurl'];
        $parts = wp_parse_url($url);
        $baseParts = wp_parse_url($base);
        if (!is_array($parts) || !is_array($baseParts)
            || (isset($parts['host']) && (strcasecmp($parts['host'], $baseParts['host'] ?? '') !== 0
                || ($parts['port'] ?? null) !== ($baseParts['port'] ?? null)))) {
            return $url;
        }
        $path = $parts['path'] ?? '';
        $basePath = rtrim($baseParts['path'] ?? '', '/');
        if (!str_starts_with($path, $basePath . '/')) {
            return $url;
        }
        $relative = rawurldecode(substr($path, strlen($basePath) + 1));
        if (!Manifest::path($relative)) { return $url; }
        $name = basename($relative);
        $directory = dirname(rtrim(wp_get_upload_dir()['basedir'], '/') . '/' . $relative);
        $key = get_current_blog_id() . ':' . $directory;
        if (!$id) {
            try {
                $this->matches[$key] ??= AttachmentFiles::owners($directory);
            } catch (\RuntimeException) {
                return $url;
            }
            $ids = array_unique($this->matches[$key][strtolower($name)] ?? []);
            if (count($ids) !== 1) {
                return $url;
            }
            $id = reset($ids);
        }
        $attached = AttachmentFiles::attached($id);
        if (!is_string($attached) || dirname($attached) !== $directory) { return $url; }
        $state = $this->offloader->state($id);
        if ((!empty($state['private']) || $this->offloader->isPrivate($id))
            && (basename($attached) === $name || isset($state['files'][$name]))) {
            return '';
        }
        $replacement = $this->offloader->urlFor($id, $name);
        if ($replacement === null) {
            return $url;
        }
        return $replacement . (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }
}
