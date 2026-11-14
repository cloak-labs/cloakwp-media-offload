<?php
declare(strict_types=1);

namespace {
    function get_current_blog_id(): int { return 2; }
    class WP_CLI
    {
        public static function error(string $message): void { throw new \RuntimeException($message); }
    }
}

namespace CloakWP\MediaOffload\Tests {
    use CloakWP\MediaOffload\Contracts\Storage;
    use CloakWP\MediaOffload\Core\Config;
    use CloakWP\MediaOffload\Core\Offloader;
    use CloakWP\MediaOffload\Plugin\Command;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class ScopedCommandTest extends TestCase
    {
        #[DataProvider('operations')]
        public function testCommandsRejectAnUnallowedSite(string $method, array $args): void
        {
            $config = new Config($this->createStub(Storage::class), 'https://example.test', 'prefix', true, false, null, null, [1]);
            $command = new Command(new Offloader($config));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Media offloading is not enabled for this site.');
            $command->$method(...$args);
        }

        public static function operations(): array
        {
            return [
                ['run', [[], []]], ['status', [[]]], ['report', [[], []]], ['restore', [[]]],
                ['cleanup', [[]]], ['promote', [[], []]], ['map', [[], []]], ['verify', [[]]],
                ['rewrite_posts', [[], []]], ['rewrite_meta', [[], []]], ['rewrite_options', [[], []]],
            ];
        }
    }
}
