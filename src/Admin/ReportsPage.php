<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Repository\ReportsRepository;

final class ReportsPage
{
    private ReportsRepository $reports;

    public function __construct(ReportsRepository $reports)
    {
        $this->reports = $reports;
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }

        $stats = $this->reports->getRuleStats();
        $totalDiscount = $this->reports->getTotalDiscount();
        $totalOrders = $this->reports->getTotalOrdersAffected();

        $rulesUrl = admin_url('admin.php?page=power-discount');

        require POWER_DISCOUNT_DIR . 'src/Admin/views/reports.php';
    }
}
