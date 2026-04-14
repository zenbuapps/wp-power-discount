<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;

final class CartContextTest extends TestCase
{
    public function testEmptyContext(): void
    {
        $ctx = new CartContext([]);
        self::assertTrue($ctx->isEmpty());
        self::assertSame(0, $ctx->getTotalQuantity());
        self::assertSame(0.0, $ctx->getSubtotal());
        self::assertSame([], $ctx->getItems());
    }

    public function testSubtotalAndQuantity(): void
    {
        $items = [
            new CartItem(101, 'Coffee Beans', 300.0, 2, [12]),
            new CartItem(102, 'Filter', 50.0, 3, [13]),
        ];
        $ctx = new CartContext($items);

        self::assertFalse($ctx->isEmpty());
        self::assertSame(5, $ctx->getTotalQuantity());
        self::assertSame(300.0 * 2 + 50.0 * 3, $ctx->getSubtotal());
        self::assertCount(2, $ctx->getItems());
    }

    public function testGetItemsInCategories(): void
    {
        $items = [
            new CartItem(1, 'A', 100.0, 1, [10, 20]),
            new CartItem(2, 'B', 100.0, 1, [20]),
            new CartItem(3, 'C', 100.0, 1, [30]),
        ];
        $ctx = new CartContext($items);

        $matched = $ctx->getItemsInCategories([20]);
        self::assertCount(2, $matched);

        $none = $ctx->getItemsInCategories([99]);
        self::assertCount(0, $none);
    }

    public function testGetItemsByProductIds(): void
    {
        $items = [
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
        ];
        $ctx = new CartContext($items);

        self::assertCount(1, $ctx->getItemsByProductIds([1]));
        self::assertCount(0, $ctx->getItemsByProductIds([999]));
    }

    public function testCartItemAccessors(): void
    {
        $item = new CartItem(1, 'Widget', 199.5, 3, [5, 6]);
        self::assertSame(1, $item->getProductId());
        self::assertSame('Widget', $item->getName());
        self::assertSame(199.5, $item->getPrice());
        self::assertSame(3, $item->getQuantity());
        self::assertSame([5, 6], $item->getCategoryIds());
        self::assertSame(199.5 * 3, $item->getLineTotal());
    }

    public function testCartItemRejectsNegativeQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CartItem(1, 'X', 10.0, -1, []);
    }

    public function testCartItemRejectsNegativePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CartItem(1, 'X', -1.0, 1, []);
    }

    public function testCartItemExtendedFields(): void
    {
        $item = new CartItem(
            1, 'Widget', 100.0, 1,
            [10],
            [20, 21],
            ['color' => ['red', 'blue'], 'size' => ['L']],
            true
        );
        self::assertSame([20, 21], $item->getTagIds());
        self::assertSame(['color' => ['red', 'blue'], 'size' => ['L']], $item->getAttributes());
        self::assertTrue($item->isOnSale());
    }

    public function testCartItemDefaultsForExtendedFields(): void
    {
        $item = new CartItem(1, 'X', 10.0, 1, []);
        self::assertSame([], $item->getTagIds());
        self::assertSame([], $item->getAttributes());
        self::assertFalse($item->isOnSale());
    }

    public function testIsInTags(): void
    {
        $item = new CartItem(1, 'X', 10.0, 1, [], [5, 6]);
        self::assertTrue($item->isInTags([5]));
        self::assertTrue($item->isInTags([7, 6]));
        self::assertFalse($item->isInTags([99]));
    }

    public function testHasAttribute(): void
    {
        $item = new CartItem(1, 'X', 10.0, 1, [], [], ['color' => ['red', 'blue']]);
        self::assertTrue($item->hasAttribute('color', ['red']));
        self::assertTrue($item->hasAttribute('color', ['green', 'blue']));
        self::assertFalse($item->hasAttribute('color', ['green']));
        self::assertFalse($item->hasAttribute('size', ['L']));
    }
}
