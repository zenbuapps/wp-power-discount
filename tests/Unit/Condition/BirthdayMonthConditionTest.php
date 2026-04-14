<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\BirthdayMonthCondition;
use PowerDiscount\Domain\CartContext;

final class BirthdayMonthConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('birthday_month', (new BirthdayMonthCondition(
            static function (): int { return 0; },
            static function (int $uid): ?int { return null; },
            static function (): int { return 0; }
        ))->type());
    }

    public function testMatchesCurrentMonth(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return 4; }, // April
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertTrue($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testDoesNotMatchDifferentMonth(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return 7; }, // July
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertFalse($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testNoBirthdaySet(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return null; },
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertFalse($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testGuestFalse(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 0; },
            static function (int $uid): ?int { return 4; },
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertFalse($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testMissingConfig(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return 4; },
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        // Without match_current_month=true, condition is off
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
