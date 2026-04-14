<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\TimeOfDayCondition;
use PowerDiscount\Domain\CartContext;

final class TimeOfDayConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('time_of_day', (new TimeOfDayCondition())->type());
    }

    public function testWithinWindow(): void
    {
        $now = static function (): int { return strtotime('2026-04-14 14:30:00 UTC'); };
        $c = new TimeOfDayCondition($now);
        self::assertTrue($c->evaluate(['from' => '10:00', 'to' => '18:00'], new CartContext([])));
        self::assertFalse($c->evaluate(['from' => '15:00', 'to' => '18:00'], new CartContext([])));
        self::assertFalse($c->evaluate(['from' => '10:00', 'to' => '14:00'], new CartContext([])));
    }

    public function testCrossMidnightWindow(): void
    {
        // Window 22:00–02:00 (night sale)
        $c = new TimeOfDayCondition(static function (): int { return strtotime('2026-04-14 23:00:00 UTC'); });
        self::assertTrue($c->evaluate(['from' => '22:00', 'to' => '02:00'], new CartContext([])));

        $c2 = new TimeOfDayCondition(static function (): int { return strtotime('2026-04-14 01:30:00 UTC'); });
        self::assertTrue($c2->evaluate(['from' => '22:00', 'to' => '02:00'], new CartContext([])));

        $c3 = new TimeOfDayCondition(static function (): int { return strtotime('2026-04-14 15:00:00 UTC'); });
        self::assertFalse($c3->evaluate(['from' => '22:00', 'to' => '02:00'], new CartContext([])));
    }

    public function testMissingConfigKeyIsFalse(): void
    {
        $c = new TimeOfDayCondition(static function (): int { return time(); });
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
