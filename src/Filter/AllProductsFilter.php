<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

use PowerDiscount\Domain\CartItem;

final class AllProductsFilter implements FilterInterface
{
    public function type(): string
    {
        return 'all_products';
    }

    public function matches(array $config, CartItem $item): bool
    {
        return true;
    }
}
