<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$method = (string) ($config['method'] ?? 'percentage');
$value = $config['value'] ?? '';
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Discount method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[simple][method]" value="percentage"<?php checked($method, 'percentage'); ?>> <?php esc_html_e('Percentage off', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[simple][method]" value="flat"<?php checked($method, 'flat'); ?>> <?php esc_html_e('Flat amount off (per item)', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[simple][method]" value="fixed_price"<?php checked($method, 'fixed_price'); ?>> <?php esc_html_e('Fixed price (each item becomes this price)', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label for="pd-simple-value"><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td>
            <input type="number" id="pd-simple-value" name="config_by_type[simple][value]" value="<?php echo esc_attr((string) $value); ?>" step="0.01" min="0" class="small-text">
            <span class="description"><?php esc_html_e('% for percentage, NT$ for flat/fixed price', 'power-discount'); ?></span>
        </td>
    </tr>
</table>
