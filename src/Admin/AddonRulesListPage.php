<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Repository\AddonRuleRepository;

final class AddonRulesListPage
{
    private AddonRuleRepository $rules;

    public function __construct(AddonRuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }

        $table = new AddonRulesListTable($this->rules);
        $table->prepare_items();

        $newUrl = add_query_arg(
            ['page' => 'power-discount-addons', 'action' => 'new'],
            admin_url('admin.php')
        );
        $deactivateUrl = wp_nonce_url(
            add_query_arg(['action' => 'pd_deactivate_addons'], admin_url('admin-post.php')),
            'pd_deactivate_addons'
        );

        require POWER_DISCOUNT_DIR . 'src/Admin/views/addon-list.php';
    }

    public function handleDelete(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('pd_delete_addon_rule_' . $id);
        $this->rules->delete($id);
        Notices::add(__('加價購規則已刪除。', 'power-discount'), 'success');
        wp_safe_redirect(add_query_arg(['page' => 'power-discount-addons'], admin_url('admin.php')));
        exit;
    }
}
