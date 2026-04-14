<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\DayOfWeekCondition;
use PowerDiscount\Domain\CartContext;

final class DayOfWeekConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('day_of_week', (new DayOfWeekCondition())->type());
    }

    public function testMatchesCurrentDay(): void
    {
        // 2026-04-14 is a Tuesday (ISO 2)
        $now = static function (): int { return strtotime('2026-04-14 12:00:00 UTC'); };
        $c = new DayOfWeekCondition($now);
        self::assertTrue($c->evaluate(['days' => [2]], new CartContext([])));
        self::assertTrue($c->evaluate(['days' => [1, 2, 3]], new CartContext([])));
        self::assertFalse($c->evaluate(['days' => [6, 7]], new CartContext([])));
    }

    public function testEmptyDaysConfigIsFalse(): void
    {
        $c = new DayOfWeekCondition(static function (): int { return time(); });
        self::assertFalse($c->evaluate([], new CartContext([])));
        self::assertFalse($c->evaluate(['days' => []], new CartContext([])));
    }
}
