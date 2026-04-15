<?php
declare(strict_types=1);

namespace PowerDiscount\Integration;

use PowerDiscount\Admin\AddonMenu;
use PowerDiscount\Domain\AddonItem;
use PowerDiscount\Repository\AddonRuleRepository;

final class AddonFrontend
{
    private AddonRuleRepository $rules;

    public function __construct(AddonRuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function register(): void
    {
        if (!AddonMenu::isEnabled()) {
            return;
        }
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_single_product_summary', [$this, 'renderWidget'], 35);
    }

    public function enqueueAssets(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        wp_enqueue_style(
            'power-discount-addon',
            POWER_DISCOUNT_URL . 'assets/frontend/addon.css',
            [],
            POWER_DISCOUNT_VERSION
        );
        // E2 will enqueue assets/frontend/addon.js here.
    }

    public function renderWidget(): void
    {
        global $product;
        if (!$product || !function_exists('wc_get_product')) {
            return;
        }
        $productId = (int) $product->get_id();
        $rules = $this->rules->findActiveForTarget($productId);
        if ($rules === []) {
            return;
        }

        // De-duplicate addon products across multiple matching rules
        $seen = [];
        $cards = [];
        foreach ($rules as $rule) {
            foreach ($rule->getAddonItems() as $item) {
                $pid = $item->getProductId();
                if (isset($seen[$pid])) {
                    continue;
                }
                $addonProduct = wc_get_product($pid);
                if (!$addonProduct || !$addonProduct->is_purchasable()) {
                    continue;
                }
                $cards[] = [
                    'rule_id'       => $rule->getId(),
                    'product'       => $addonProduct,
                    'special_price' => $item->getSpecialPrice(),
                ];
                $seen[$pid] = true;
            }
        }

        if ($cards === []) {
            return;
        }

        echo '<div class="pd-addon-section">';
        echo '<h3 class="pd-addon-section-title">' . esc_html__('加價購優惠', 'power-discount') . '</h3>';
        echo '<div class="pd-addon-list">';
        foreach ($cards as $card) {
            $this->renderCard($card['product'], (float) $card['special_price'], (int) $card['rule_id']);
        }
        echo '</div></div>';
    }

    private function renderCard(\WC_Product $product, float $specialPrice, int $ruleId): void
    {
        $pid = (int) $product->get_id();
        $imgUrl = wp_get_attachment_image_url((int) $product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src();
        $imgMediumUrl = wp_get_attachment_image_url((int) $product->get_image_id(), 'medium') ?: wc_placeholder_img_src();
        $title = $product->get_name();
        $regularPriceRaw = $product->get_regular_price();
        $regularPrice = $regularPriceRaw === '' ? 0.0 : (float) $regularPriceRaw;
        $excerpt = $product->get_short_description() ?: '';
        $content = apply_filters('the_content', $product->get_description());
        ?>
        <label class="pd-addon-card"
               data-product-id="<?php echo $pid; ?>"
               data-rule-id="<?php echo $ruleId; ?>"
               data-special-price="<?php echo esc_attr((string) $specialPrice); ?>">
            <input type="checkbox" name="pd_addon_ids[]" value="<?php echo $pid; ?>">
            <div class="pd-addon-thumb"><img src="<?php echo esc_url($imgUrl); ?>" alt=""></div>
            <div class="pd-addon-info">
                <div class="pd-addon-title"><?php echo esc_html($title); ?></div>
                <div class="pd-addon-price">
                    <?php if ($regularPrice > $specialPrice): ?>
                        <del><?php echo wp_kses_post(wc_price($regularPrice)); ?></del>
                    <?php endif; ?>
                    <strong class="pd-addon-special"><?php echo wp_kses_post(wc_price($specialPrice)); ?></strong>
                </div>
                <button type="button" class="pd-addon-details-btn" data-product-id="<?php echo $pid; ?>">
                    <?php esc_html_e('查看詳細', 'power-discount'); ?>
                </button>
            </div>
        </label>
        <template class="pd-addon-detail" data-product-id="<?php echo $pid; ?>">
            <div class="pd-addon-detail-header">
                <img src="<?php echo esc_url($imgMediumUrl); ?>" alt="">
                <div>
                    <h3><?php echo esc_html($title); ?></h3>
                    <div class="pd-addon-detail-price">
                        <?php if ($regularPrice > $specialPrice): ?>
                            <del><?php echo wp_kses_post(wc_price($regularPrice)); ?></del>
                        <?php endif; ?>
                        <strong><?php echo wp_kses_post(wc_price($specialPrice)); ?></strong>
                    </div>
                    <div class="pd-addon-detail-excerpt"><?php echo wp_kses_post($excerpt); ?></div>
                </div>
            </div>
            <div class="pd-addon-detail-body"><?php echo wp_kses_post($content); ?></div>
        </template>
        <?php
    }
}
