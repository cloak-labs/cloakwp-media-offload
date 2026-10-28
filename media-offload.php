<?php
/**
 * Plugin Name: Media Offload
 * Description: Offload WordPress attachments to object storage.
 * Version: 0.1.0
 * Requires PHP: 8.2
 * License: LGPL-3.0-or-later
 */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }
if (is_readable(__DIR__ . '/vendor/autoload.php')) { require_once __DIR__ . '/vendor/autoload.php'; }
if (!class_exists(\CloakWP\MediaOffload\MediaOffload::class)) {
  spl_autoload_register(static function (string $class): void {
    $prefix = 'CloakWP\\MediaOffload\\';
    if (str_starts_with($class, $prefix)) {
      $path = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
      if (is_readable($path)) { require_once $path; }
    }
  });
}
add_action('init', static function (): void {
  if (!\CloakWP\MediaOffload\MediaOffload::booted()) {
    \CloakWP\MediaOffload\MediaOffload::make()->register();
  }
}, 1);
