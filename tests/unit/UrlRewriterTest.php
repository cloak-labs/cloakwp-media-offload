<?php
declare(strict_types=1);
namespace CloakWP\MediaOffload\Tests;
use CloakWP\MediaOffload\Core\UrlRewriter;
use PHPUnit\Framework\TestCase;
final class UrlRewriterTest extends TestCase
{
    public function testRewritesOnlyCompleteKnownUrlsAndEscapedJson(): void
    {
        $old = 'https://old.example.test/uploads/photo.jpg';
        $new = 'https://media.example.test/revision/photo.jpg';
        $rewriter = new UrlRewriter([$old => $new]);
        self::assertSame('<img src="' . $new . '?v=1">', $rewriter->text('<img src="' . $old . '?v=1">'));
        self::assertSame($old . '.backup', $rewriter->text($old . '.backup'));
        self::assertSame(json_encode(['src' => $new]), $rewriter->text(json_encode(['src' => $old])));
        self::assertSame(['src' => [$new, 1]], $rewriter->value(['src' => [$old, 1]]));
        self::assertTrue(UrlRewriter::containsObject(['nested' => (object) ['url' => $old]]));
    }
    public function testRejectsUnsafeDestination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UrlRewriter(['https://old.test/a' => 'javascript:alert(1)']);
    }
}
