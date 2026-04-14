<?php
declare(strict_types=1);

namespace PowerDiscount\Frontend;

use PowerDiscount\Domain\Rule;
use PowerDiscount\Repository\RuleRepository;

final class PriceTableShortcode
{
    private RuleRepository $rules;

    public function __construct(RuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function register(): void
    {
        add_shortcode('power_discount_table', [$this, 'render']);
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function render($atts = []): string
    {
        if (!is_array($atts)) {
            $atts = [];
        }
        $atts = shortcode_atts(['id' => 0], $atts, 'power_discount_table');
        $productId = (int) $atts['id'];
        if ($productId <= 0) {
            return '';
        }

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        if (!$product) {
            return '';
        }

        $allRules = $this->rules->getActiveRules();
        $bulkRules = $this->collectMatchingBulkRules($allRules, $productId, $product);
        if ($bulkRules === []) {
            return '';
        }

        ob_start();
        echo '<table class="pd-price-table">';
        echo '<thead><tr><th>' . esc_html__('Quantity', 'power-discount') . '</th><th>' . esc_html__('Discount', 'power-discount') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($bulkRules as $rule) {
            $config = $rule->getConfig();
            $ranges = $config['ranges'] ?? [];
            if (!is_array($ranges)) {
                continue;
            }
            foreach ($ranges as $range) {
                $from = (int) ($range['from'] ?? 0);
                $to = isset($range['to']) && $range['to'] !== null ? (int) $range['to'] : null;
                $method = (string) ($range['method'] ?? 'percentage');
                $value = (float) ($range['value'] ?? 0);
                if ($value <= 0) {
                    continue;
                }

                $qtyLabel = $to === null
                    ? sprintf(__('%d+', 'power-discount'), $from)
                    : sprintf('%d – %d', $from, $to);
                $discountLabel = $method === 'percentage'
                    ? sprintf('%s %%', rtrim(rtrim(number_format($value, 2), '0'), '.'))
                    : (function_exists('wc_price') ? wp_strip_all_tags(wc_price($value)) : number_format($value, 2));

                echo '<tr><td>' . esc_html($qtyLabel) . '</td><td>' . esc_html($discountLabel) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
        return (string) ob_get_clean();
    }

    /**
     * @param Rule[] $rules
     * @return Rule[]
     */
    private function collectMatchingBulkRules(array $rules, int $productId, $product): array
    {
        $matched = [];
        $categoryIds = method_exists($product, 'get_category_ids') ? (array) $product->get_category_ids() : [];
        $categoryIds = array_map('intval', $categoryIds);

        foreach ($rules as $rule) {
            if ($rule->getType() !== 'bulk') {
                continue;
            }
            $filters = $rule->getFilters();
            $items = $filters['items'] ?? [];
            if (!is_array($items) || $items === []) {
                $matched[] = $rule;
                continue;
            }
            foreach ($items as $filterItem) {
                if (!is_array($filterItem)) {
                    continue;
                }
                $type = (string) ($filterItem['type'] ?? '');
                if ($type === 'all_products') {
                    $matched[] = $rule;
                    break;
                }
                if ($type === 'products') {
                    $ids = array_map('intval', (array) ($filterItem['ids'] ?? []));
                    $method = (string) ($filterItem['method'] ?? 'in');
                    $hit = in_array($productId, $ids, true);
                    if (($method === 'in' && $hit) || ($method === 'not_in' && !$hit)) {
                        $matched[] = $rule;
                        break;
                    }
                }
                if ($type === 'categories') {
                    $ids = array_map('intval', (array) ($filterItem['ids'] ?? []));
                    $method = (string) ($filterItem['method'] ?? 'in');
                    $hit = false;
                    foreach ($categoryIds as $cat) {
                        if (in_array($cat, $ids, true)) {
                            $hit = true;
                            break;
                        }
                    }
                    if (($method === 'in' && $hit) || ($method === 'not_in' && !$hit)) {
                        $matched[] = $rule;
                        break;
                    }
                }
            }
        }
        return $matched;
    }
}
