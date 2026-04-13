<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\NthItemStrategy;

final class NthItemStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('nth_item', (new NthItemStrategy())->type());
    }

    public function testSecondItemHalfOffOneProduct(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 200.0, 2, [])]);
        // 1st: 0% off; 2nd: 50% off → 100
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testThreeTiers(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 40],
                ['nth' => 3, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);
        // 1st 0%, 2nd 40%, 3rd 50% → 0 + 40 + 50 = 90
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(90.0, $result->getAmount());
    }

    public function testBeyondMaxTierUsesLastWhenNotRecursive(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 4, [])]);
        // units 1 2 3 4 → 0% 50% 50% 50% = 0 + 50 + 50 + 50 = 150
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(150.0, $result->getAmount());
    }

    public function testRecursive(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => true,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 4, [])]);
        // Cycle 2: units 1 2 3 4 → 0% 50% 0% 50% = 0 + 50 + 0 + 50 = 100
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testSortByPriceAsc(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 100],
            ],
            'sort_by' => 'price_asc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Cheap', 50.0, 1, []),
            new CartItem(2, 'Expensive', 500.0, 1, []),
        ]);
        // ASC: cheapest (50) = tier 1 (0%), expensive (500) = tier 2 (100%) → 500 off
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(500.0, $result->getAmount());
    }

    public function testFlatMethod(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'flat', 'value' => 30],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        // 1st 0, 2nd flat $30 = 30 off
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule([
            'tiers' => [['nth' => 1, 'method' => 'percentage', 'value' => 10]],
            'sort_by' => 'price_desc',
        ]);
        self::assertNull((new NthItemStrategy())->apply($rule, new CartContext([])));
    }

    public function testEmptyTiersReturnsNull(): void
    {
        $rule = $this->rule(['tiers' => [], 'sort_by' => 'price_desc']);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        self::assertNull((new NthItemStrategy())->apply($rule, $ctx));
    }

    public function testZeroDiscountOverallReturnsNull(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
            ],
            'sort_by' => 'price_desc',
            'recursive' => true,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);
        // Everything at 0% off → null
        self::assertNull((new NthItemStrategy())->apply($rule, $ctx));
    }

    public function testSparseTiersReturnNull(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 3, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);
        self::assertNull((new NthItemStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'nth_item', 'config' => $config]);
    }
}
