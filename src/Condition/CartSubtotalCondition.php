<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use PowerDiscount\Domain\CartContext;

final class CartSubtotalCondition implements ConditionInterface
{
    public function type(): string
    {
        return 'cart_subtotal';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        $operator = (string) $config['operator'];
        $target = (float) $config['value'];
        $subtotal = $context->getSubtotal();

        switch ($operator) {
            case '>=': return $subtotal >= $target;
            case '>':  return $subtotal >  $target;
            case '=':  return abs($subtotal - $target) < 0.00001;
            case '<=': return $subtotal <= $target;
            case '<':  return $subtotal <  $target;
            case '!=': return abs($subtotal - $target) >= 0.00001;
        }
        return false;
    }
}
