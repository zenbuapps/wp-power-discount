<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\FirstOrderCondition;
use PowerDiscount\Domain\CartContext;

final class FirstOrderConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('first_order', (new FirstOrderCondition(
            static function (): int { return 0; },
            static function (int $uid): int { return 0; }
        ))->type());
    }

    public function testGuestTreatedAsFirstOrder(): void
    {
        // Guest user_id = 0 → always treat as first order (design decision in spec)
        $c = new FirstOrderCondition(
            static function (): int { return 0; },
            static function (int $uid): int { return 0; }
        );
        self::assertTrue($c->evaluate(['is_first_order' => true], new CartContext([])));
        self::assertFalse($c->evaluate(['is_first_order' => false], new CartContext([])));
    }

    public function testLoggedInUserFirstOrder(): void
    {
        $c = new FirstOrderCondition(
            static function (): int { return 42; },
            static function (int $uid): int { return $uid === 42 ? 0 : 999; }
        );
        self::assertTrue($c->evaluate(['is_first_order' => true], new CartContext([])));
    }

    public function testLoggedInUserReturningCustomer(): void
    {
        $c = new FirstOrderCondition(
            static function (): int { return 42; },
            static function (int $uid): int { return 5; }
        );
        self::assertTrue($c->evaluate(['is_first_order' => false], new CartContext([])));
        self::assertFalse($c->evaluate(['is_first_order' => true], new CartContext([])));
    }

    public function testMissingConfig(): void
    {
        $c = new FirstOrderCondition(
            static function (): int { return 0; },
            static function (int $uid): int { return 0; }
        );
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
