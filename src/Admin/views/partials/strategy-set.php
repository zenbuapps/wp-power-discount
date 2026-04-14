<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$bundleSize = (int) ($config['bundle_size'] ?? 2);
$method = (string) ($config['method'] ?? 'set_price');
$value = $config['value'] ?? '';
$repeat = !empty($config['repeat']);
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Bundle size (N items)', 'power-discount'); ?></label></th>
        <td><input type="number" name="config_by_type[set][bundle_size]" value="<?php echo esc_attr((string) $bundleSize); ?>" min="2" class="small-text"></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Set method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[set][method]" value="set_price"<?php checked($method, 'set_price'); ?>> <?php esc_html_e('Set price — N items for NT$X', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[set][method]" value="set_percentage"<?php checked($method, 'set_percentage'); ?>> <?php esc_html_e('Set percentage — N items for X% off', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[set][method]" value="set_flat_off"<?php checked($method, 'set_flat_off'); ?>> <?php esc_html_e('Set flat off — N items for flat NT$X off (Taiwan exclusive)', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td><input type="number" step="0.01" min="0" name="config_by_type[set][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text"></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Repeat', 'power-discount'); ?></label></th>
        <td><label><input type="checkbox" name="config_by_type[set][repeat]" value="1"<?php checked($repeat, true); ?>> <?php esc_html_e('Apply multiple bundles if customer has enough items', 'power-discount'); ?></label></td>
    </tr>
</table>
