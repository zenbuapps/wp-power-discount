<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Engine\ExclusivityResolver;

final class ExclusivityResolverTest extends TestCase
{
    public function testShouldStopAfterExclusiveRule(): void
    {
        $resolver = new ExclusivityResolver();
        $exclusive = new Rule(['title' => 'x', 'type' => 'simple', 'exclusive' => true]);
        self::assertTrue($resolver->shouldStopAfter($exclusive));
    }

    public function testShouldNotStopAfterNonExclusive(): void
    {
        $resolver = new ExclusivityResolver();
        $normal = new Rule(['title' => 'x', 'type' => 'simple', 'exclusive' => false]);
        self::assertFalse($resolver->shouldStopAfter($normal));
    }
}
