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
        <td>
            <input type="number" name="config_by_type[set][bundle_size]" value="<?php echo esc_attr((string) $bundleSize); ?>" min="2" class="small-text">
            <p class="description"><?php esc_html_e('每組要湊滿的件數。例如設 3 代表「任選 3 件」。', 'power-discount'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Set method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[set][method]" value="set_price" class="pd-set-method"<?php checked($method, 'set_price'); ?>> <?php esc_html_e('Set price — N items for NT$X', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[set][method]" value="set_percentage" class="pd-set-method"<?php checked($method, 'set_percentage'); ?>> <?php esc_html_e('Set percentage — N items for X% off', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[set][method]" value="set_flat_off" class="pd-set-method"<?php checked($method, 'set_flat_off'); ?>> <?php esc_html_e('Set flat off — N items for flat NT$X off (Taiwan exclusive)', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td>
            <input type="number" step="0.01" min="0" name="config_by_type[set][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text">
            <p class="description pd-set-value-hint" data-for="set_price"<?php echo $method === 'set_price' ? '' : ' style="display:none"'; ?>>
                <?php esc_html_e('這 N 件商品加起來的「組合總價」(NT$)。例如組合件數 = 2、數值填 300，代表顧客湊 2 件後，這 2 件總共就是 NT$300（不論原價多少）。', 'power-discount'); ?>
            </p>
            <p class="description pd-set-value-hint" data-for="set_percentage"<?php echo $method === 'set_percentage' ? '' : ' style="display:none"'; ?>>
                <?php esc_html_e('這 N 件商品要「打幾折」的折扣百分比。例如數值填 20 代表 8 折（省 20%）；填 10 代表 9 折。整組原價是多少就乘上對應的折扣。', 'power-discount'); ?>
            </p>
            <p class="description pd-set-value-hint" data-for="set_flat_off"<?php echo $method === 'set_flat_off' ? '' : ' style="display:none"'; ?>>
                <?php esc_html_e('這 N 件商品整組「現折多少元」(NT$)。例如組合件數 = 3、數值填 100，代表顧客湊滿 3 件後，整組就直接折 100 元。原價 1200 就變 1100。', 'power-discount'); ?>
            </p>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Repeat', 'power-discount'); ?></label></th>
        <td>
            <label><input type="checkbox" name="config_by_type[set][repeat]" value="1"<?php checked($repeat, true); ?>> <?php esc_html_e('Apply multiple bundles if customer has enough items', 'power-discount'); ?></label>
            <p class="description">
                <?php esc_html_e('勾選後，當顧客購買的件數夠組成多組時，折扣會自動套用多次。例如組合件數 = 2、顧客買 6 件，勾選後就會組成 3 組，折扣重複套用 3 次；未勾選則只套用 1 組，剩下的 4 件維持原價。', 'power-discount'); ?>
            </p>
        </td>
    </tr>
</table>
