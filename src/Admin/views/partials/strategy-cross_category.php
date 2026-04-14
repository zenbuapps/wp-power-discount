<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$groups = $config['groups'] ?? [];
if (empty($groups)) {
    $groups = [
        ['name' => '', 'category_ids' => [], 'min_qty' => 1],
        ['name' => '', 'category_ids' => [], 'min_qty' => 1],
    ];
}
$reward = (array) ($config['reward'] ?? []);
$rewardMethod = (string) ($reward['method'] ?? 'percentage');
$rewardValue = $reward['value'] ?? '';
$repeat = !empty($config['repeat']);
// Normalise existing groups that stored category ids under filter.value
foreach ($groups as $i => $g) {
    if (!isset($g['category_ids']) && isset($g['filter']['value'])) {
        $groups[$i]['category_ids'] = (array) $g['filter']['value'];
    }
}
?>
<table class="form-table">
    <tr>
        <th><?php esc_html_e('Groups (all must be satisfied)', 'power-discount'); ?></th>
        <td>
            <div class="pd-repeater" data-pd-repeater="xcat-group">
                <?php foreach ($groups as $i => $g):
                    $catIds = (array) ($g['category_ids'] ?? []);
                ?>
                    <div class="pd-repeater-row pd-group-row">
                        <button type="button" class="button button-small pd-repeater-remove pd-group-remove" title="<?php esc_attr_e('Remove group', 'power-discount'); ?>">×</button>
                        <div class="pd-group-field">
                            <label><?php esc_html_e('Group name', 'power-discount'); ?></label>
                            <input type="text" name="config_by_type[cross_category][groups][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr((string) ($g['name'] ?? '')); ?>" class="regular-text" placeholder="<?php esc_attr_e('e.g. Tops', 'power-discount'); ?>">
                        </div>
                        <div class="pd-group-field">
                            <label><?php esc_html_e('Categories', 'power-discount'); ?></label>
                            <select name="config_by_type[cross_category][groups][<?php echo (int) $i; ?>][category_ids][]" multiple class="pd-category-select" data-placeholder="<?php esc_attr_e('Select categories', 'power-discount'); ?>" style="min-width:300px;">
                                <?php foreach ($catIds as $cid): $cid = (int) $cid; $term = get_term($cid, 'product_cat'); if ($term && !is_wp_error($term)): ?>
                                    <option value="<?php echo $cid; ?>" selected><?php echo esc_html($term->name); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <div class="pd-group-field">
                            <label><?php esc_html_e('Min qty', 'power-discount'); ?></label>
                            <input type="number" name="config_by_type[cross_category][groups][<?php echo (int) $i; ?>][min_qty]" value="<?php echo (int) ($g['min_qty'] ?? 1); ?>" min="1" class="small-text">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button pd-repeater-add" data-pd-add="xcat-group">+ <?php esc_html_e('Add group', 'power-discount'); ?></button></p>
            <p class="description"><?php esc_html_e('Need at least 2 groups.', 'power-discount'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Reward', 'power-discount'); ?></label></th>
        <td>
            <select name="config_by_type[cross_category][reward][method]">
                <option value="percentage"<?php selected($rewardMethod, 'percentage'); ?>><?php esc_html_e('Percentage off bundle', 'power-discount'); ?></option>
                <option value="flat"<?php selected($rewardMethod, 'flat'); ?>><?php esc_html_e('Flat amount off bundle', 'power-discount'); ?></option>
                <option value="fixed_bundle_price"<?php selected($rewardMethod, 'fixed_bundle_price'); ?>><?php esc_html_e('Fixed bundle price', 'power-discount'); ?></option>
            </select>
            <input type="number" step="0.01" min="0" name="config_by_type[cross_category][reward][value]" value="<?php echo esc_attr((string) $rewardValue); ?>" class="small-text">
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Repeat', 'power-discount'); ?></label></th>
        <td><label><input type="checkbox" name="config_by_type[cross_category][repeat]" value="1"<?php checked($repeat, true); ?>> <?php esc_html_e('Form multiple bundles when possible', 'power-discount'); ?></label></td>
    </tr>
</table>
