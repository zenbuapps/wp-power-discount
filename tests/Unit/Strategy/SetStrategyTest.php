<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\SetStrategy;

final class SetStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('set', (new SetStrategy())->type());
    }

    public function testSetPriceAnyTwoForNinety(): void
    {
        // 任選 2 件 $90
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90, 'repeat' => true]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 60.0, 1, []),
            new CartItem(2, 'B', 60.0, 1, []),
        ]);
        // Original bundle total: 60 + 60 = 120. Set price 90. Discount = 30.
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testSetPriceRepeatsWhenPossible(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90, 'repeat' => true]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 60.0, 4, []),
        ]);
        // 4 items = 2 bundles. Each bundle: original 120, set 90, discount 30. Total = 60.
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(60.0, $result->getAmount());
    }

    public function testSetPriceNoRepeatOnlyOneBundle(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 60.0, 4, []),
        ]);
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testSetPercentageThreeFor90Percent(): void
    {
        // 任選 3 件 9 折
        $rule = $this->rule(['bundle_size' => 3, 'method' => 'set_percentage', 'value' => 10, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
            new CartItem(3, 'C', 100.0, 1, []),
        ]);
        // bundle total 300 * 10% = 30
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testSetFlatOffFourForHundredOff(): void
    {
        // 任選 4 件現折 $100  (WDR 做不到)
        $rule = $this->rule(['bundle_size' => 4, 'method' => 'set_flat_off', 'value' => 100, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 200.0, 4, []),
        ]);
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testSetFlatOffRepeat(): void
    {
        $rule = $this->rule(['bundle_size' => 4, 'method' => 'set_flat_off', 'value' => 100, 'repeat' => true]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 200.0, 8, []),
        ]);
        // 2 bundles × $100 = $200
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(200.0, $result->getAmount());
    }

    public function testInsufficientItemsReturnsNull(): void
    {
        $rule = $this->rule(['bundle_size' => 3, 'method' => 'set_price', 'value' => 90]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        self::assertNull((new SetStrategy())->apply($rule, $ctx));
    }

    public function testSetPriceHigherThanBundleYieldsNull(): void
    {
        // Set price is MORE expensive than natural bundle price: no discount
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 500]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
        ]);
        self::assertNull((new SetStrategy())->apply($rule, $ctx));
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90]);
        self::assertNull((new SetStrategy())->apply($rule, new CartContext([])));
    }

    public function testPicksMostExpensiveItemsForBundle(): void
    {
        // To maximise customer savings, the bundle pulls the MOST EXPENSIVE units first.
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 100, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'Cheap', 50.0, 1, []),
            new CartItem(2, 'Mid', 100.0, 1, []),
            new CartItem(3, 'Premium', 200.0, 1, []),
        ]);
        // Pick Premium + Mid → 300 - 100 = 200 discount
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(200.0, $result->getAmount());
    }

    public function testSetPercentageOverHundredIsCappedAtBundleTotal(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_percentage', 'value' => 150, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
        ]);
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(200.0, $result->getAmount()); // bundle total 200, capped
    }

    public function testSetStrategyWithUnknownMethodReturnsNull(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'nonsense', 'value' => 50]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
        ]);
        self::assertNull((new SetStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'set', 'config' => $config]);
    }
}
