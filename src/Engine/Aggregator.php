<?php
declare(strict_types=1);

namespace PowerDiscount\Engine;

use PowerDiscount\Domain\DiscountResult;

final class Aggregator
{
    /**
     * @param DiscountResult[] $results
     */
    public function aggregate(array $results): AggregatedDiscounts
    {
        $kept = [];
        $product = 0.0;
        $cart = 0.0;
        $shipping = 0.0;

        foreach ($results as $result) {
            if (!$result instanceof DiscountResult || !$result->hasDiscount()) {
                continue;
            }
            $kept[] = $result;
            switch ($result->getScope()) {
                case DiscountResult::SCOPE_PRODUCT:
                    $product += $result->getAmount();
                    break;
                case DiscountResult::SCOPE_CART:
                    $cart += $result->getAmount();
                    break;
                case DiscountResult::SCOPE_SHIPPING:
                    // Intentionally not summed. Shipping rules are intent-only;
                    // Phase 4 ShippingHooks consumes shippingResults() directly.
                    break;
            }
        }

        return new AggregatedDiscounts($kept, $product, $cart, 0.0);
    }
}
