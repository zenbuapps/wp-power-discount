<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class BirthdayMonthCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $getCurrentUserId;
    /** @var Closure(int): ?int */
    private Closure $getUserBirthdayMonth;
    /** @var Closure(): int */
    private Closure $now;

    public function __construct(Closure $getCurrentUserId, Closure $getUserBirthdayMonth, Closure $now)
    {
        $this->getCurrentUserId = $getCurrentUserId;
        $this->getUserBirthdayMonth = $getUserBirthdayMonth;
        $this->now = $now;
    }

    public function type(): string
    {
        return 'birthday_month';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (empty($config['match_current_month'])) {
            return false;
        }
        $userId = ($this->getCurrentUserId)();
        if ($userId <= 0) {
            return false;
        }
        $birthdayMonth = ($this->getUserBirthdayMonth)($userId);
        if ($birthdayMonth === null) {
            return false;
        }
        $currentMonth = (int) gmdate('n', ($this->now)());
        return $birthdayMonth === $currentMonth;
    }
}
