<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

use PowerDiscount\Domain\CartItem;

final class CategoriesFilter implements FilterInterface
{
    public function type(): string
    {
        return 'categories';
    }

    public function matches(array $config, CartItem $item): bool
    {
        $method = strtolower((string) ($config['method'] ?? 'in'));
        $ids = array_map('intval', (array) ($config['ids'] ?? []));

        $matchesList = false;
        foreach ($item->getCategoryIds() as $itemCat) {
            if (in_array($itemCat, $ids, true)) {
                $matchesList = true;
                break;
            }
        }

        if ($method === 'not_in') {
            return !$matchesList;
        }
        return $matchesList;
    }
}
