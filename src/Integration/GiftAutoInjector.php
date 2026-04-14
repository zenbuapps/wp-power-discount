<?php
declare(strict_types=1);

namespace PowerDiscount\Integration;

use PowerDiscount\Domain\Rule;
use PowerDiscount\Repository\RuleRepository;
use WC_Cart;

/**
 * Auto-injects gift_with_purchase rule rewards into the cart.
 *
 * - When a rule's threshold is met and its gift is missing → add it
 * - When threshold no longer met and the auto-added gift is in cart → remove it
 *
 * Items added by us are tagged with cart_item_data['_pd_gift_rule_id'] = <rule id>
 * so we know which rule added them and can find/remove them later. The actual
 * price discount (set price to 0) is handled by the existing GiftWithPurchaseStrategy
 * + CartHooks::distributeProductDiscount pipeline; we only manage cart membership here.
 */
final class GiftAutoInjector
{
    private RuleRepository $rules;

    public function __construct(RuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function register(): void
    {
        // Priority 5 to run before CartHooks (20) so the gift item exists when Calculator runs.
        add_action('woocommerce_before_calculate_totals', [$this, 'maintainGifts'], 5, 1);
        // Display the gift item with a "Free gift" label in the cart.
        add_filter('woocommerce_cart_item_name', [$this, 'labelGiftItem'], 10, 3);
        // Block direct removal of the gift via the × in cart? Actually allow it; it'll come back next request.
    }

    /**
     * @param WC_Cart $cart
     */
    public function maintainGifts($cart): void
    {
        static $running = false;
        if ($running) {
            return;
        }
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart instanceof WC_Cart) {
            return;
        }

        $running = true;
        try {
            $this->maintainGiftsInner($cart);
        } finally {
            $running = false;
        }
    }

    private function maintainGiftsInner(WC_Cart $cart): void
    {
        $allRules = $this->rules->findAll();
        $giftRules = [];
        foreach ($allRules as $rule) {
            if ($rule->getType() !== 'gift_with_purchase' || !$rule->isEnabled()) {
                continue;
            }
            $giftRules[] = $rule;
        }
        if ($giftRules === []) {
            return;
        }

        // Compute the "real" subtotal: every cart item's full price × qty,
        // EXCLUDING any items that were auto-added by us as gifts.
        $nonGiftSubtotal = 0.0;
        foreach ($cart->get_cart() as $cartItem) {
            if (!empty($cartItem['_pd_gift_rule_id'])) {
                continue;
            }
            $product = $cartItem['data'] ?? null;
            if (!$product || !method_exists($product, 'get_price')) {
                continue;
            }
            $price = (float) $product->get_price();
            $qty = (int) ($cartItem['quantity'] ?? 0);
            $nonGiftSubtotal += $price * $qty;
        }

        foreach ($giftRules as $rule) {
            $config = $rule->getConfig();
            $threshold = (float) ($config['threshold'] ?? 0);
            $giftIds = array_values(array_filter(
                array_map('intval', (array) ($config['gift_product_ids'] ?? [])),
                static function (int $id): bool { return $id > 0; }
            ));
            $giftQty = max(1, (int) ($config['gift_qty'] ?? 1));

            if ($threshold <= 0 || $giftIds === []) {
                continue;
            }

            $met = $nonGiftSubtotal >= $threshold;

            // Find existing gift item for this rule.
            $existingKey = null;
            foreach ($cart->get_cart() as $key => $cartItem) {
                if ((int) ($cartItem['_pd_gift_rule_id'] ?? 0) === $rule->getId()) {
                    $existingKey = $key;
                    break;
                }
            }

            if ($met && $existingKey === null) {
                $this->addGift($cart, $rule, $giftIds, $giftQty);
            } elseif (!$met && $existingKey !== null) {
                $cart->remove_cart_item($existingKey);
            }
        }
    }

    /**
     * @param int[] $giftIds
     */
    private function addGift(WC_Cart $cart, Rule $rule, array $giftIds, int $qty): void
    {
        if (!function_exists('wc_get_product')) {
            return;
        }
        // Pick the first product that exists and is purchasable.
        foreach ($giftIds as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            if (method_exists($product, 'is_in_stock') && !$product->is_in_stock()) {
                continue;
            }
            $cart->add_to_cart(
                $pid,
                $qty,
                0,
                [],
                ['_pd_gift_rule_id' => $rule->getId()]
            );
            return;
        }
    }

    /**
     * Tag the cart item display name when it's an auto-added gift.
     *
     * @param string $name
     * @param array<string, mixed> $cartItem
     * @param string $cartItemKey
     */
    public function labelGiftItem($name, $cartItem, $cartItemKey): string
    {
        if (empty($cartItem['_pd_gift_rule_id'])) {
            return (string) $name;
        }
        return (string) $name . ' <span class="pd-gift-tag">' . esc_html__('Free gift', 'power-discount') . '</span>';
    }
}
