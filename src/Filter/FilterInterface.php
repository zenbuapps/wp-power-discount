<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

use PowerDiscount\Domain\CartItem;

interface FilterInterface
{
    public function type(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function matches(array $config, CartItem $item): bool;
}
