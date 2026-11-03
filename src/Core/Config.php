<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Core;
use CloakWP\MediaOffload\Contracts\Storage;
use CloakWP\MediaOffload\Storage\S3Storage;

final class Config
{
    public function __construct(
        public readonly Storage $storage,
        public readonly string $publicUrl,
        public readonly string $prefix,
        public readonly bool $automatic = true,
        public readonly bool $removeLocal = false,
        public readonly ?Storage $privateStorage = null,
        public readonly ?string $privateDirectory = null,
        public readonly ?array $siteIds = null,
    ) {
        $parts = parse_url($publicUrl);
        if (!filter_var($publicUrl, FILTER_VALIDATE_URL) || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('The public media URL must be an HTTPS URL.');
        }
        if ($prefix === '' || !preg_match('~^[a-zA-Z0-9_/-]+$~', $prefix) || str_contains($prefix, '..')) {
            throw new \InvalidArgumentException('A safe, non-empty environment prefix is required.');
        }
        if ($privateStorage !== null && $privateStorage === $storage) {
            throw new \InvalidArgumentException('Private storage must use a separate adapter and a non-public bucket.');
        }
        if ($privateStorage instanceof S3Storage && (!$privateStorage->isPrivate()
            || ($storage instanceof S3Storage && $storage->identity() === $privateStorage->identity()))) {
            throw new \InvalidArgumentException('Private S3 storage must use private mode and a separate bucket.');
        }
        if ($siteIds !== null && (!$siteIds || array_filter($siteIds, static fn($id) => !is_int($id) || $id < 1))) {
            throw new \InvalidArgumentException('Site IDs must be a non-empty list of positive integers.');
        }
    }
}
