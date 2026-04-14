<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$method = (string) ($config['method'] ?? 'remove_shipping');
$value = $config['value'] ?? '';
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[free_shipping][method]" value="remove_shipping"<?php checked($method, 'remove_shipping'); ?>> <?php esc_html_e('Remove shipping entirely', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[free_shipping][method]" value="percentage_off_shipping"<?php checked($method, 'percentage_off_shipping'); ?>> <?php esc_html_e('Percentage off shipping cost', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr class="pd-fs-value">
        <th><label><?php esc_html_e('Percentage off (1–100)', 'power-discount'); ?></label></th>
        <td><input type="number" min="1" max="100" name="config_by_type[free_shipping][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text"></td>
    </tr>
</table>
