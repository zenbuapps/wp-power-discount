<?php
declare(strict_types=1);

namespace PowerDiscount\Engine;

use Closure;
use PowerDiscount\Condition\Evaluator;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Filter\Matcher;
use PowerDiscount\Strategy\StrategyRegistry;

final class Calculator
{
    private StrategyRegistry $strategies;
    private Evaluator $conditionEvaluator;
    private Matcher $filterMatcher;
    private ExclusivityResolver $exclusivityResolver;
    /** @var Closure(): int */
    private Closure $now;

    public function __construct(
        StrategyRegistry $strategies,
        Evaluator $conditionEvaluator,
        Matcher $filterMatcher,
        ExclusivityResolver $exclusivityResolver,
        ?Closure $now = null
    ) {
        $this->strategies = $strategies;
        $this->conditionEvaluator = $conditionEvaluator;
        $this->filterMatcher = $filterMatcher;
        $this->exclusivityResolver = $exclusivityResolver;
        $this->now = $now ?? static function (): int { return time(); };
    }

    /**
     * @param Rule[] $rules  already ordered by priority ASC
     * @return DiscountResult[]
     */
    public function run(array $rules, CartContext $context): array
    {
        $results = [];
        $now = ($this->now)();

        foreach ($rules as $rule) {
            if (!$rule->isEnabled()) {
                continue;
            }
            if (!$rule->isActiveAt($now)) {
                continue;
            }
            if ($rule->isUsageLimitExhausted()) {
                continue;
            }
            if (!$this->conditionEvaluator->evaluate($rule->getConditions(), $context)) {
                continue;
            }

            $matched = $this->filterMatcher->matches($rule->getFilters(), $context);
            if ($matched === []) {
                continue;
            }

            $strategy = $this->strategies->resolve($rule->getType());
            if ($strategy === null) {
                continue;
            }

            $filteredContext = new CartContext($matched);
            $result = $strategy->apply($rule, $filteredContext);
            if ($result === null || !$result->hasDiscount()) {
                continue;
            }

            $results[] = $result;

            if ($this->exclusivityResolver->shouldStopAfter($rule)) {
                break;
            }
        }

        return $results;
    }
}
