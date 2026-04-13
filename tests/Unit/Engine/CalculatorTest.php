<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\ConditionRegistry;
use PowerDiscount\Condition\Evaluator;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Engine\Calculator;
use PowerDiscount\Engine\ExclusivityResolver;
use PowerDiscount\Filter\AllProductsFilter;
use PowerDiscount\Filter\CategoriesFilter;
use PowerDiscount\Filter\FilterRegistry;
use PowerDiscount\Filter\Matcher;
use PowerDiscount\Strategy\SimpleStrategy;
use PowerDiscount\Strategy\StrategyRegistry;

final class CalculatorTest extends TestCase
{
    public function testSingleRuleAppliesSimpleDiscount(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [10])]);
        $rule = new Rule([
            'id' => 1, 'title' => 'r', 'type' => 'simple',
            'config' => ['method' => 'percentage', 'value' => 10],
        ]);

        $results = $calc->run([$rule], $ctx);
        self::assertCount(1, $results);
        self::assertSame(20.0, $results[0]->getAmount());
    }

    public function testSkipsDisabledOrExpiredRules(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $expired = new Rule([
            'id' => 1, 'title' => 'r', 'type' => 'simple',
            'config' => ['method' => 'percentage', 'value' => 10],
            'starts_at' => '2020-01-01 00:00:00',
            'ends_at'   => '2020-12-31 23:59:59',
        ]);

        $results = $calc->run([$expired], $ctx);
        self::assertCount(0, $results);
    }

    public function testSkipsUsageLimitExhaustedRules(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $exhausted = new Rule([
            'id' => 1, 'title' => 'r', 'type' => 'simple',
            'config' => ['method' => 'percentage', 'value' => 10],
            'usage_limit' => 1, 'used_count' => 1,
        ]);

        $results = $calc->run([$exhausted], $ctx);
        self::assertCount(0, $results);
    }

    public function testConditionsGateApplication(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $rule = new Rule([
            'id' => 1, 'title' => 'r', 'type' => 'simple',
            'config' => ['method' => 'percentage', 'value' => 10],
            'conditions' => [
                'logic' => 'and',
                'items' => [['type' => 'cart_subtotal', 'operator' => '>=', 'value' => 500]],
            ],
        ]);

        $results = $calc->run([$rule], $ctx);
        self::assertCount(0, $results);
    }

    public function testFiltersRestrictToCategories(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([
            new CartItem(1, 'Coffee', 300.0, 1, [100]),
            new CartItem(2, 'Tea',    200.0, 1, [200]),
        ]);

        $rule = new Rule([
            'id' => 1, 'title' => 'coffee 10%', 'type' => 'simple',
            'config' => ['method' => 'percentage', 'value' => 10],
            'filters' => [
                'items' => [['type' => 'categories', 'method' => 'in', 'ids' => [100]]],
            ],
        ]);

        $results = $calc->run([$rule], $ctx);
        self::assertCount(1, $results);
        self::assertSame(30.0, $results[0]->getAmount());
    }

    public function testExclusiveRuleStopsIteration(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $first = new Rule([
            'id' => 1, 'title' => 'A', 'type' => 'simple', 'priority' => 1, 'exclusive' => true,
            'config' => ['method' => 'percentage', 'value' => 10],
        ]);
        $second = new Rule([
            'id' => 2, 'title' => 'B', 'type' => 'simple', 'priority' => 2,
            'config' => ['method' => 'percentage', 'value' => 20],
        ]);

        $results = $calc->run([$first, $second], $ctx);
        self::assertCount(1, $results);
        self::assertSame(1, $results[0]->getRuleId());
    }

    public function testEmptyFilterMatchSkipsRule(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [999])]);

        $rule = new Rule([
            'id' => 1, 'title' => 'r', 'type' => 'simple',
            'config' => ['method' => 'percentage', 'value' => 10],
            'filters' => [
                'items' => [['type' => 'categories', 'method' => 'in', 'ids' => [100]]],
            ],
        ]);

        $results = $calc->run([$rule], $ctx);
        self::assertCount(0, $results);
    }

    public function testUnknownRuleTypeSkipped(): void
    {
        $calc = $this->makeCalculator();
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $rule = new Rule([
            'id' => 1, 'title' => 'r', 'type' => 'unknown_type',
            'config' => ['method' => 'percentage', 'value' => 10],
        ]);

        $results = $calc->run([$rule], $ctx);
        self::assertCount(0, $results);
    }

    private function makeCalculator(): Calculator
    {
        $strategies = new StrategyRegistry();
        $strategies->register(new SimpleStrategy());

        $conditions = new ConditionRegistry();
        $conditions->register(new \PowerDiscount\Condition\CartSubtotalCondition());

        $filters = new FilterRegistry();
        $filters->register(new AllProductsFilter());
        $filters->register(new CategoriesFilter());

        return new Calculator(
            $strategies,
            new Evaluator($conditions),
            new Matcher($filters),
            new ExclusivityResolver(),
            static function (): int { return strtotime('2026-04-14 12:00:00 UTC'); }
        );
    }
}
