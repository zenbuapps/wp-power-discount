<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\CartSubtotalCondition;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;

final class CartSubtotalConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('cart_subtotal', (new CartSubtotalCondition())->type());
    }

    public function testGreaterThanOrEqual(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 500.0, 2, [])]);
        $c = new CartSubtotalCondition();

        self::assertTrue($c->evaluate(['operator' => '>=', 'value' => 1000], $ctx));
        self::assertTrue($c->evaluate(['operator' => '>=', 'value' => 999], $ctx));
        self::assertFalse($c->evaluate(['operator' => '>=', 'value' => 1001], $ctx));
    }

    public function testAllOperators(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 5, [])]);
        $c = new CartSubtotalCondition();

        self::assertTrue($c->evaluate(['operator' => '>', 'value' => 499], $ctx));
        self::assertFalse($c->evaluate(['operator' => '>', 'value' => 500], $ctx));

        self::assertTrue($c->evaluate(['operator' => '=', 'value' => 500], $ctx));
        self::assertFalse($c->evaluate(['operator' => '=', 'value' => 499], $ctx));

        self::assertTrue($c->evaluate(['operator' => '<=', 'value' => 500], $ctx));
        self::assertFalse($c->evaluate(['operator' => '<=', 'value' => 499], $ctx));

        self::assertTrue($c->evaluate(['operator' => '<', 'value' => 501], $ctx));
        self::assertFalse($c->evaluate(['operator' => '<', 'value' => 500], $ctx));

        self::assertTrue($c->evaluate(['operator' => '!=', 'value' => 499], $ctx));
        self::assertFalse($c->evaluate(['operator' => '!=', 'value' => 500], $ctx));
    }

    public function testInvalidOperatorReturnsFalse(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);
        self::assertFalse((new CartSubtotalCondition())->evaluate(['operator' => '~~', 'value' => 1], $ctx));
    }

    public function testMissingConfigReturnsFalse(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);
        self::assertFalse((new CartSubtotalCondition())->evaluate([], $ctx));
    }
}
