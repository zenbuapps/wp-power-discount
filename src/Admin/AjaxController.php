<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Domain\Rule;
use PowerDiscount\Domain\RuleStatus;
use PowerDiscount\Repository\RuleRepository;

final class AjaxController
{
    private RuleRepository $rules;

    public function __construct(RuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function register(): void
    {
        add_action('wp_ajax_pd_toggle_rule_status', [$this, 'toggleStatus']);
        add_action('wp_ajax_pd_search_terms', [$this, 'searchTerms']);
        add_action('wp_ajax_pd_reorder_rules', [$this, 'reorderRules']);
    }

    public function reorderRules(): void
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

        $newStatus = $rule->isEnabled() ? RuleStatus::DISABLED : RuleStatus::ENABLED;

        $updated = new Rule([
            'id'          => $rule->getId(),
            'title'       => $rule->getTitle(),
            'type'        => $rule->getType(),
            'status'      => $newStatus,
            'priority'    => $rule->getPriority(),
            'exclusive'   => $rule->isExclusive(),
            'starts_at'   => $rule->getStartsAt(),
            'ends_at'     => $rule->getEndsAt(),
            'usage_limit' => $rule->getUsageLimit(),
            'used_count'  => $rule->getUsedCount(),
            'config'      => $rule->getConfig(),
            'filters'     => $rule->getFilters(),
            'conditions'  => $rule->getConditions(),
            'label'       => $rule->getLabel(),
            'notes'       => $rule->getNotes(),
        ]);
        $this->rules->update($updated);

        wp_send_json_success(['status' => $newStatus]);
    }

    public function searchTerms(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
        check_ajax_referer('power_discount_admin', 'nonce');

        $taxonomy = isset($_GET['taxonomy']) ? sanitize_key((string) $_GET['taxonomy']) : '';
        $q = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
        if (!in_array($taxonomy, ['product_cat', 'product_tag'], true)) {
            wp_send_json_success([]);
        }
        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => 20,
            'search'     => $q,
        ]);
        $results = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                $results[] = ['id' => $term->term_id, 'text' => $term->name];
            }
        }
        wp_send_json_success($results);
    }
}
