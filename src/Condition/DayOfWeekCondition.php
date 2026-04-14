<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class DayOfWeekCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $now;

    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? static function (): int { return time(); };
    }

    public function type(): string
    {
        return 'day_of_week';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        $days = (array) ($config['days'] ?? []);
        if ($days === []) {
            return false;
        }
        $days = array_map('intval', $days);
        $ts = ($this->now)();
        $today = (int) gmdate('N', $ts); // 1 = Monday, 7 = Sunday
        return in_array($today, $days, true);
    }
}
