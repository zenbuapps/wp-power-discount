<?php
/**
 * @var \PowerDiscount\Domain\AddonRule $rule
 * @var bool $isNew
 * @var string $pendingError
 */
if (!defined('ABSPATH')) {
    exit;
}

$pageTitle = $isNew ? __('新增加價購規則', 'power-discount') : __('編輯加價購規則', 'power-discount');
$listUrl = admin_url('admin.php?page=power-discount-addons');
$pendingError = $pendingError ?? '';

// Pre-fetch target products to pre-populate the selectWoo input
$targetIds = $rule->getTargetProductIds();
$targetProducts = [];
if (!empty($targetIds) && function_exists('wc_get_product')) {
    foreach ($targetIds as $tid) {
        $p = wc_get_product($tid);
        if ($p) {
            $targetProducts[$tid] = $p->get_formatted_name();
        }
    }
}
?>
<div class="wrap pd-rule-editor pd-addon-editor">
    <h1 class="wp-heading-inline"><?php echo esc_html($pageTitle); ?></h1>
    <a href="<?php echo esc_url($listUrl); ?>" class="page-title-action">← <?php esc_html_e('回到列表', 'power-discount'); ?></a>
    <hr class="wp-header-end">

    <?php if ($pendingError !== ''): ?>
        <div class="notice notice-error pd-inline-error"><p><strong><?php esc_html_e('儲存失敗：', 'power-discount'); ?></strong> <?php echo esc_html($pendingError); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="pd-addon-rule-form">
        <input type="hidden" name="action" value="pd_save_addon_rule">
        <input type="hidden" name="id" value="<?php echo (int) $rule->getId(); ?>">
        <input type="hidden" name="priority" value="<?php echo (int) $rule->getPriority(); ?>">
        <?php wp_nonce_field('pd_save_addon_rule_' . (int) $rule->getId()); ?>

        <div class="pd-section">
            <h2 class="pd-section-title"><?php esc_html_e('1. 基本設定', 'power-discount'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="pd-addon-title"><?php esc_html_e('規則名稱', 'power-discount'); ?></label></th>
                    <td><input type="text" id="pd-addon-title" name="title" value="<?php echo esc_attr($rule->getTitle()); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="pd-addon-status"><?php esc_html_e('狀態', 'power-discount'); ?></label></th>
                    <td>
                        <select id="pd-addon-status" name="status">
                            <option value="1"<?php selected($rule->getStatus(), 1); ?>><?php esc_html_e('啟用', 'power-discount'); ?></option>
                            <option value="0"<?php selected($rule->getStatus(), 0); ?>><?php esc_html_e('停用', 'power-discount'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('獨立定價', 'power-discount'); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="exclude_from_discounts" value="1"<?php checked($rule->isExcludeFromDiscounts(), true); ?>>
                            <?php esc_html_e('此規則內的加價購商品不套用其他折扣規則', 'power-discount'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('勾選後，這條規則中的加價購商品在購物車中只會以下方設定的特價計算，不受其他折扣規則影響。未勾選則會與其他折扣疊加。', 'power-discount'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="pd-section">
            <h2 class="pd-section-title"><?php esc_html_e('2. 加價購商品', 'power-discount'); ?></h2>
            <p class="description"><?php esc_html_e('挑選要作為加價購的商品並分別設定特價。顧客必須購買下方「目標商品」之一，這些加價購才會出現在商品頁面。', 'power-discount'); ?></p>

            <div class="pd-repeater" data-pd-repeater="addon-item">
                <template class="pd-repeater-template">
                    <div class="pd-repeater-row pd-addon-item-row">
                        <select name="addon_items[__INDEX__][product_id]" class="wc-product-search"
                                data-placeholder="<?php echo esc_attr__('搜尋商品…', 'power-discount'); ?>"
                                data-action="woocommerce_json_search_products_and_variations"
                                style="min-width:320px;"></select>
                        <input type="number" name="addon_items[__INDEX__][special_price]" value="" step="0.01" min="0" class="small-text" placeholder="<?php echo esc_attr__('特價', 'power-discount'); ?>">
                        <button type="button" class="button button-small pd-repeater-remove">×</button>
                    </div>
                </template>
                <?php foreach ($rule->getAddonItems() as $index => $item):
                    $pid = $item->getProductId();
                    $name = '';
                    if (function_exists('wc_get_product')) {
                        $p = wc_get_product($pid);
                        if ($p) { $name = $p->get_formatted_name(); }
                    }
                ?>
                    <div class="pd-repeater-row pd-addon-item-row">
                        <select name="addon_items[<?php echo (int) $index; ?>][product_id]" class="wc-product-search"
                                data-placeholder="<?php echo esc_attr__('搜尋商品…', 'power-discount'); ?>"
                                data-action="woocommerce_json_search_products_and_variations"
                                style="min-width:320px;">
                            <?php if ($pid > 0): ?>
                                <option value="<?php echo (int) $pid; ?>" selected><?php echo esc_html($name ?: '#' . $pid); ?></option>
                            <?php endif; ?>
                        </select>
                        <input type="number" name="addon_items[<?php echo (int) $index; ?>][special_price]" value="<?php echo esc_attr((string) $item->getSpecialPrice()); ?>" step="0.01" min="0" class="small-text">
                        <button type="button" class="button button-small pd-repeater-remove">×</button>
                    </div>
                <?php endforeach; ?>
            </div>

            <p><button type="button" class="button pd-repeater-add" data-pd-add="addon-item"><?php esc_html_e('新增加價購商品', 'power-discount'); ?></button></p>
        </div>

        <div class="pd-section">
            <h2 class="pd-section-title"><?php esc_html_e('3. 投放目標商品', 'power-discount'); ?></h2>
            <p class="description"><?php esc_html_e('選擇哪些商品的商品頁面要顯示這批加價購選項。顧客只有在瀏覽這些目標商品時才會看到加價購專區。', 'power-discount'); ?></p>

            <select name="target_product_ids[]" class="wc-product-search" multiple
                    data-placeholder="<?php echo esc_attr__('搜尋目標商品…', 'power-discount'); ?>"
                    data-action="woocommerce_json_search_products_and_variations"
                    style="min-width:480px;">
                <?php foreach ($targetProducts as $tid => $tname): ?>
                    <option value="<?php echo (int) $tid; ?>" selected><?php echo esc_html($tname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php submit_button($isNew ? __('建立規則', 'power-discount') : __('儲存規則', 'power-discount')); ?>
    </form>
</div>
