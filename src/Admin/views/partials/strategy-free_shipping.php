<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$method = (string) ($config['method'] ?? 'remove_shipping');
$value = $config['value'] ?? '';
$selectedMethodIds = array_map('strval', (array) ($config['shipping_method_ids'] ?? []));

// Build the list of all configured shipping method instances across every WC zone.
$shippingMethodOptions = [];
if (class_exists('WC_Shipping_Zones')) {
    $allZones = WC_Shipping_Zones::get_zones();
    // Append "Locations not covered by your other zones" (zone 0)
    $restOfWorld = WC_Shipping_Zones::get_zone(0);
    if ($restOfWorld) {
        $allZones[] = [
            'zone_name'        => $restOfWorld->get_zone_name() ?: __('Rest of the World', 'power-discount'),
            'shipping_methods' => $restOfWorld->get_shipping_methods(),
        ];
    }
    foreach ($allZones as $zone) {
        $zoneName = (string) ($zone['zone_name'] ?? '');
        $methods = (array) ($zone['shipping_methods'] ?? []);
        foreach ($methods as $shippingMethod) {
            if (!is_object($shippingMethod)) {
                continue;
            }
            $instanceId = method_exists($shippingMethod, 'get_instance_id')
                ? (int) $shippingMethod->get_instance_id()
                : (int) ($shippingMethod->instance_id ?? 0);
            $methodSlug = isset($shippingMethod->id) ? (string) $shippingMethod->id : '';
            if ($methodSlug === '' || $instanceId === 0) {
                continue;
            }
            if (method_exists($shippingMethod, 'is_enabled') && !$shippingMethod->is_enabled()) {
                continue;
            }
            $title = method_exists($shippingMethod, 'get_title')
                ? (string) $shippingMethod->get_title()
                : $methodSlug;
            $key = $methodSlug . ':' . $instanceId;
            $shippingMethodOptions[$key] = sprintf('%s — %s', $zoneName, $title);
        }
    }
}
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[free_shipping][method]" value="remove_shipping"<?php checked($method, 'remove_shipping'); ?>> <?php esc_html_e('Remove shipping entirely', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[free_shipping][method]" value="percentage_off_shipping"<?php checked($method, 'percentage_off_shipping'); ?>> <?php esc_html_e('Percentage off shipping cost', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[free_shipping][method]" value="flat_off_shipping"<?php checked($method, 'flat_off_shipping'); ?>> <?php esc_html_e('Flat amount off shipping', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td>
            <input type="number" step="0.01" min="0" name="config_by_type[free_shipping][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text">
            <p class="description"><?php esc_html_e('Percentage (1–100) for percentage off; NT$ amount for flat off. Ignored when removing shipping entirely.', 'power-discount'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Apply to which shipping methods', 'power-discount'); ?></label></th>
        <td>
            <?php if ($shippingMethodOptions !== []): ?>
                <select
                    name="config_by_type[free_shipping][shipping_method_ids][]"
                    multiple
                    class="pd-shipping-method-select"
                    style="min-width:360px;min-height:140px;">
                    <?php foreach ($shippingMethodOptions as $optKey => $optLabel): ?>
                        <option value="<?php echo esc_attr($optKey); ?>"<?php selected(in_array($optKey, $selectedMethodIds, true)); ?>><?php echo esc_html($optLabel); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Leave empty to apply to ALL shipping methods. Hold ⌘ / Ctrl to select multiple.', 'power-discount'); ?></p>
            <?php else: ?>
                <p class="description"><?php esc_html_e('No shipping methods are configured in WooCommerce yet. Set up shipping zones in WooCommerce → Settings → Shipping first.', 'power-discount'); ?></p>
            <?php endif; ?>
        </td>
    </tr>
</table>
