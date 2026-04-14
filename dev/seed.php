<?php
/**
 * Power Discount dev seed script.
 *
 * Run via: docker exec power-discount-dev-cli wp eval-file /var/www/html/wp-content/plugins/power-discount/dev/seed.php --url=http://localhost:3303
 *
 * Idempotent — safe to re-run.
 */

if (!defined('ABSPATH')) {
    echo "Must run via wp eval-file\n";
    exit(1);
}
if (!class_exists('WooCommerce')) {
    echo "WooCommerce not active\n";
    exit(1);
}

// Silence transactional emails during seed.
add_filter('pre_wp_mail', '__return_true');

global $wpdb;
$rules_table = $wpdb->prefix . 'pd_rules';
$order_discounts_table = $wpdb->prefix . 'pd_order_discounts';

echo "=== Power Discount seed ===\n\n";

// --- 1. Categories ---
$categories = [
    'coffee-beans' => '咖啡豆',
    'coffee-gear'  => '咖啡器具',
    'tops'         => '上衣',
    'pants'        => '褲子',
];
$cat_ids = [];
foreach ($categories as $slug => $name) {
    $term = get_term_by('slug', $slug, 'product_cat');
    if ($term) {
        $cat_ids[$slug] = (int) $term->term_id;
    } else {
        $result = wp_insert_term($name, 'product_cat', ['slug' => $slug]);
        if (is_wp_error($result)) {
            echo "  FAIL category {$name}: " . $result->get_error_message() . "\n";
            continue;
        }
        $cat_ids[$slug] = (int) $result['term_id'];
        echo "  + category {$name} (#{$cat_ids[$slug]})\n";
    }
}

// --- 2. Tags ---
$tag_defs = [
    'new-arrival' => '新品',
    'best-seller' => '熱銷',
];
$tag_ids = [];
foreach ($tag_defs as $slug => $name) {
    $term = get_term_by('slug', $slug, 'product_tag');
    if ($term) {
        $tag_ids[$slug] = (int) $term->term_id;
    } else {
        $result = wp_insert_term($name, 'product_tag', ['slug' => $slug]);
        if (is_wp_error($result)) {
            continue;
        }
        $tag_ids[$slug] = (int) $result['term_id'];
        echo "  + tag {$name} (#{$tag_ids[$slug]})\n";
    }
}

// --- 3. Products ---
$products_def = [
    // Coffee beans
    ['sku' => 'BEAN-ETH',    'name' => '衣索比亞耶加雪菲', 'price' => 480, 'cat' => 'coffee-beans', 'tags' => ['new-arrival']],
    ['sku' => 'BEAN-COL',    'name' => '哥倫比亞薇拉',    'price' => 420, 'cat' => 'coffee-beans', 'tags' => ['best-seller']],
    ['sku' => 'BEAN-BRA',    'name' => '巴西喜拉朵',      'price' => 360, 'cat' => 'coffee-beans', 'tags' => []],
    ['sku' => 'BEAN-KEN',    'name' => '肯亞 AA',         'price' => 520, 'cat' => 'coffee-beans', 'tags' => ['new-arrival']],
    // Coffee gear
    ['sku' => 'GEAR-FILTER', 'name' => '濾紙 100 入',      'price' => 120, 'cat' => 'coffee-gear', 'tags' => []],
    ['sku' => 'GEAR-DRIP',   'name' => 'V60 濾杯',        'price' => 380, 'cat' => 'coffee-gear', 'tags' => []],
    ['sku' => 'GEAR-CARAFE', 'name' => '玻璃分享壺',      'price' => 680, 'cat' => 'coffee-gear', 'tags' => []],
    // Tops
    ['sku' => 'TOP-TEE',     'name' => '棉質 T 恤',        'price' => 590, 'cat' => 'tops',        'tags' => ['best-seller']],
    ['sku' => 'TOP-POLO',    'name' => 'POLO 衫',         'price' => 890, 'cat' => 'tops',        'tags' => []],
    ['sku' => 'TOP-HOODIE',  'name' => '連帽 T',           'price' => 1290, 'cat' => 'tops',       'tags' => ['new-arrival']],
    // Pants
    ['sku' => 'PANT-CHINO',  'name' => '卡其褲',          'price' => 990,  'cat' => 'pants',      'tags' => []],
    ['sku' => 'PANT-DENIM',  'name' => '牛仔褲',          'price' => 1490, 'cat' => 'pants',      'tags' => ['best-seller']],
];
$product_ids_by_sku = [];
foreach ($products_def as $p) {
    $existing = wc_get_product_id_by_sku($p['sku']);
    if ($existing) {
        $product_ids_by_sku[$p['sku']] = (int) $existing;
        continue;
    }
    $prod = new WC_Product_Simple();
    $prod->set_name($p['name']);
    $prod->set_sku($p['sku']);
    $prod->set_regular_price((string) $p['price']);
    $prod->set_manage_stock(false);
    $prod->set_stock_status('instock');
    $prod->set_catalog_visibility('visible');
    $prod->set_status('publish');
    $prod->set_category_ids([$cat_ids[$p['cat']]]);
    $tids = [];
    foreach ($p['tags'] as $tslug) {
        if (isset($tag_ids[$tslug])) {
            $tids[] = $tag_ids[$tslug];
        }
    }
    if ($tids) {
        $prod->set_tag_ids($tids);
    }
    $id = $prod->save();
    $product_ids_by_sku[$p['sku']] = (int) $id;
    echo "  + product {$p['name']} (#{$id}, {$p['sku']}, NT\${$p['price']})\n";
}

