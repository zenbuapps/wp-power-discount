<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Engine\Aggregator;

final class AggregatorTest extends TestCase
{
    public function testEmptyInputReturnsZeroBuckets(): void
    {
        $agg = new Aggregator();
        $summary = $agg->aggregate([]);
        self::assertSame(0.0, $summary->productTotal());
        self::assertSame(0.0, $summary->cartTotal());
        self::assertSame(0.0, $summary->shippingTotal());
        self::assertSame([], $summary->results());
    }

    public function testGroupsByScope(): void
    {
        $agg = new Aggregator();
        $results = [
            new DiscountResult(1, 'simple', 'product', 30.0, [1], null, []),
            new DiscountResult(2, 'cart', 'cart', 100.0, [], null, []),
            new DiscountResult(3, 'free_shipping', 'shipping', 50.0, [], null, []),
            new DiscountResult(4, 'simple', 'product', 20.0, [2], null, []),
        ];

        $summary = $agg->aggregate($results);
        self::assertSame(50.0, $summary->productTotal());
        self::assertSame(100.0, $summary->cartTotal());
        self::assertSame(50.0, $summary->shippingTotal());
        self::assertCount(4, $summary->results());
    }

    public function testIgnoresZeroDiscountResults(): void
    {
        $agg = new Aggregator();
        $results = [
            new DiscountResult(1, 'simple', 'product', 0.0, [], null, []),
            new DiscountResult(2, 'cart', 'cart', 25.0, [], null, []),
        ];
        $summary = $agg->aggregate($results);
        self::assertSame(0.0, $summary->productTotal());
        self::assertSame(25.0, $summary->cartTotal());
        self::assertCount(1, $summary->results());
    }
}
