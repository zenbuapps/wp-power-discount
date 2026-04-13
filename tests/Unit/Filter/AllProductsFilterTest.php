<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Filter\AllProductsFilter;

final class AllProductsFilterTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('all_products', (new AllProductsFilter())->type());
    }

    public function testAlwaysMatches(): void
    {
        $f = new AllProductsFilter();
        self::assertTrue($f->matches([], new CartItem(1, 'A', 100.0, 1, [])));
        self::assertTrue($f->matches([], new CartItem(99, 'Z', 10.0, 5, [2, 3])));
    }
}
