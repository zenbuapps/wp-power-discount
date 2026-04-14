<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class UserLoggedInCondition implements ConditionInterface
{
    /** @var Closure(): bool */
    private Closure $isLoggedIn;

    public function __construct(Closure $isLoggedIn)
    {
        $this->isLoggedIn = $isLoggedIn;
    }

    public function type(): string
    {
        return 'user_logged_in';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['is_logged_in'])) {
            return false;
        }
        $required = (bool) $config['is_logged_in'];
        $actual = ($this->isLoggedIn)();
        return $required === $actual;
    }
}
