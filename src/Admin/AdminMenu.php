<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Repository\RuleRepository;

final class AdminMenu
{
    private RuleRepository $rules;
    private RulesListPage $listPage;
    private RuleEditPage $editPage;
    private ReportsPage $reportsPage;

    public function __construct(RuleRepository $rules, RulesListPage $listPage, RuleEditPage $editPage, ReportsPage $reportsPage)
    {
        $this->rules = $rules;
        $this->listPage = $listPage;
        $this->editPage = $editPage;
        $this->reportsPage = $reportsPage;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_pd_save_rule', [$this->editPage, 'handleSave']);
        add_action('admin_post_pd_delete_rule', [$this->listPage, 'handleDelete']);
        add_action('admin_post_pd_duplicate_rule', [$this->listPage, 'handleDuplicate']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Power Discount', 'power-discount'),
            __('Power Discount', 'power-discount'),
            'manage_woocommerce',
            'power-discount',
            [$this, 'route']
        );
        add_submenu_page(
            'woocommerce',
            __('Power Discount Reports', 'power-discount'),
            __('PD Reports', 'power-discount'),
            'manage_woocommerce',
            'power-discount-reports',
            [$this->reportsPage, 'render']
        );
    }

    public function route(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'power-discount'));
        }

        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        if ($action === 'edit' || $action === 'new') {
            $this->editPage->render();
            return;
        }
        $this->listPage->render();
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (strpos($hookSuffix, 'power-discount') === false) {
            return;
        }

        // WC-enhanced-select for category/product pickers on the edit page
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('select2');

        wp_enqueue_style(
            'power-discount-admin',
            POWER_DISCOUNT_URL . 'assets/admin/admin.css',
            [],
            POWER_DISCOUNT_VERSION
        );
        wp_enqueue_script(
            'power-discount-admin',
            POWER_DISCOUNT_URL . 'assets/admin/admin.js',
            ['jquery', 'wc-enhanced-select'],
            POWER_DISCOUNT_VERSION,
            true
        );
        wp_localize_script('power-discount-admin', 'PowerDiscountAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('power_discount_admin'),
        ]);
    }
}
