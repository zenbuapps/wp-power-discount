<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

final class CartContext
{
    /** @var CartItem[] */
    private array $items;

    public function __construct(array $items)
    {
        foreach ($items as $item) {
            if (!$item instanceof CartItem) {
                throw new \InvalidArgumentException('CartContext only accepts CartItem instances.');
            }
        }
        $this->items = array_values($items);
    }

    /** @return CartItem[] */
    public function getItems(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function getTotalQuantity(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getQuantity();
        }
        return $total;
    }

    public function getSubtotal(): float
    {
        $subtotal = 0.0;
        foreach ($this->items as $item) {
            $subtotal += $item->getLineTotal();
        }
        return $subtotal;
    }

    /** @return CartItem[] */
    public function getItemsByProductIds(array $productIds): array
    {
        $ids = array_map('intval', $productIds);
        return array_values(array_filter(
            $this->items,
            static function (CartItem $item) use ($ids): bool {
                return in_array($item->getProductId(), $ids, true);
            }
        ));
    }

    /** @return CartItem[] */
    public function getItemsInCategories(array $categoryIds): array
    {
        return array_values(array_filter(
            $this->items,
            static function (CartItem $item) use ($categoryIds): bool {
                return $item->isInCategories($categoryIds);
            }
        ));
    }
}
