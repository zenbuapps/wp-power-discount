<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class TotalSpentCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $getCurrentUserId;
    /** @var Closure(int): float */
    private Closure $getUserTotalSpent;

    public function __construct(Closure $getCurrentUserId, Closure $getUserTotalSpent)
    {
        $this->getCurrentUserId = $getCurrentUserId;
        $this->getUserTotalSpent = $getUserTotalSpent;
    }

    public function type(): string
    {
        return 'total_spent';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        $userId = ($this->getCurrentUserId)();
        $spent = $userId > 0 ? ($this->getUserTotalSpent)($userId) : 0.0;
        return Comparator::compare($spent, (string) $config['operator'], (float) $config['value']);
    }
}
