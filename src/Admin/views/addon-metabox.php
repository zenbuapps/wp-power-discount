<?php
/**
 * @var int $productId
 * @var \PowerDiscount\Domain\AddonRule[] $asTarget
 * @var \PowerDiscount\Domain\AddonRule[] $asAddon
 * @var \PowerDiscount\Domain\AddonRule[] $allRules
 * @var string $nonce
 */
if (!defined('ABSPATH')) exit;

$attachedTargetIds = array_map(static fn ($r) => $r->getId(), $asTarget);
$attachedAddonIds  = array_map(static fn ($r) => $r->getId(), $asAddon);

$rulesListUrl = admin_url('admin.php?page=power-discount-addons');
?>
<div class="pd-addon-metabox" data-product-id="<?php echo (int) $productId; ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
    <p class="pd-addon-metabox-help">
        <?php esc_html_e('勾選下方規則可即時調整此商品的加價購關聯，不需要另外儲存商品。', 'power-discount'); ?>
    </p>

    <h4><?php esc_html_e('作為「目標商品」（出現加價購專區）', 'power-discount'); ?></h4>
    <?php if ($asTarget !== []): ?>
        <ul class="pd-addon-metabox-list">
            <?php foreach ($asTarget as $rule): ?>
                <li>
                    <label>
                        <input type="checkbox" class="pd-addon-metabox-checkbox" checked
                               data-rule-id="<?php echo (int) $rule->getId(); ?>"
                               data-role="target">
                        <?php echo esc_html($rule->getTitle()); ?>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="description pd-muted"><?php esc_html_e('尚未屬於任何加價購規則。', 'power-discount'); ?></p>
    <?php endif; ?>

    <h4><?php esc_html_e('作為「加價購商品」（被加購）', 'power-discount'); ?></h4>
    <?php if ($asAddon !== []): ?>
        <ul class="pd-addon-metabox-list">
            <?php foreach ($asAddon as $rule): ?>
                <li>
                    <label>
                        <input type="checkbox" class="pd-addon-metabox-checkbox" checked
                               data-rule-id="<?php echo (int) $rule->getId(); ?>"
                               data-role="addon">
                        <?php echo esc_html($rule->getTitle()); ?>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="description pd-muted"><?php esc_html_e('目前沒有被任何規則作為加價購商品。', 'power-discount'); ?></p>
    <?php endif; ?>

    <?php
    $unattachedForTarget = array_filter($allRules, static function ($r) use ($attachedTargetIds) {
        return !in_array($r->getId(), $attachedTargetIds, true);
    });
    ?>
    <?php if (!empty($unattachedForTarget)): ?>
        <p class="pd-addon-metabox-attach">
            <label>
                <?php esc_html_e('加到其他規則作為目標商品：', 'power-discount'); ?>
                <select class="pd-addon-metabox-attach-select" data-role="target">
                    <option value=""><?php esc_html_e('— 選擇 —', 'power-discount'); ?></option>
                    <?php foreach ($unattachedForTarget as $rule): ?>
                        <option value="<?php echo (int) $rule->getId(); ?>"><?php echo esc_html($rule->getTitle()); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
    <?php endif; ?>

    <p class="pd-addon-metabox-footer">
        <a href="<?php echo esc_url($rulesListUrl); ?>" target="_blank"><?php esc_html_e('前往加價購規則管理', 'power-discount'); ?> →</a>
    </p>
</div>

<script>
(function () {
    var $box = document.querySelector('.pd-addon-metabox');
    if (!$box) return;
    var productId = parseInt($box.dataset.productId, 10);
    var nonce = $box.dataset.nonce;
    var ajaxUrl = (window.PowerDiscountAdmin && PowerDiscountAdmin.ajaxUrl) || ajaxurl;

    function sendToggle(ruleId, role, attach, callback) {
        var fd = new FormData();
        fd.append('action', 'pd_toggle_addon_metabox_rule');
        fd.append('nonce', nonce);
        fd.append('rule_id', ruleId);
        fd.append('product_id', productId);
        fd.append('role', role);
        fd.append('attach', attach ? '1' : '0');
        return fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'include' })
            .then(function (r) { return r.json(); })
            .then(callback)
            .catch(function () { window.alert('請重試一次。'); });
    }

    $box.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList.contains('pd-addon-metabox-checkbox')) {
            sendToggle(t.dataset.ruleId, t.dataset.role, t.checked, function (resp) {
                if (!resp || !resp.success) {
                    t.checked = !t.checked;
                    window.alert('操作失敗。');
                }
            });
        } else if (t.classList.contains('pd-addon-metabox-attach-select') && t.value) {
            var ruleId = t.value;
            var role = t.dataset.role;
            sendToggle(ruleId, role, true, function (resp) {
                if (resp && resp.success) {
                    window.location.reload();
                } else {
                    t.value = '';
                    window.alert('操作失敗。');
                }
            });
        }
    });
})();
</script>
