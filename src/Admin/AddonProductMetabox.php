<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Repository\AddonRuleRepository;

final class AddonProductMetabox
{
    private AddonRuleRepository $rules;

    public function __construct(AddonRuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function register(): void
    {
        if (!AddonMenu::isEnabled()) {
            return;
        }
        add_action('add_meta_boxes_product', [$this, 'addMetabox']);
    }

    public function addMetabox(\WP_Post $post): void
    {
        add_meta_box(
            'pd-addon-relations',
            __('加價購關聯', 'power-discount'),
            [$this, 'renderMetabox'],
            'product',
            'side',
            'default'
        );
    }

    public function renderMetabox(\WP_Post $post): void
    {
        $productId = (int) $post->ID;
        $asTarget = $this->rules->findContainingTarget($productId);
        $asAddon = $this->rules->findContainingAddon($productId);
        // Show all active rules so user can attach to new ones via the dropdown
        $allRules = $this->rules->findAll();
        $nonce = wp_create_nonce('power_discount_admin');
        require POWER_DISCOUNT_DIR . 'src/Admin/views/addon-metabox.php';
    }
}
