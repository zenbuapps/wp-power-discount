<?php
/** @var \PowerDiscount\Admin\AddonRulesListTable $table */
/** @var string $newUrl */
/** @var string $deactivateUrl */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap pd-rules-list pd-addons-list">
    <h1 class="wp-heading-inline"><?php esc_html_e('加價購規則', 'power-discount'); ?></h1>
    <a href="<?php echo esc_url($newUrl); ?>" class="page-title-action"><?php esc_html_e('新增規則', 'power-discount'); ?></a>
    <a href="<?php echo esc_url($deactivateUrl); ?>" class="page-title-action pd-deactivate-link"
       onclick="return confirm('<?php echo esc_js(__('確定要停用加價購功能嗎？既有規則資料會保留。', 'power-discount')); ?>');">
        <?php esc_html_e('停用功能', 'power-discount'); ?>
    </a>
    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="power-discount-addons">
        <?php $table->display(); ?>
    </form>
</div>
