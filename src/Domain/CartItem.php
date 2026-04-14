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
    /** @var int[] */
    private array $tagIds;
    /** @var array<string, string[]> */
    private array $attributes;
    private bool $onSale;

    /**
     * @param int[] $categoryIds
     * @param int[] $tagIds
     * @param array<string, string[]> $attributes  attribute_name => values[]
     */
    public function __construct(
        int $productId,
        string $name,
        float $price,
        int $quantity,
        array $categoryIds,
        array $tagIds = [],
        array $attributes = [],
        bool $onSale = false
    ) {
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
        $this->tagIds      = array_values(array_map('intval', $tagIds));
        $this->attributes  = $attributes;
        $this->onSale      = $onSale;
    }

    public function getProductId(): int      { return $this->productId; }
    public function getName(): string        { return $this->name; }
    public function getPrice(): float        { return $this->price; }
    public function getQuantity(): int       { return $this->quantity; }
    public function getCategoryIds(): array  { return $this->categoryIds; }
    public function getTagIds(): array       { return $this->tagIds; }
    public function getAttributes(): array   { return $this->attributes; }
    public function isOnSale(): bool         { return $this->onSale; }

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

    public function isInTags(array $tagIds): bool
    {
        foreach ($tagIds as $id) {
            if (in_array((int) $id, $this->tagIds, true)) {
                return true;
            }
        }
        return false;
    }

    public function hasAttribute(string $attribute, array $values): bool
    {
        if (!isset($this->attributes[$attribute])) {
            return false;
        }
        $itemValues = (array) $this->attributes[$attribute];
        foreach ($values as $v) {
            if (in_array((string) $v, $itemValues, true)) {
                return true;
            }
        }
        return false;
    }
}
