<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class UserRoleCondition implements ConditionInterface
{
    /** @var Closure(): string[] */
    private Closure $getCurrentRoles;

    public function __construct(Closure $getCurrentRoles)
    {
        $this->getCurrentRoles = $getCurrentRoles;
    }

    public function type(): string
    {
        return 'user_role';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        $configRoles = (array) ($config['roles'] ?? []);
        if ($configRoles === []) {
            return false;
        }
        $userRoles = ($this->getCurrentRoles)();
        foreach ($configRoles as $role) {
            if (in_array($role, $userRoles, true)) {
                return true;
            }
        }
        return false;
    }
}
