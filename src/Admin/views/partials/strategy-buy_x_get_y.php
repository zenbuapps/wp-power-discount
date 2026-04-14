<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$trigger = (array) ($config['trigger'] ?? []);
$reward = (array) ($config['reward'] ?? []);
$triggerSource = (string) ($trigger['source'] ?? 'filter');
$triggerQty = (int) ($trigger['qty'] ?? 1);
$rewardTarget = (string) ($reward['target'] ?? 'same');
$rewardQty = (int) ($reward['qty'] ?? 1);
$rewardMethod = (string) ($reward['method'] ?? 'free');
$rewardValue = $reward['value'] ?? 0;
$recursive = !empty($config['recursive']);
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Trigger — buy this many', 'power-discount'); ?></label></th>
        <td>
            <input type="number" name="config_by_type[buy_x_get_y][trigger][qty]" value="<?php echo (int) $triggerQty; ?>" min="1" class="small-text">
            <select name="config_by_type[buy_x_get_y][trigger][source]">
                <option value="filter"<?php selected($triggerSource, 'filter'); ?>><?php esc_html_e('Any filter-matching item', 'power-discount'); ?></option>
                <option value="specific"<?php selected($triggerSource, 'specific'); ?>><?php esc_html_e('Specific products (set via filter below)', 'power-discount'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Reward — get this many', 'power-discount'); ?></label></th>
        <td>
            <input type="number" name="config_by_type[buy_x_get_y][reward][qty]" value="<?php echo (int) $rewardQty; ?>" min="1" class="small-text">
            <select name="config_by_type[buy_x_get_y][reward][target]">
                <option value="same"<?php selected($rewardTarget, 'same'); ?>><?php esc_html_e('Of the same triggering product', 'power-discount'); ?></option>
                <option value="cheapest_in_cart"<?php selected($rewardTarget, 'cheapest_in_cart'); ?>><?php esc_html_e('Cheapest item in cart', 'power-discount'); ?></option>
                <option value="specific"<?php selected($rewardTarget, 'specific'); ?>><?php esc_html_e('Specific products (enter IDs)', 'power-discount'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Reward discount', 'power-discount'); ?></label></th>
        <td>
            <select name="config_by_type[buy_x_get_y][reward][method]">
                <option value="free"<?php selected($rewardMethod, 'free'); ?>><?php esc_html_e('Free', 'power-discount'); ?></option>
                <option value="percentage"<?php selected($rewardMethod, 'percentage'); ?>><?php esc_html_e('Percentage off', 'power-discount'); ?></option>
                <option value="flat"<?php selected($rewardMethod, 'flat'); ?>><?php esc_html_e('Flat amount off', 'power-discount'); ?></option>
            </select>
            <input type="number" step="0.01" min="0" name="config_by_type[buy_x_get_y][reward][value]" value="<?php echo esc_attr((string) $rewardValue); ?>" class="small-text">
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Recursive', 'power-discount'); ?></label></th>
        <td><label><input type="checkbox" name="config_by_type[buy_x_get_y][recursive]" value="1"<?php checked($recursive, true); ?>> <?php esc_html_e('Apply the rule repeatedly while the cart allows it', 'power-discount'); ?></label></td>
    </tr>
</table>
