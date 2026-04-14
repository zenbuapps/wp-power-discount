<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\UserRoleCondition;
use PowerDiscount\Domain\CartContext;

final class UserRoleConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('user_role', (new UserRoleCondition(static function (): array { return []; }))->type());
    }

    public function testMatchesWhenAnyRolePresent(): void
    {
        $c = new UserRoleCondition(static function (): array { return ['customer', 'subscriber']; });
        self::assertTrue($c->evaluate(['roles' => ['customer']], new CartContext([])));
        self::assertTrue($c->evaluate(['roles' => ['admin', 'subscriber']], new CartContext([])));
        self::assertFalse($c->evaluate(['roles' => ['admin']], new CartContext([])));
    }

    public function testEmptyRolesInConfigIsFalse(): void
    {
        $c = new UserRoleCondition(static function (): array { return ['customer']; });
        self::assertFalse($c->evaluate([], new CartContext([])));
        self::assertFalse($c->evaluate(['roles' => []], new CartContext([])));
    }

    public function testGuestHasNoRoles(): void
    {
        $c = new UserRoleCondition(static function (): array { return []; });
        self::assertFalse($c->evaluate(['roles' => ['customer']], new CartContext([])));
    }
}
