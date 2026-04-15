<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use InvalidArgumentException;
use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Repository\AddonRuleRepository;

final class AddonRuleEditPage
{
    private AddonRuleRepository $rules;
    private ?AddonRule $pendingRule = null;
    private string $pendingError = '';

    public function __construct(AddonRuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }

        $rule = $this->pendingRule;
        if ($rule === null) {
            $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            $rule = $id > 0 ? $this->rules->findById($id) : null;
        }
        if ($rule === null) {
            $rule = new AddonRule([
                'title'    => '',
                'status'   => 1,
                'priority' => 10,
            ]);
        }

        $isNew = $rule->getId() === 0;
        $pendingError = $this->pendingError;
        require POWER_DISCOUNT_DIR . 'src/Admin/views/addon-edit.php';
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }
        $postedId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        check_admin_referer('pd_save_addon_rule_' . $postedId);

        $post = wp_unslash($_POST);
        if (!is_array($post)) {
            $post = [];
        }

        try {
            $rule = AddonRuleFormMapper::fromFormData($post);
        } catch (InvalidArgumentException $e) {
            $this->pendingRule = AddonRuleFormMapper::fromFormDataLoose($post);
            $this->pendingError = $e->getMessage();
            $this->render();
            return;
        }

        if ($rule->getId() > 0) {
            $this->rules->update($rule);
            Notices::add(__('加價購規則已更新。', 'power-discount'), 'success');
        } else {
            $rule = self::withPriority($rule, $this->rules->getMaxPriority() + 1);
            $newId = $this->rules->insert($rule);
            Notices::add(__('加價購規則已建立。', 'power-discount'), 'success');
            wp_safe_redirect(add_query_arg([
                'page'   => 'power-discount-addons',
                'action' => 'edit',
                'id'     => $newId,
            ], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg([
            'page'   => 'power-discount-addons',
            'action' => 'edit',
            'id'     => $rule->getId(),
        ], admin_url('admin.php')));
        exit;
    }

    private static function withPriority(AddonRule $rule, int $priority): AddonRule
    {
        $itemsArr = array_map(static fn ($i) => $i->toArray(), $rule->getAddonItems());
        return new AddonRule([
            'id'                     => $rule->getId(),
            'title'                  => $rule->getTitle(),
            'status'                 => $rule->getStatus(),
            'priority'               => $priority,
            'addon_items'            => $itemsArr,
            'target_product_ids'     => $rule->getTargetProductIds(),
            'exclude_from_discounts' => $rule->isExcludeFromDiscounts(),
        ]);
    }
}
