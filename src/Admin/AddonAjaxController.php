<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Repository\AddonRuleRepository;

final class AddonAjaxController
{
    private AddonRuleRepository $rules;

    public function __construct(AddonRuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function register(): void
    {
        add_action('wp_ajax_pd_toggle_addon_rule_status', [$this, 'toggleStatus']);
        add_action('wp_ajax_pd_reorder_addon_rules', [$this, 'reorder']);
        add_action('wp_ajax_pd_toggle_addon_metabox_rule', [$this, 'toggleMetaboxRule']);
    }

    public function toggleMetaboxRule(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        check_ajax_referer('power_discount_admin', 'nonce');

        $ruleId    = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $role      = isset($_POST['role']) ? (string) $_POST['role'] : '';
        $attach    = !empty($_POST['attach']);

        $rule = $this->rules->findById($ruleId);
        if ($rule === null || $productId <= 0 || !in_array($role, ['target', 'addon'], true)) {
            wp_send_json_error(['message' => 'Invalid request'], 400);
        }

        $targets = $rule->getTargetProductIds();
        $items = array_map(static fn ($i) => $i->toArray(), $rule->getAddonItems());

        if ($role === 'target') {
            if ($attach && !in_array($productId, $targets, true)) {
                $targets[] = $productId;
            } elseif (!$attach) {
                $targets = array_values(array_filter($targets, static fn (int $id): bool => $id !== $productId));
            }
        } else {
            // role === 'addon'
            if ($attach && !$rule->containsAddon($productId)) {
                // Default special_price = 0; user should set it via the rule edit page
                $items[] = ['product_id' => $productId, 'special_price' => 0];
            } elseif (!$attach) {
                $items = array_values(array_filter(
                    $items,
                    static fn ($i) => (int) ($i['product_id'] ?? 0) !== $productId
                ));
            }
        }

        $updated = new AddonRule([
            'id'                     => $rule->getId(),
            'title'                  => $rule->getTitle(),
            'status'                 => $rule->getStatus(),
            'priority'               => $rule->getPriority(),
            'addon_items'            => $items,
            'target_product_ids'     => $targets,
            'exclude_from_discounts' => $rule->isExcludeFromDiscounts(),
        ]);
        $this->rules->update($updated);
        wp_send_json_success();
    }

    public function toggleStatus(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        check_ajax_referer('power_discount_admin', 'nonce');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $rule = $this->rules->findById($id);
        if ($rule === null) {
            wp_send_json_error(['message' => 'Rule not found'], 404);
        }

        $newStatus = $rule->isEnabled() ? 0 : 1;

        // Reconstruct AddonRule with flipped status (immutable pattern)
        $itemsArr = array_map(static fn ($i) => $i->toArray(), $rule->getAddonItems());
        $updated = new AddonRule([
            'id'                     => $rule->getId(),
            'title'                  => $rule->getTitle(),
            'status'                 => $newStatus,
            'priority'               => $rule->getPriority(),
            'addon_items'            => $itemsArr,
            'target_product_ids'     => $rule->getTargetProductIds(),
            'exclude_from_discounts' => $rule->isExcludeFromDiscounts(),
        ]);
        $this->rules->update($updated);

        wp_send_json_success(['status' => $newStatus]);
    }

    public function reorder(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        check_ajax_referer('power_discount_admin', 'nonce');

        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $ordered = array_values(array_filter(
            array_map('intval', $ids),
            static function (int $id): bool { return $id > 0; }
        ));
        if ($ordered === []) {
            wp_send_json_error(['message' => 'No ids provided'], 400);
        }

        $this->rules->reorder($ordered);
        wp_send_json_success(['count' => count($ordered)]);
    }
}
