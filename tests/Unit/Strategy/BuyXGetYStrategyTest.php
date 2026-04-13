<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\BuyXGetYStrategy;

final class BuyXGetYStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('buy_x_get_y', (new BuyXGetYStrategy())->type());
    }

    public function testBuyOneGetOneSameFree(): void
    {
        // Buy 1 of product 1, get 1 of product 1 free
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        // 2 units in cart: 1 is trigger, 1 is reward. Reward = 1 × 100 × 100% = 100
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testBuyTwoGetOneSameHalfOff(): void
    {
        // Buy 2, get 1 at 50% off — same product
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 2],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'percentage', 'value' => 50],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 200.0, 3, [])]);
        // 3 units: 2 trigger + 1 reward. Discount = 200 * 50% * 1 = 100
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testRecursive(): void
    {
        // Buy 1 Get 1 Free, recursive
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => true,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 4, [])]);
        // 4 units = 2 rounds × (1 trigger + 1 reward). Total free = 2 × 100 = 200
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(200.0, $result->getAmount());
    }

    public function testNonRecursiveCaps(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 10, [])]);
        // Non-recursive: at most 1 free
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testInsufficientTriggerQty(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 3],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);
        // Only 3 units → 3 trigger, 0 reward available → null
        self::assertNull((new BuyXGetYStrategy())->apply($rule, $ctx));
    }

    public function testSpecificTriggerSource(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'specific', 'qty' => 1, 'product_ids' => [10]],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(10, 'Target', 100.0, 2, []),
            new CartItem(20, 'Other',  200.0, 5, []),
        ]);
        // Only product 10 qualifies. 2 units: 1 trigger + 1 reward = 100 off.
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testSpecificRewardTarget(): void
    {
        // Buy any 1 → get product 99 free (product 99 must already be in cart)
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => [
                'target' => 'specific', 'qty' => 1, 'method' => 'free', 'value' => 0,
                'product_ids' => [99],
            ],
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Any', 300.0, 1, []),
            new CartItem(99, 'Free gift', 50.0, 1, []),
        ]);
        // Discount = 50
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(50.0, $result->getAmount());
    }

    public function testCheapestInCart(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 2],
            'reward'  => ['target' => 'cheapest_in_cart', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Cheap', 50.0, 1, []),
            new CartItem(2, 'Expensive', 500.0, 2, []),
        ]);
        // 3 units total. 2 are trigger (pick highest 2 — product 2 two units: 1000). 1 reward = cheapest remaining.
        // After taking 2 trigger units (best = 2 × 500 = 1000), cheapest remaining is product 1 at 50.
        // Reward 1 × 50 × 100% = 50 off
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(50.0, $result->getAmount());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
        ]);
        self::assertNull((new BuyXGetYStrategy())->apply($rule, new CartContext([])));
    }

    public function testInvalidConfigReturnsNull(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 0],
            'reward'  => ['target' => 'same', 'qty' => 0, 'method' => 'free'],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 5, [])]);
        self::assertNull((new BuyXGetYStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'buy_x_get_y', 'config' => $config]);
    }
}
