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
        if (!$this->config) { throw new \LogicException('Configure storage first.'); }
        $copy = clone $this;
        $c = $this->config;
        $copy->config = new Config($c->storage, $c->publicUrl, $c->prefix, $c->automatic, $remove, $c->privateStorage, $c->privateDirectory, $c->siteIds);
        return $copy;
    }
    public function automatic(bool $automatic = true): self
    {
        if (!$this->config) { throw new \LogicException('Configure storage first.'); }
        $copy = clone $this;
        $c = $this->config;
        $copy->config = new Config($c->storage, $c->publicUrl, $c->prefix, $automatic, $c->removeLocal, $c->privateStorage, $c->privateDirectory, $c->siteIds);
        return $copy;
    }
    public function privateStorage(Storage $storage): self
    {
        if (!$this->config) { throw new \LogicException('Configure storage first.'); }
        $copy = clone $this;
        $c = $this->config;
        $copy->config = new Config($c->storage, $c->publicUrl, $c->prefix, $c->automatic, $c->removeLocal, $storage, $c->privateDirectory, $c->siteIds);
        return $copy;
    }
    public function privateDirectory(string $directory): self
    {
        if (!$this->config) { throw new \LogicException('Configure storage first.'); }
        $copy = clone $this;
        $c = $this->config;
        $copy->config = new Config($c->storage, $c->publicUrl, $c->prefix, $c->automatic, $c->removeLocal, $c->privateStorage, $directory, $c->siteIds);
        return $copy;
    }
    /** Limit transfers and manifest access to the listed multisite IDs. */
    public function siteIds(array $siteIds): self
    {
        if (!$this->config) { throw new \LogicException('Configure storage first.'); }
        $copy = clone $this;
        $c = $this->config;
        $copy->config = new Config($c->storage, $c->publicUrl, $c->prefix, $c->automatic, $c->removeLocal, $c->privateStorage, $c->privateDirectory, $siteIds);
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