// --- 4. Discount rules ---
// Clear stale "Seeded by" notes from previous seed runs
$wpdb->query("UPDATE {$rules_table} SET notes = NULL WHERE notes = 'Seeded by dev/seed.php'");
$rule_defs = [
    [
        'title' => '全站 95 折', 'type' => 'simple', 'priority' => 50,
        'config' => ['method' => 'percentage', 'value' => 5],
        'filters' => [], 'conditions' => [], 'label' => '全站優惠',
    ],
    [
        'title' => '咖啡豆階梯折扣', 'type' => 'bulk', 'priority' => 20,
        'config' => [
            'count_scope' => 'cumulative',
            'ranges' => [
                ['from' => 1,  'to' => 4,    'method' => 'percentage', 'value' => 0],
                ['from' => 5,  'to' => 9,    'method' => 'percentage', 'value' => 10],
                ['from' => 10, 'to' => null, 'method' => 'percentage', 'value' => 20],
            ],
        ],
        'filters' => ['items' => [['type' => 'categories', 'method' => 'in', 'ids' => [$cat_ids['coffee-beans']]]]],
        'conditions' => [],
        'label' => '咖啡豆多買多折',
    ],
    [
        'title' => '滿千折百', 'type' => 'cart', 'priority' => 30,
        'config' => ['method' => 'flat_total', 'value' => 100],
        'filters' => [],
        'conditions' => ['logic' => 'and', 'items' => [['type' => 'cart_subtotal', 'operator' => '>=', 'value' => 1000]]],
        'label' => '滿千折百',
    ],
    [
        'title' => '咖啡器具任選 2 件 $500', 'type' => 'set', 'priority' => 25,
        'config' => ['bundle_size' => 2, 'method' => 'set_price', 'value' => 500, 'repeat' => true],
        'filters' => ['items' => [['type' => 'categories', 'method' => 'in', 'ids' => [$cat_ids['coffee-gear']]]]],
        'conditions' => [],
        'label' => '咖啡器具組合價',
    ],
    [
        'title' => '上衣任選 3 件現折 $300', 'type' => 'set', 'priority' => 26,
        'config' => ['bundle_size' => 3, 'method' => 'set_flat_off', 'value' => 300, 'repeat' => false],
        'filters' => ['items' => [['type' => 'categories', 'method' => 'in', 'ids' => [$cat_ids['tops']]]]],
        'conditions' => [],
        'label' => '上衣 3 件現折 $300',
    ],
    [
        'title' => '買 2 送 1 最便宜（咖啡豆）', 'type' => 'buy_x_get_y', 'priority' => 15,
        'config' => [
            'trigger' => ['source' => 'filter', 'qty' => 2],
            'reward'  => ['target' => 'cheapest_in_cart', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => true,
        ],
        'filters' => ['items' => [['type' => 'categories', 'method' => 'in', 'ids' => [$cat_ids['coffee-beans']]]]],
        'conditions' => [],
        'label' => '買 2 送 1',
    ],
    [
        'title' => '第二件 6 折（衣物）', 'type' => 'nth_item', 'priority' => 40,
        'config' => [
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 40],
            ],
            'sort_by' => 'price_desc',
            'recursive' => true,
        ],
        'filters' => ['items' => [['type' => 'categories', 'method' => 'in', 'ids' => [$cat_ids['tops'], $cat_ids['pants']]]]],
        'conditions' => [],
        'label' => '第二件 6 折',
    ],
    [
        'title' => '紅配綠：上衣+褲子整組 8 折', 'type' => 'cross_category', 'priority' => 35,
        'config' => [
            'groups' => [
                ['name' => '上衣', 'filter' => ['type' => 'categories', 'value' => [$cat_ids['tops']]], 'min_qty' => 1],
                ['name' => '褲子', 'filter' => ['type' => 'categories', 'value' => [$cat_ids['pants']]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'percentage', 'value' => 20],
            'repeat' => true,
        ],
        'filters' => [], 'conditions' => [],
        'label' => '紅配綠 8 折',
    ],
    [
        'title' => '滿 $1500 免運', 'type' => 'free_shipping', 'priority' => 10,
        'config' => ['method' => 'remove_shipping'],
        'filters' => [],
        'conditions' => ['logic' => 'and', 'items' => [['type' => 'cart_subtotal', 'operator' => '>=', 'value' => 1500]]],
        'label' => '滿 $1500 免運',
    ],
];

$rule_ids_by_title = [];
$now = gmdate('Y-m-d H:i:s');
foreach ($rule_defs as $r) {
    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$rules_table} WHERE title = %s LIMIT 1", $r['title']));
    if ($existing) {
        $rule_ids_by_title[$r['title']] = (int) $existing;
        continue;
    }
    $wpdb->insert($rules_table, [
        'title'       => $r['title'],
        'type'        => $r['type'],
        'status'      => 1,
        'priority'    => $r['priority'],
        'exclusive'   => 0,
        'starts_at'   => null,
        'ends_at'     => null,
        'usage_limit' => null,
        'used_count'  => 0,
        'filters'     => wp_json_encode($r['filters']),
        'conditions'  => wp_json_encode($r['conditions']),
        'config'      => wp_json_encode($r['config']),
        'label'       => $r['label'] ?? null,
        'notes'       => null,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
    $rule_ids_by_title[$r['title']] = (int) $wpdb->insert_id;
    echo "  + rule {$r['title']} (#{$rule_ids_by_title[$r['title']]}, type={$r['type']})\n";
}

// --- 5. Sample orders ---
// Idempotency: skip if any seeded orders exist.
$existing_seeded = $wpdb->get_var("SELECT COUNT(*) FROM {$order_discounts_table} WHERE rule_title IN ('" . implode("','", array_map('esc_sql', array_keys($rule_ids_by_title))) . "')");
if ((int) $existing_seeded > 0) {
    echo "\n  (order seeding skipped: {$existing_seeded} discount records already present)\n";
} else {
    $sample_orders = [
        [
            'days_ago' => 25,
            'items'    => [['sku' => 'BEAN-ETH', 'qty' => 5], ['sku' => 'BEAN-COL', 'qty' => 3]],
            'discounts' => [
                ['rule_title' => '咖啡豆階梯折扣', 'amount' => 396.00, 'scope' => 'product'],
            ],
        ],
        [
            'days_ago' => 20,
            'items'    => [['sku' => 'TOP-TEE', 'qty' => 2], ['sku' => 'PANT-CHINO', 'qty' => 1]],
            'discounts' => [
                ['rule_title' => '紅配綠：上衣+褲子整組 8 折', 'amount' => 316.00, 'scope' => 'product'],
            ],
        ],
        [
            'days_ago' => 15,
            'items'    => [['sku' => 'BEAN-KEN', 'qty' => 2], ['sku' => 'BEAN-BRA', 'qty' => 1]],
            'discounts' => [
                ['rule_title' => '買 2 送 1 最便宜（咖啡豆）', 'amount' => 360.00, 'scope' => 'product'],
            ],
        ],
        [
            'days_ago' => 10,
            'items'    => [['sku' => 'GEAR-DRIP', 'qty' => 1], ['sku' => 'GEAR-CARAFE', 'qty' => 1]],
            'discounts' => [
                ['rule_title' => '咖啡器具任選 2 件 $500', 'amount' => 560.00, 'scope' => 'product'],
            ],
        ],
        [
            'days_ago' => 5,
            'items'    => [['sku' => 'TOP-POLO', 'qty' => 2], ['sku' => 'GEAR-DRIP', 'qty' => 1]],
            'discounts' => [
                ['rule_title' => '第二件 6 折（衣物）', 'amount' => 356.00, 'scope' => 'product'],
                ['rule_title' => '滿千折百',           'amount' => 100.00, 'scope' => 'cart'],
            ],
        ],
        [
            'days_ago' => 2,
            'items'    => [['sku' => 'BEAN-ETH', 'qty' => 2], ['sku' => 'GEAR-FILTER', 'qty' => 1]],
            'discounts' => [
                ['rule_title' => '全站 95 折', 'amount' => 54.00, 'scope' => 'product'],
            ],
        ],
    ];

    foreach ($sample_orders as $o) {
        $order = wc_create_order();
        foreach ($o['items'] as $item) {
            if (!isset($product_ids_by_sku[$item['sku']])) {
                continue;
            }
            $product = wc_get_product($product_ids_by_sku[$item['sku']]);
            if (!$product) {
                continue;
            }
            $order->add_product($product, $item['qty']);
        }
        $order->set_billing_first_name('Test');
        $order->set_billing_last_name('Customer');
        $order->set_billing_email('test@example.com');
        $order->set_billing_country('TW');

        // Backdate the order.
        $order_date_ts = strtotime("-{$o['days_ago']} days");
        $date_string = gmdate('Y-m-d H:i:s', $order_date_ts);
        $order->set_date_created($order_date_ts);

        $order->calculate_totals();
        $order->set_status('completed');
        $order_id = (int) $order->save();

        // Insert discount records, backdated to match the order.
        foreach ($o['discounts'] as $d) {
            if (!isset($rule_ids_by_title[$d['rule_title']])) {
                continue;
            }
            $rule_id = $rule_ids_by_title[$d['rule_title']];
            $rule_type = $wpdb->get_var($wpdb->prepare("SELECT type FROM {$rules_table} WHERE id = %d", $rule_id));
            $wpdb->insert($order_discounts_table, [
                'order_id'        => $order_id,
                'rule_id'         => $rule_id,
                'rule_title'      => $d['rule_title'],
                'rule_type'       => (string) $rule_type,
                'discount_amount' => (float) $d['amount'],
                'scope'           => $d['scope'],
                'meta'            => '{}',
                'created_at'      => $date_string,
            ]);
            $wpdb->query($wpdb->prepare("UPDATE {$rules_table} SET used_count = used_count + 1 WHERE id = %d", $rule_id));
        }
        echo "  + order #{$order_id} (-{$o['days_ago']}d) with " . count($o['discounts']) . " discount record(s)\n";
    }
}

// --- Summary ---
echo "\n=== Summary ===\n";
echo "Products:        " . count($product_ids_by_sku) . "\n";
echo "Categories:      " . count($cat_ids) . "\n";
echo "Rules:           " . count($rule_ids_by_title) . "\n";
$total_orders = (int) $wpdb->get_var("SELECT COUNT(DISTINCT order_id) FROM {$order_discounts_table}");
$total_discount = (float) $wpdb->get_var("SELECT SUM(discount_amount) FROM {$order_discounts_table}");
echo "Orders w/ discount: {$total_orders}\n";
echo "Total discount recorded: NT\$" . number_format($total_discount, 2) . "\n";
echo "\nDone!\n";
