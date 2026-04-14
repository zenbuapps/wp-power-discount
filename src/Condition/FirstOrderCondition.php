<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class FirstOrderCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $getCurrentUserId;
    /** @var Closure(int): int */
    private Closure $getUserOrderCount;

    public function __construct(Closure $getCurrentUserId, Closure $getUserOrderCount)
    {
        $this->getCurrentUserId = $getCurrentUserId;
        $this->getUserOrderCount = $getUserOrderCount;
    }

    public function type(): string
    {
        return 'first_order';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['is_first_order'])) {
            return false;
        }
        $required = (bool) $config['is_first_order'];
        $userId = ($this->getCurrentUserId)();

        if ($userId <= 0) {
            // Guest treated as first order = true
            return $required === true;
        }

        $count = ($this->getUserOrderCount)($userId);
        $isFirst = $count === 0;
        return $required === $isFirst;
    }
}
