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

    /**
     * Always returns 0.0. Shipping-scope DiscountResults are intent-only
     * (a signal that a free-shipping-style rule matched); they are not
     * currency amounts to be summed. Consumers should read `shippingResults()`
     * and manipulate the actual shipping line via WC ShippingHooks.
     */
    public function shippingTotal(): float { return 0.0; }

    /** @return DiscountResult[] */
    public function shippingResults(): array
    {
        return array_values(array_filter(
            $this->results,
            static function (DiscountResult $r): bool {
                return $r->getScope() === DiscountResult::SCOPE_SHIPPING;
            }
        ));
    }
}
