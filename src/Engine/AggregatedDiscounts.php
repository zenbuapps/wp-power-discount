<?php
declare(strict_types=1);

namespace PowerDiscount\Engine;

use PowerDiscount\Domain\DiscountResult;

final class AggregatedDiscounts
{
    /** @var DiscountResult[] */
    private array $results;
    private float $product;
    private float $cart;
    private float $shipping;

    /**
     * @param DiscountResult[] $results
     */
    public function __construct(array $results, float $product, float $cart, float $shipping)
    {
        $this->results = $results;
        $this->product = $product;
        $this->cart = $cart;
        $this->shipping = $shipping;
    }

    /** @return DiscountResult[] */
    public function results(): array { return $this->results; }
    public function productTotal(): float { return $this->product; }
    public function cartTotal(): float { return $this->cart; }
    public function shippingTotal(): float { return $this->shipping; }
}
