<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Filter\CategoriesFilter;

final class CategoriesFilterTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('categories', (new CategoriesFilter())->type());
    }

    public function testInListMatches(): void
    {
        $f = new CategoriesFilter();
        $config = ['method' => 'in', 'ids' => [12, 13]];

        self::assertTrue($f->matches($config, new CartItem(1, 'A', 100.0, 1, [12])));
        self::assertTrue($f->matches($config, new CartItem(2, 'B', 100.0, 1, [13, 14])));
        self::assertFalse($f->matches($config, new CartItem(3, 'C', 100.0, 1, [14])));
    }

    public function testNotInListMatches(): void
    {
        $f = new CategoriesFilter();
        $config = ['method' => 'not_in', 'ids' => [99]];

        self::assertTrue($f->matches($config, new CartItem(1, 'A', 100.0, 1, [12])));
        self::assertFalse($f->matches($config, new CartItem(2, 'B', 100.0, 1, [99])));
    }

    public function testEmptyIdsInListNeverMatches(): void
    {
        $f = new CategoriesFilter();
        self::assertFalse($f->matches(['method' => 'in', 'ids' => []], new CartItem(1, 'A', 100.0, 1, [1])));
    }

    public function testEmptyIdsNotInAlwaysMatches(): void
    {
        $f = new CategoriesFilter();
        self::assertTrue($f->matches(['method' => 'not_in', 'ids' => []], new CartItem(1, 'A', 100.0, 1, [1])));
    }

    public function testDefaultMethodIsIn(): void
    {
        $f = new CategoriesFilter();
        self::assertTrue($f->matches(['ids' => [10]], new CartItem(1, 'A', 100.0, 1, [10])));
    }
}
