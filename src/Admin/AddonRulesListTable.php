<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Repository\AddonRuleRepository;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class AddonRulesListTable extends \WP_List_Table
{
    private AddonRuleRepository $rules;

    public function __construct(AddonRuleRepository $rules)
    {
        parent::__construct([
            'singular' => 'addon_rule',
            'plural'   => 'addon_rules',
            'ajax'     => false,
        ]);
        $this->rules = $rules;
    }

    public function get_columns(): array
    {
        return [
            'order'     => esc_html__('Priority', 'power-discount') .
                ' <span class="pd-help-tip" data-tip="' .
                esc_attr__('拖拉列可調整套用順序。排在越上面越先套用。', 'power-discount') .
                '">?</span>',
            'status'    => __('Status', 'power-discount'),
            'title'     => __('Title', 'power-discount'),
            'addons'    => __('加價購商品', 'power-discount'),
            'targets'   => __('目標商品', 'power-discount'),
            'exclusive' => __('獨立定價', 'power-discount'),
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [$this->get_columns(), [], []];
        $position = 1;
        $rows = [];
        foreach ($this->rules->findAll() as $rule) {
            $rows[] = $this->ruleToRow($rule, $position++);
        }
        $this->items = $rows;
    }

    /** @param array<string, mixed> $item */
    public function single_row($item): void
    {
        echo '<tr data-id="' . (int) $item['id'] . '">';
        $this->single_row_columns($item);
        echo '</tr>';
    }

    /** @param array<string, mixed> $item */
    public function column_order($item): string
    {
        return '<span class="pd-drag-handle dashicons dashicons-menu" title="' .
            esc_attr__('Drag to reorder', 'power-discount') .
            '"></span><span class="pd-priority-pill">' . (int) $item['position'] . '</span>';
    }

    /** @param array<string, mixed> $item */
    public function column_status($item): string
    {
        $enabled = (int) $item['status'] === 1;
        $nonce = wp_create_nonce('power_discount_admin');
        return sprintf(
            '<label class="pd-toggle-switch" title="%s">'
            . '<input type="checkbox" class="pd-toggle-status-input" data-id="%d" data-nonce="%s" data-ajax-action="pd_toggle_addon_rule_status"%s>'
            . '<span class="pd-toggle-slider"></span>'
            . '</label>',
            esc_attr($enabled ? __('Click to disable', 'power-discount') : __('Click to enable', 'power-discount')),
            (int) $item['id'],
            esc_attr($nonce),
            $enabled ? ' checked' : ''
        );
    }

    /** @param array<string, mixed> $item */
    public function column_title($item): string
    {
        $editUrl = add_query_arg([
            'page'   => 'power-discount-addons',
            'action' => 'edit',
            'id'     => (int) $item['id'],
        ], admin_url('admin.php'));

        $deleteUrl = wp_nonce_url(add_query_arg([
            'action' => 'pd_delete_addon_rule',
            'id'     => (int) $item['id'],
        ], admin_url('admin-post.php')), 'pd_delete_addon_rule_' . $item['id']);

        $title = sprintf(
            '<strong><a href="%s">%s</a></strong>',
            esc_url($editUrl),
            esc_html((string) $item['title'])
        );
        $actions = sprintf(
            '<div class="row-actions"><span class="edit"><a href="%s">%s</a> | </span><span class="delete"><a href="%s" onclick="return confirm(\'%s\')">%s</a></span></div>',
            esc_url($editUrl),
            esc_html__('Edit', 'power-discount'),
            esc_url($deleteUrl),
            esc_js(__('確定要刪除這條加價購規則嗎？', 'power-discount')),
            esc_html__('Delete', 'power-discount')
        );
        return $title . $actions;
    }

    /** @param array<string, mixed> $item */
    public function column_addons($item): string
    {
        $count = (int) $item['addon_count'];
        if ($count === 0) {
            return '<span class="pd-muted">—</span>';
        }
        return sprintf(
            '<span class="pd-muted">' . esc_html__('%d 項商品', 'power-discount') . '</span>',
            $count
        );
    }

    /** @param array<string, mixed> $item */
    public function column_targets($item): string
    {
        $count = (int) $item['target_count'];
        if ($count === 0) {
            return '<span class="pd-muted">—</span>';
        }
        return sprintf(
            '<span class="pd-muted">' . esc_html__('%d 個目標', 'power-discount') . '</span>',
            $count
        );
    }

    /** @param array<string, mixed> $item */
    public function column_exclusive($item): string
    {
        return !empty($item['exclude_from_discounts'])
            ? '<span class="pd-addon-exclusive-yes">✓</span>'
            : '<span class="pd-muted">—</span>';
    }

    /** @return array<string, mixed> */
    private function ruleToRow(AddonRule $rule, int $position): array
    {
        return [
            'id'                     => $rule->getId(),
            'title'                  => $rule->getTitle(),
            'status'                 => $rule->getStatus(),
            'priority'               => $rule->getPriority(),
            'position'               => $position,
            'addon_count'            => count($rule->getAddonItems()),
            'target_count'           => count($rule->getTargetProductIds()),
            'exclude_from_discounts' => $rule->isExcludeFromDiscounts(),
        ];
    }
}
