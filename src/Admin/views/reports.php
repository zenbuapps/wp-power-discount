<?php
/**
 * @var array<int, array<string, mixed>> $stats
 * @var float $totalDiscount
 * @var int $totalOrders
 * @var string $rulesUrl
 */
if (!defined('ABSPATH')) {
    exit;
}
$priceFormat = function ($amount) {
    if (function_exists('wc_price')) {
        return wp_strip_all_tags(wc_price((float) $amount));
    }
    return number_format((float) $amount, 2);
};
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Power Discount Reports', 'power-discount'); ?></h1>
    <a href="<?php echo esc_url($rulesUrl); ?>" class="page-title-action"><?php esc_html_e('Manage Rules', 'power-discount'); ?></a>
    <hr class="wp-header-end">

    <div style="display:flex;gap:16px;margin:16px 0;">
        <div style="flex:1;background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
            <div style="color:#646970;font-size:13px;"><?php esc_html_e('Total discount given', 'power-discount'); ?></div>
            <div style="font-size:24px;font-weight:600;margin-top:4px;"><?php echo esc_html($priceFormat($totalDiscount)); ?></div>
        </div>
        <div style="flex:1;background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
            <div style="color:#646970;font-size:13px;"><?php esc_html_e('Orders affected', 'power-discount'); ?></div>
            <div style="font-size:24px;font-weight:600;margin-top:4px;"><?php echo (int) $totalOrders; ?></div>
        </div>
        <div style="flex:1;background:#fff;border:1px solid #c3c4c7;padding:16px;border-radius:4px;">
            <div style="color:#646970;font-size:13px;"><?php esc_html_e('Active rules tracked', 'power-discount'); ?></div>
            <div style="font-size:24px;font-weight:600;margin-top:4px;"><?php echo count($stats); ?></div>
        </div>
    </div>

    <h2><?php esc_html_e('Rule performance', 'power-discount'); ?></h2>
    <?php if ($stats === []): ?>
        <p><?php esc_html_e('No discount records yet. Reports populate as orders get placed.', 'power-discount'); ?></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Rule', 'power-discount'); ?></th>
                    <th><?php esc_html_e('Type', 'power-discount'); ?></th>
                    <th><?php esc_html_e('Times applied', 'power-discount'); ?></th>
                    <th><?php esc_html_e('Total discount', 'power-discount'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stats as $row): ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) $row['rule_title']); ?></strong> <span style="color:#999;">#<?php echo (int) $row['rule_id']; ?></span></td>
                        <td><?php echo esc_html((string) $row['rule_type']); ?></td>
                        <td><?php echo (int) $row['count']; ?></td>
                        <td><?php echo esc_html($priceFormat($row['total_amount'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
