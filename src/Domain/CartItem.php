<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

use InvalidArgumentException;

final class CartItem
{
    private int $productId;
    private string $name;
    private float $price;
    private int $quantity;
    /** @var int[] */
    private array $categoryIds;

    public function __construct(int $productId, string $name, float $price, int $quantity, array $categoryIds)
    {
        if ($price < 0) {
            throw new InvalidArgumentException('CartItem price cannot be negative.');
        }
        if ($quantity < 0) {
            throw new InvalidArgumentException('CartItem quantity cannot be negative.');
        }
        $this->productId   = $productId;
        $this->name        = $name;
        $this->price       = $price;
        $this->quantity    = $quantity;
        $this->categoryIds = array_values(array_map('intval', $categoryIds));
    }

    public function getProductId(): int      { return $this->productId; }
    public function getName(): string        { return $this->name; }
    public function getPrice(): float        { return $this->price; }
    public function getQuantity(): int       { return $this->quantity; }
    public function getCategoryIds(): array  { return $this->categoryIds; }

    public function getLineTotal(): float
    {
        return $this->price * $this->quantity;
    }

    public function isInCategories(array $categoryIds): bool
    {
        foreach ($categoryIds as $id) {
            if (in_array((int) $id, $this->categoryIds, true)) {
                return true;
            }
        }
        return false;
    }
}
