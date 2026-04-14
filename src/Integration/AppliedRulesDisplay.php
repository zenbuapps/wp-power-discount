<?php
declare(strict_types=1);

namespace PowerDiscount\Integration;

use PowerDiscount\Domain\DiscountResult;

/**
 * Surface applied power-discount rules in the cart and checkout UI:
 *
 * 1. `woocommerce_cart_item_name` filter (classic cart): append a blue
 *    notification-style callout box per applied product-scope rule directly
 *    under the product name. This is the primary, user-facing presentation.
 *
 * 2. `woocommerce_get_item_data` filter (block cart fallback): block cart
 *    doesn't use `cart_item_name` filter, so we emit simple text entries via
 *    `get_item_data` as a plain-text fallback. We detect block-cart (Store API)
 *    context so classic cart doesn't get duplicate output.
 *
 * 3. A summary panel above the cart total via the classic-cart-only hooks
 *    `woocommerce_cart_totals_before_order_total` / `woocommerce_review_order_before_order_total`.
 */
final class AppliedRulesDisplay
{
    private CartHooks $cartHooks;

    public function __construct(CartHooks $cartHooks)
    {
        $this->cartHooks = $cartHooks;
    }

    public function register(): void
    {
        add_filter('woocommerce_get_item_data', [$this, 'annotateCartItem'], 20, 2);
        add_action('woocommerce_cart_totals_before_order_total', [$this, 'renderAppliedPanel']);
        add_action('woocommerce_review_order_before_order_total', [$this, 'renderAppliedPanel']);
    }

    /**
     * Add applied-rule entries to a cart item's meta via woocommerce_get_item_data.
     *
     * Both classic and block cart honour the `display` field, which we use
     * to emit a styled HTML "notification" (blue bordered box). The `key` is a
     * stable ASCII slug ("pdapplied") so CSS can target the resulting
     * `dt.variation-pdapplied` / `dd.variation-pdapplied` markup and hide the
     * leading key label.
     *
     * @param array<int, array<string, string>> $itemData
     * @param array<string, mixed> $cartItem
     * @return array<int, array<string, string>>
     */
    public function annotateCartItem($itemData, $cartItem): array
    {
        if (!is_array($itemData)) {
            $itemData = [];
        }
        $labels = $this->appliedLabelsForItem($cartItem);
        if ($labels === []) {
            return $itemData;
        }

        foreach ($labels as $label) {
            $itemData[] = [
                'key'     => 'pdapplied',
                'value'   => $label,
                'display' => sprintf(
                    '<span class="pd-applied-note"><span class="pd-applied-note__label">%s</span> %s</span>',
                    esc_html__('Applied:', 'power-discount'),
                    esc_html($label)
                ),
            ];
        }
        return $itemData;
    }

    public function renderAppliedPanel(): void
    {
        if (!function_exists('WC') || WC()->cart === null) {
            return;
        }
        $results = $this->cartHooks->getLastResultsForCart(WC()->cart);
        if ($results === null || $results === []) {
            return;
        }

        $entries = [];
        foreach ($results as $result) {
            if (!$result instanceof DiscountResult || !$result->hasDiscount()) {
                continue;
            }
            $label = $result->getLabel();
            if ($label === null || $label === '') {
                $label = __('Discount', 'power-discount');
            }
            $entries[] = [
                'label'  => (string) $label,
                'amount' => (float) $result->getAmount(),
                'scope'  => $result->getScope(),
            ];
        }

        if ($entries === []) {
            return;
        }

        $priceFn = function (float $amount): string {
            if (function_exists('wc_price')) {
                return wp_strip_all_tags(wc_price($amount));
            }
            return number_format($amount, 2);
        };

        echo '<tr class="pd-applied-rules-row">';
        echo '<th>' . esc_html__('Applied discounts', 'power-discount') . '</th>';
        echo '<td>';
        echo '<ul class="pd-applied-rules-list">';
        foreach ($entries as $entry) {
            echo '<li>';
            echo '<span class="pd-applied-rule-label">' . esc_html($entry['label']) . '</span>';
            if ($entry['amount'] > 0 && $entry['scope'] !== DiscountResult::SCOPE_SHIPPING) {
                echo ' <span class="pd-applied-rule-amount">−' . esc_html($priceFn($entry['amount'])) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Return the distinct rule labels that apply to a cart item, based on
     * the cached Calculator results stored by CartHooks.
     *
     * @param array<string, mixed> $cartItem
     * @return string[]
     */
    private function appliedLabelsForItem(array $cartItem): array
    {
        if (!function_exists('WC') || WC()->cart === null) {
            return [];
        }

        $results = $this->cartHooks->getLastResultsForCart(WC()->cart);
        if ($results === null || $results === []) {
            return [];
        }

        $product = $cartItem['data'] ?? null;
        if (!$product || !method_exists($product, 'get_id')) {
            return [];
        }

        $productId = (int) $product->get_id();
        $parentId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;

        $applied = [];
        foreach ($results as $result) {
            if (!$result instanceof DiscountResult || !$result->hasDiscount()) {
                continue;
            }
            if ($result->getScope() !== DiscountResult::SCOPE_PRODUCT) {
                continue;
            }
            $affected = $result->getAffectedProductIds();
            $hits = in_array($productId, $affected, true)
                || ($parentId > 0 && in_array($parentId, $affected, true));
            if (!$hits) {
                continue;
            }
            $label = $result->getLabel();
            if ($label === null || $label === '') {
                $label = __('Discount', 'power-discount');
            }
            $applied[(string) $label] = true;
        }

        return array_keys($applied);
    }

}
