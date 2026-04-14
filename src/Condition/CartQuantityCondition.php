<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use PowerDiscount\Domain\CartContext;

final class CartQuantityCondition implements ConditionInterface
{
    public function type(): string
    {
        return 'cart_quantity';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        return Comparator::compare(
            $context->getTotalQuantity(),
            (string) $config['operator'],
            (float) $config['value']
        );
    }
}
