<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\UserLoggedInCondition;
use PowerDiscount\Domain\CartContext;

final class UserLoggedInConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('user_logged_in', (new UserLoggedInCondition(static function (): bool { return false; }))->type());
    }

    public function testRequireLoggedIn(): void
    {
        $c = new UserLoggedInCondition(static function (): bool { return true; });
        self::assertTrue($c->evaluate(['is_logged_in' => true], new CartContext([])));
        self::assertFalse($c->evaluate(['is_logged_in' => false], new CartContext([])));
    }

    public function testRequireGuest(): void
    {
        $c = new UserLoggedInCondition(static function (): bool { return false; });
        self::assertTrue($c->evaluate(['is_logged_in' => false], new CartContext([])));
        self::assertFalse($c->evaluate(['is_logged_in' => true], new CartContext([])));
    }

    public function testMissingConfigKeyIsFalse(): void
    {
        $c = new UserLoggedInCondition(static function (): bool { return true; });
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
