<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Frontend\GiftProgressHelper;

final class GiftProgressHelperTest extends TestCase
{
    public function testNoGiftRule(): void
    {
        $helper = new GiftProgressHelper();
        $progress = $helper->compute(new CartContext([new CartItem(1, 'A', 100.0, 1, [])]), []);

        self::assertFalse($progress->hasGiftRule);
        self::assertFalse($progress->achieved);
        self::assertNull($progress->threshold);
        self::assertNull($progress->remaining);
        self::assertSame([], $progress->giftProductIds);
    }

    public function testThresholdNotYetReached(): void
    {
        $helper = new GiftProgressHelper();
        $rule = $this->giftRule(1000.0, [99]);
        $ctx = new CartContext([new CartItem(1, 'A', 300.0, 1, [])]);

        $progress = $helper->compute($ctx, [$rule]);

        self::assertTrue($progress->hasGiftRule);
        self::assertFalse($progress->achieved);
        self::assertSame(1000.0, $progress->threshold);
        self::assertSame(700.0, $progress->remaining);
        self::assertSame([99], $progress->giftProductIds);
    }

    public function testThresholdAchievedExactly(): void
    {
        $helper = new GiftProgressHelper();
        $rule = $this->giftRule(1000.0, [99]);
        $ctx = new CartContext([new CartItem(1, 'A', 1000.0, 1, [])]);

        $progress = $helper->compute($ctx, [$rule]);

        self::assertTrue($progress->achieved);
        self::assertNull($progress->remaining);
        self::assertSame([99], $progress->giftProductIds);
    }

    public function testPicksLowestUnachievedThreshold(): void
    {
        $helper = new GiftProgressHelper();
        $cheap = $this->giftRule(500.0, [99]);
        $premium = $this->giftRule(2000.0, [100]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $progress = $helper->compute($ctx, [$cheap, $premium]);

        self::assertSame(500.0, $progress->threshold);
        self::assertSame(400.0, $progress->remaining);
        self::assertSame([99], $progress->giftProductIds);
    }

    public function testIgnoresDisabledRules(): void
    {
        $helper = new GiftProgressHelper();
        $disabled = new Rule([
            'title' => 'x', 'type' => 'gift_with_purchase',
            'status' => 0,
            'config' => ['threshold' => 500, 'gift_product_ids' => [99]],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $progress = $helper->compute($ctx, [$disabled]);

        self::assertFalse($progress->hasGiftRule);
    }

    public function testIgnoresNonGiftRules(): void
    {
        $helper = new GiftProgressHelper();
        $simple = new Rule([
            'title' => 'x', 'type' => 'simple', 'status' => 1,
            'config' => ['method' => 'percentage', 'value' => 10],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $progress = $helper->compute($ctx, [$simple]);

        self::assertFalse($progress->hasGiftRule);
    }

    public function testEmptyGiftIdsSkipsRule(): void
    {
        $helper = new GiftProgressHelper();
        $rule = $this->giftRule(500.0, []);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $progress = $helper->compute($ctx, [$rule]);

        self::assertFalse($progress->hasGiftRule);
    }

    private function giftRule(float $threshold, array $giftIds): Rule
    {
        return new Rule([
            'title' => 'Gift',
            'type' => 'gift_with_purchase',
            'status' => 1,
            'config' => [
                'threshold' => $threshold,
                'gift_product_ids' => $giftIds,
                'gift_qty' => 1,
            ],
        ]);
    }
}
