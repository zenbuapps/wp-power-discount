<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\CartQuantityCondition;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;

final class CartQuantityConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('cart_quantity', (new CartQuantityCondition())->type());
    }

    public function testGreaterThanOrEqual(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 10.0, 5, [])]);
        $c = new CartQuantityCondition();
        self::assertTrue($c->evaluate(['operator' => '>=', 'value' => 5], $ctx));
        self::assertFalse($c->evaluate(['operator' => '>=', 'value' => 6], $ctx));
    }

    public function testMissingConfigReturnsFalse(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 10.0, 1, [])]);
        self::assertFalse((new CartQuantityCondition())->evaluate([], $ctx));
    }
}
