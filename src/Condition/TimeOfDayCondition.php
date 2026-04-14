<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class TimeOfDayCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $now;

    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? static function (): int { return time(); };
    }

    public function type(): string
    {
        return 'time_of_day';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['from'], $config['to'])) {
            return false;
        }
        $nowMinutes = $this->toMinutes(gmdate('H:i', ($this->now)()));
        $fromMinutes = $this->toMinutes((string) $config['from']);
        $toMinutes = $this->toMinutes((string) $config['to']);

        if ($fromMinutes === null || $toMinutes === null) {
            return false;
        }

        if ($fromMinutes <= $toMinutes) {
            return $nowMinutes >= $fromMinutes && $nowMinutes <= $toMinutes;
        }
        // Cross-midnight window
        return $nowMinutes >= $fromMinutes || $nowMinutes <= $toMinutes;
    }

    private function toMinutes(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }
        return $h * 60 + $min;
    }
}
