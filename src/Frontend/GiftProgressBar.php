<?php
declare(strict_types=1);

namespace PowerDiscount\Frontend;

use PowerDiscount\Integration\CartContextBuilder;
use PowerDiscount\Repository\RuleRepository;

final class GiftProgressBar
{
    private RuleRepository $rules;
    private CartContextBuilder $builder;
    private GiftProgressHelper $helper;

    public function __construct(RuleRepository $rules, CartContextBuilder $builder, GiftProgressHelper $helper)
    {
        $this->rules = $rules;
        $this->builder = $builder;
        $this->helper = $helper;
    }

    public function register(): void
    {
        // Render after the existing free-shipping bar (priority 11) so they stack visually.
        add_action('woocommerce_before_cart', [$this, 'render'], 11);
        add_action('woocommerce_before_checkout_form', [$this, 'render'], 11);
    }

    public function render(): void
    {
        if (!function_exists('WC') || WC()->cart === null) {
            return;
        }

        $context = $this->builder->fromWcCart(WC()->cart);
        $allRules = $this->rules->findAll();
        $progress = $this->helper->compute($context, $allRules);

        if (!$progress->hasGiftRule) {
            return;
        }

        $giftLabel = $this->resolveGiftLabel($progress->giftProductIds);

        echo '<div class="pd-gift-bar">';

        if ($progress->achieved && $progress->threshold === null) {
            // Everything achieved
            $msg = $giftLabel === ''
                ? esc_html__('Gift unlocked!', 'power-discount')
                : sprintf(esc_html__('Gift unlocked: %s', 'power-discount'), esc_html($giftLabel));
            echo '<div class="pd-gift-bar__message">🎁 ' . $msg . '</div>';
        } elseif ($progress->threshold !== null && $progress->remaining !== null) {
            $remainingFormatted = function_exists('wc_price')
                ? wp_strip_all_tags(wc_price($progress->remaining))
                : number_format($progress->remaining, 2);
            $msg = $giftLabel === ''
                ? sprintf(esc_html__('Add %s more to unlock a free gift', 'power-discount'), esc_html($remainingFormatted))
                : sprintf(
                    esc_html__('Add %1$s more to unlock free gift: %2$s', 'power-discount'),
                    esc_html($remainingFormatted),
                    esc_html($giftLabel)
                );
            echo '<div class="pd-gift-bar__message">🎁 ' . $msg . '</div>';

            $achievedAmount = $progress->threshold - $progress->remaining;
            $percent = (int) max(0, min(100, ($achievedAmount / $progress->threshold) * 100));
            echo '<div class="pd-gift-bar__track"><div class="pd-gift-bar__fill" style="width:' . $percent . '%"></div></div>';
        } else {
            $msg = esc_html__('Gift promotions available — see checkout for details.', 'power-discount');
            echo '<div class="pd-gift-bar__message">🎁 ' . $msg . '</div>';
        }

        echo '</div>';
    }

    /**
     * @param int[] $productIds
     */
    private function resolveGiftLabel(array $productIds): string
    {
        if ($productIds === [] || !function_exists('wc_get_product')) {
            return '';
        }
        $names = [];
        foreach ($productIds as $pid) {
            $product = wc_get_product((int) $pid);
            if ($product) {
                $names[] = $product->get_name();
            }
            if (count($names) >= 3) {
                break;
            }
        }
        if ($names === []) {
            return '';
        }
        // Join: "A、B 或 C" — Chinese-friendly
        return implode(' / ', $names);
    }
}
