<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$method = (string) ($config['method'] ?? 'percentage');
$value = $config['value'] ?? '';
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[cart][method]" value="percentage"<?php checked($method, 'percentage'); ?>> <?php esc_html_e('Percentage off whole cart', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[cart][method]" value="flat_total"<?php checked($method, 'flat_total'); ?>> <?php esc_html_e('Fixed amount off cart total', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[cart][method]" value="flat_per_item"<?php checked($method, 'flat_per_item'); ?>> <?php esc_html_e('Fixed amount off per item', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td>
            <input type="number" step="0.01" min="0" name="config_by_type[cart][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text">
        </td>
    </tr>
</table>
