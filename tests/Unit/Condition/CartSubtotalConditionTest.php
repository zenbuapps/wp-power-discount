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

    public function testFloatEqualityTolerance(): void
    {
        // Accumulate a float subtotal that might drift
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = new \PowerDiscount\Domain\CartItem($i + 1, 'X', 0.1, 1, []);
        }
        $ctx = new \PowerDiscount\Domain\CartContext($items); // subtotal is ~1.0 (may drift)
        $c = new CartSubtotalCondition();
        self::assertTrue($c->evaluate(['operator' => '=', 'value' => 1.0], $ctx));
        self::assertFalse($c->evaluate(['operator' => '!=', 'value' => 1.0], $ctx));
    }
}
