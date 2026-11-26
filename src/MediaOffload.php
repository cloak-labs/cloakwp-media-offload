<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload;
use CloakWP\MediaOffload\Contracts\Storage;
use CloakWP\MediaOffload\Core\Config;
use CloakWP\MediaOffload\Core\Offloader;
use CloakWP\MediaOffload\Plugin\Integration;
use CloakWP\MediaOffload\Plugin\Command;

final class MediaOffload
{
    private static bool $booted = false;
    private ?Config $config = null;
    public static function make(): self { return new self(); }
    public static function booted(): bool { return self::$booted; }
    public function storage(Storage $storage, string $publicUrl, string $prefix): self
    {
        $copy = clone $this;
        $copy->config = new Config($storage, rtrim($publicUrl, '/'), trim($prefix, '/'));
        return $copy;
    }
    public function removeLocalFiles(bool $remove = true): self
    {
        return $this->with(removeLocal: $remove);
    }
    public function automatic(bool $automatic = true): self
    {
        return $this->with(automatic: $automatic);
    }
    public function privateStorage(Storage $storage): self
    {
        return $this->with(privateStorage: $storage);
    }
    public function privateDirectory(string $directory): self
    {
        return $this->with(privateDirectory: $directory);
    }
    /** Limit transfers and manifest access to the listed multisite IDs. */
    public function siteIds(array $siteIds): self
    {
        return $this->with(siteIds: $siteIds);
    }
    public function publicSources(array $sources): self
    {
        return $this->with(publicSources: $sources);
    }
    public function verifyPublicDelivery(bool $verify = true): self
    {
        return $this->with(verifyPublicDelivery: $verify);
    }
    public function writes(bool $enabled): self
    {
        return $this->with(writeEnabled: $enabled);
    }
    public function preferLocalDelivery(bool $enabled = true): self
    {
        return $this->with(preferLocalDelivery: $enabled);
    }
    private function with(mixed ...$settings): self
    {
        if (!$this->config) { throw new \LogicException('Configure storage first.'); }
        $copy = clone $this;
        $copy->config = new Config(...array_replace(get_object_vars($this->config), $settings));
        return $copy;
    }
    public function register(): void
    {
        if (self::$booted) { return; }
        self::$booted = true;
        $config = function_exists('apply_filters') ? apply_filters('cloakwp/media-offload/config', $this->config) : $this->config;
        if (!$config instanceof Config) { return; }
        $offloader = new Offloader($config);
        (new Integration($offloader, $config))->register();
        if (defined('WP_CLI') && WP_CLI) { \WP_CLI::add_command('media offload', new Command($offloader)); }
    }
}
