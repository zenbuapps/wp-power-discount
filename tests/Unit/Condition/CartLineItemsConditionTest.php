<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\CartLineItemsCondition;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;

final class CartLineItemsConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('cart_line_items', (new CartLineItemsCondition())->type());
    }

    public function testLineItemCount(): void
    {
        $ctx = new CartContext([
            new CartItem(1, 'A', 10.0, 5, []),
            new CartItem(2, 'B', 10.0, 3, []),
        ]);
        $c = new CartLineItemsCondition();
        // 2 distinct line items
        self::assertTrue($c->evaluate(['operator' => '=', 'value' => 2], $ctx));
        self::assertFalse($c->evaluate(['operator' => '>', 'value' => 2], $ctx));
    }

    public function testMissingConfigReturnsFalse(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 10.0, 1, [])]);
        self::assertFalse((new CartLineItemsCondition())->evaluate([], $ctx));
    }
}
