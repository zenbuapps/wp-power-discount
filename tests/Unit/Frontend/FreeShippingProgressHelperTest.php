<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Frontend\FreeShippingProgressHelper;

final class FreeShippingProgressHelperTest extends TestCase
{
    public function testNoFreeShippingRule(): void
    {
        $helper = new FreeShippingProgressHelper();
        $progress = $helper->compute(new CartContext([new CartItem(1, 'A', 100.0, 1, [])]), []);

        self::assertFalse($progress->hasFreeShippingRule);
        self::assertFalse($progress->achieved);
        self::assertNull($progress->threshold);
        self::assertNull($progress->remaining);
    }

    public function testThresholdNotYetReached(): void
    {
        $helper = new FreeShippingProgressHelper();
        $rule = $this->freeShippingRule(1000.0);
        $ctx = new CartContext([new CartItem(1, 'A', 300.0, 1, [])]);

        $progress = $helper->compute($ctx, [$rule]);

        self::assertTrue($progress->hasFreeShippingRule);
        self::assertFalse($progress->achieved);
        self::assertSame(1000.0, $progress->threshold);
        self::assertSame(700.0, $progress->remaining);
    }

    public function testThresholdAchievedExactly(): void
    {
        $helper = new FreeShippingProgressHelper();
        $rule = $this->freeShippingRule(1000.0);
        $ctx = new CartContext([new CartItem(1, 'A', 1000.0, 1, [])]);

        $progress = $helper->compute($ctx, [$rule]);

        self::assertTrue($progress->achieved);
        self::assertNull($progress->remaining);
    }

    public function testPicksLowestUnachievedThreshold(): void
    {
        $helper = new FreeShippingProgressHelper();
        $cheap = $this->freeShippingRule(500.0);
        $premium = $this->freeShippingRule(2000.0);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $progress = $helper->compute($ctx, [$cheap, $premium]);

        self::assertSame(500.0, $progress->threshold);
        self::assertSame(400.0, $progress->remaining);
    }

    public function testIgnoresAlreadyAchievedRules(): void
    {
        $helper = new FreeShippingProgressHelper();
        $cheap = $this->freeShippingRule(500.0); // already achieved
        $premium = $this->freeShippingRule(2000.0); // not yet
        $ctx = new CartContext([new CartItem(1, 'A', 700.0, 1, [])]);

        $progress = $helper->compute($ctx, [$cheap, $premium]);

        // Achieved is true because at least one threshold is met
        self::assertTrue($progress->achieved);
    }

    public function testSkipsDisabledFreeShippingRule(): void
    {
        $helper = new FreeShippingProgressHelper();
        $disabled = new Rule([
            'title' => 'x', 'type' => 'free_shipping',
            'status' => 0,
            'conditions' => ['logic' => 'and', 'items' => [['type' => 'cart_subtotal', 'operator' => '>=', 'value' => 100]]],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 50.0, 1, [])]);

        $progress = $helper->compute($ctx, [$disabled]);

        self::assertFalse($progress->hasFreeShippingRule);
    }

    public function testRuleWithoutCartSubtotalConditionStillCountsAsFreeShipping(): void
    {
        $helper = new FreeShippingProgressHelper();
        // Free shipping triggered by payment method, not subtotal
        $rule = new Rule([
            'title' => 'LinePay free ship', 'type' => 'free_shipping',
            'status' => 1,
            'conditions' => ['logic' => 'and', 'items' => [['type' => 'payment_method', 'methods' => ['linepay']]]],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $progress = $helper->compute($ctx, [$rule]);

        self::assertTrue($progress->hasFreeShippingRule);
        self::assertNull($progress->threshold);
        self::assertNull($progress->remaining);
        self::assertFalse($progress->achieved);
    }

    public function testIgnoresNonFreeShippingRules(): void
    {
        $helper = new FreeShippingProgressHelper();
        $simple = new Rule([
            'title' => 'x', 'type' => 'simple',
            'conditions' => ['logic' => 'and', 'items' => [['type' => 'cart_subtotal', 'operator' => '>=', 'value' => 500]]],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $progress = $helper->compute($ctx, [$simple]);

        self::assertFalse($progress->hasFreeShippingRule);
    }

    private function freeShippingRule(float $threshold): Rule
    {
        return new Rule([
            'title' => 'Free Ship',
            'type' => 'free_shipping',
            'status' => 1,
            'conditions' => [
                'logic' => 'and',
                'items' => [['type' => 'cart_subtotal', 'operator' => '>=', 'value' => $threshold]],
            ],
        ]);
    }
}
