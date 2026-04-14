<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\TotalSpentCondition;
use PowerDiscount\Domain\CartContext;

final class TotalSpentConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('total_spent', (new TotalSpentCondition(
            static function (): int { return 0; },
            static function (int $uid): float { return 0.0; }
        ))->type());
    }

    public function testGuestAlwaysZero(): void
    {
        $c = new TotalSpentCondition(
            static function (): int { return 0; },
            static function (int $uid): float { return 1000.0; }
        );
        // Guests always treated as 0 total spent
        self::assertFalse($c->evaluate(['operator' => '>=', 'value' => 100], new CartContext([])));
        self::assertTrue($c->evaluate(['operator' => '=', 'value' => 0], new CartContext([])));
    }

    public function testLoggedInTotalSpent(): void
    {
        $c = new TotalSpentCondition(
            static function (): int { return 42; },
            static function (int $uid): float { return 5000.0; }
        );
        self::assertTrue($c->evaluate(['operator' => '>=', 'value' => 1000], new CartContext([])));
        self::assertFalse($c->evaluate(['operator' => '>=', 'value' => 10000], new CartContext([])));
    }

    public function testMissingConfig(): void
    {
        $c = new TotalSpentCondition(
            static function (): int { return 0; },
            static function (int $uid): float { return 0.0; }
        );
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
