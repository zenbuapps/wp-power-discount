<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use PowerDiscount\Domain\CartContext;

final class CartLineItemsCondition implements ConditionInterface
{
    public function type(): string
    {
        return 'cart_line_items';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        return Comparator::compare(
            count($context->getItems()),
            (string) $config['operator'],
            (float) $config['value']
        );
    }
}
