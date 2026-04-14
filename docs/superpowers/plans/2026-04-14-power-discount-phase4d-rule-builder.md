# Power Discount — Phase 4d: GUI Rule Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Phase 4b JSON-textarea rule editor with a proper GUI that a non-developer can use. Dynamic strategy-specific config forms, Filter/Condition row builders with add/remove, and WC's built-in enhanced selects for category/product pickers. Reference UX: WooCommerce Discount Rules plugin.

**Architecture:** Stay with PHP + jQuery (no React build step), consistent with Phase 4b. Form inputs use nested names (`config_by_type[<type>][<field>]`, `filters[items][<idx>][<field>]`, `conditions[items][<idx>][<field>]`) so PHP auto-parses them into structured arrays. `RuleFormMapper` is refactored to accept these arrays directly instead of JSON strings.

**Tech Stack:** PHP 7.4+, PHPUnit 9.6, jQuery (WP core), select2 (WC built-in via `wc-enhanced-select`).

**Phase 定位:**
- Phase 1 ✅ Foundation + 4 core strategies
- Phase 2 ✅ Repository + Engine + WC hooks
- Phase 3 ✅ Taiwan strategies
- Phase 4a ✅ Conditions + Filters + ShippingHooks
- Phase 4b ✅ PHP Admin UI (list + JSON-textarea editor)
- Phase 4c ✅ Frontend components + Reports
- **Phase 4d (this)**: GUI rule builder — replaces JSON textareas with proper form controls

---

## Scope

**Remove from the edit page:**
- `config_json` textarea
- `filters_json` textarea
- `conditions_json` textarea
- `notes` (internal notes) field — entirely gone

**Add:**
- Strategy-specific config sub-forms for all 8 types, swapped via type select
- Filter row builder (6 filter types: all_products, products, categories, tags, attributes, on_sale)
- Condition row builder (13 condition types)
- WC enhanced-select for product/category/tag pickers
- Generic JS "repeater" for add/remove of nested row structures

**Not in scope (Phase 4e or later):**
- Live discount preview
- Drag-sort priority on list page
- Rule templates / recipes
- Export/import

---

## File Structure

New:
```
src/Admin/views/partials/
├── strategy-simple.php
├── strategy-bulk.php
├── strategy-cart.php
├── strategy-set.php
├── strategy-buy_x_get_y.php
├── strategy-nth_item.php
├── strategy-cross_category.php
├── strategy-free_shipping.php
├── filter-builder.php
└── condition-builder.php

assets/admin/
├── admin.js       (expanded with repeater JS)
└── admin.css      (new — rule editor spacing/row styling)
```

Modified:
- `src/Admin/RuleFormMapper.php` — new contract: accepts arrays, not JSON strings
- `src/Admin/RuleEditPage.php` — pass `$rule` to view directly
- `src/Admin/AdminMenu.php` — enqueue WC enhanced-select + new admin.css on edit page
- `src/Admin/views/rule-edit.php` — rewritten
- `tests/Unit/Admin/RuleFormMapperTest.php` — updated for new contract
- `dev/seed.php` — remove the `notes` field population

Database cleanup (manual, one-off): clear existing `notes` values on previously-seeded rules so the UI doesn't show them.

---

## Contract change: RuleFormMapper

### Old ($_POST shape)
```
title, type, status, priority, exclusive, starts_at, ends_at, usage_limit,
label, notes, config_json, filters_json, conditions_json
```

### New ($_POST shape)
```
title, type, status, priority, exclusive, starts_at, ends_at, usage_limit,
label,
config_by_type[simple][method], config_by_type[simple][value], …
config_by_type[bulk][count_scope], config_by_type[bulk][ranges][0][from], …
…
filters[items][0][type], filters[items][0][method], filters[items][0][ids][], …
conditions[logic], conditions[items][0][type], conditions[items][0][value], …
```

`fromFormData` picks `$post['config_by_type'][$post['type']]` and ignores the other types' stale data. Filters/conditions are parsed directly.

**Per-type validation**: each strategy type has its own required-fields check. Throws `InvalidArgumentException` with field-level message on failure.

### Validation rules

| Type | Required |
|---|---|
| `simple` | method in [percentage, flat, fixed_price], value > 0 |
| `bulk` | count_scope in [cumulative, per_item, per_category], ≥1 range with from≥1 and method+value |
| `cart` | method in [percentage, flat_total, flat_per_item], value > 0 |
| `set` | bundle_size ≥ 2, method in [set_price, set_percentage, set_flat_off], value ≥ 0 |
| `buy_x_get_y` | trigger.qty ≥ 1, reward.qty ≥ 1, reward.target and reward.method set |
| `nth_item` | ≥1 tier with nth ≥ 1 and method+value |
| `cross_category` | ≥2 groups, each with category filter and min_qty ≥ 1; reward method+value |
| `free_shipping` | method in [remove_shipping, percentage_off_shipping] |

---

## Ground Rules

- `<?php declare(strict_types=1);` for all PHP classes (views/partials don't need it)
- PHP 7.4 compatible
- Every input in views must be escaped (`esc_attr`, `esc_textarea`, `esc_html`, `esc_url`)
- Every name attribute sanitized / structured
- `wp_kses_post` for messages with HTML
- TDD for `RuleFormMapper` refactor; views/JS are manually verified
- Per-task commits, Conventional Commits style
- `git -c user.email=luke@local -c user.name=Luke commit -m "..."`

---

## Tasks

### Task 1: Refactor RuleFormMapper to structured arrays (TDD)

**Files:**
- Modify: `src/Admin/RuleFormMapper.php`
- Modify: `tests/Unit/Admin/RuleFormMapperTest.php`

### 1a. Replace entire `src/Admin/RuleFormMapper.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use InvalidArgumentException;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Domain\RuleStatus;

final class RuleFormMapper
{
    private const VALID_TYPES = [
        'simple', 'bulk', 'cart', 'set',
        'buy_x_get_y', 'nth_item', 'cross_category', 'free_shipping',
    ];

    /**
     * Build a Rule from form POST data.
     *
     * @param array<string, mixed> $post
     */
    public static function fromFormData(array $post): Rule
    {
        $title = trim((string) ($post['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Rule title is required.');
        }

        $type = (string) ($post['type'] ?? '');
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid rule type: %s', $type));
        }

        $configByType = (array) ($post['config_by_type'] ?? []);
        $config = isset($configByType[$type]) && is_array($configByType[$type])
            ? self::normaliseConfig($type, $configByType[$type])
            : [];
        self::validateConfig($type, $config);

        $filters = self::normaliseFilters((array) ($post['filters'] ?? []));
        $conditions = self::normaliseConditions((array) ($post['conditions'] ?? []));

        $startsAt = trim((string) ($post['starts_at'] ?? ''));
        $endsAt = trim((string) ($post['ends_at'] ?? ''));
        if ($startsAt !== '' && !self::isValidDateString($startsAt)) {
            throw new InvalidArgumentException('Invalid starts_at format. Expected YYYY-MM-DD HH:MM:SS.');
        }
        if ($endsAt !== '' && !self::isValidDateString($endsAt)) {
            throw new InvalidArgumentException('Invalid ends_at format. Expected YYYY-MM-DD HH:MM:SS.');
        }

        $usageLimitRaw = trim((string) ($post['usage_limit'] ?? ''));
        $usageLimit = $usageLimitRaw === '' ? null : (int) $usageLimitRaw;

        return new Rule([
            'id'          => (int) ($post['id'] ?? 0),
            'title'       => $title,
            'type'        => $type,
            'status'      => isset($post['status']) ? (int) $post['status'] : RuleStatus::ENABLED,
            'priority'    => isset($post['priority']) ? (int) $post['priority'] : 10,
            'exclusive'   => !empty($post['exclusive']),
            'starts_at'   => $startsAt === '' ? null : $startsAt,
            'ends_at'     => $endsAt === '' ? null : $endsAt,
            'usage_limit' => $usageLimit,
            'used_count'  => 0,
            'filters'     => $filters,
            'conditions'  => $conditions,
            'config'      => $config,
            'label'       => isset($post['label']) && $post['label'] !== '' ? (string) $post['label'] : null,
            'notes'       => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normaliseConfig(string $type, array $raw): array
    {
        switch ($type) {
            case 'simple':
                return [
                    'method' => (string) ($raw['method'] ?? ''),
                    'value'  => isset($raw['value']) ? (float) $raw['value'] : 0.0,
                ];
            case 'bulk':
                $ranges = [];
                foreach ((array) ($raw['ranges'] ?? []) as $r) {
                    if (!is_array($r)) continue;
                    $from = isset($r['from']) && $r['from'] !== '' ? (int) $r['from'] : 0;
                    $toRaw = $r['to'] ?? '';
                    $to = ($toRaw === '' || $toRaw === null) ? null : (int) $toRaw;
                    $ranges[] = [
                        'from'   => $from,
                        'to'     => $to,
                        'method' => (string) ($r['method'] ?? 'percentage'),
                        'value'  => isset($r['value']) ? (float) $r['value'] : 0.0,
                    ];
                }
                return [
                    'count_scope' => (string) ($raw['count_scope'] ?? 'cumulative'),
                    'ranges'      => $ranges,
                ];
            case 'cart':
                return [
                    'method' => (string) ($raw['method'] ?? ''),
                    'value'  => isset($raw['value']) ? (float) $raw['value'] : 0.0,
                ];
            case 'set':
                return [
                    'bundle_size' => isset($raw['bundle_size']) ? (int) $raw['bundle_size'] : 0,
                    'method'      => (string) ($raw['method'] ?? ''),
                    'value'       => isset($raw['value']) ? (float) $raw['value'] : 0.0,
                    'repeat'      => !empty($raw['repeat']),
                ];
            case 'buy_x_get_y':
                $trigger = (array) ($raw['trigger'] ?? []);
                $reward = (array) ($raw['reward'] ?? []);
                return [
                    'trigger' => [
                        'source'      => (string) ($trigger['source'] ?? 'filter'),
                        'qty'         => isset($trigger['qty']) ? (int) $trigger['qty'] : 0,
                        'product_ids' => array_map('intval', (array) ($trigger['product_ids'] ?? [])),
                    ],
                    'reward' => [
                        'target'      => (string) ($reward['target'] ?? 'same'),
                        'qty'         => isset($reward['qty']) ? (int) $reward['qty'] : 0,
                        'method'      => (string) ($reward['method'] ?? 'free'),
                        'value'       => isset($reward['value']) ? (float) $reward['value'] : 0.0,
                        'product_ids' => array_map('intval', (array) ($reward['product_ids'] ?? [])),
                    ],
                    'recursive' => !empty($raw['recursive']),
                ];
            case 'nth_item':
                $tiers = [];
                foreach ((array) ($raw['tiers'] ?? []) as $t) {
                    if (!is_array($t)) continue;
                    $tiers[] = [
                        'nth'    => isset($t['nth']) ? (int) $t['nth'] : 0,
                        'method' => (string) ($t['method'] ?? 'percentage'),
                        'value'  => isset($t['value']) ? (float) $t['value'] : 0.0,
                    ];
                }
                return [
                    'tiers'     => $tiers,
                    'sort_by'   => (string) ($raw['sort_by'] ?? 'price_desc'),
                    'recursive' => !empty($raw['recursive']),
                ];
            case 'cross_category':
                $groups = [];
                foreach ((array) ($raw['groups'] ?? []) as $g) {
                    if (!is_array($g)) continue;
                    $groups[] = [
                        'name'    => (string) ($g['name'] ?? ''),
                        'filter'  => [
                            'type'  => 'categories',
                            'value' => array_map('intval', (array) ($g['category_ids'] ?? [])),
                        ],
                        'min_qty' => isset($g['min_qty']) ? (int) $g['min_qty'] : 1,
                    ];
                }
                $reward = (array) ($raw['reward'] ?? []);
                return [
                    'groups' => $groups,
                    'reward' => [
                        'method' => (string) ($reward['method'] ?? 'percentage'),
                        'value'  => isset($reward['value']) ? (float) $reward['value'] : 0.0,
                    ],
                    'repeat' => !empty($raw['repeat']),
                ];
            case 'free_shipping':
                return [
                    'method' => (string) ($raw['method'] ?? ''),
                    'value'  => isset($raw['value']) ? (float) $raw['value'] : 0.0,
                ];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function validateConfig(string $type, array $config): void
    {
        switch ($type) {
            case 'simple':
            case 'cart':
                if (!in_array($config['method'] ?? '', $type === 'simple'
                        ? ['percentage', 'flat', 'fixed_price']
                        : ['percentage', 'flat_total', 'flat_per_item'], true)) {
                    throw new InvalidArgumentException(sprintf('Invalid %s method', $type));
                }
                if (($config['value'] ?? 0) <= 0) {
                    throw new InvalidArgumentException(sprintf('%s value must be > 0', $type));
                }
                return;
            case 'bulk':
                if (empty($config['ranges'])) {
                    throw new InvalidArgumentException('Bulk rule needs at least one range.');
                }
                foreach ($config['ranges'] as $i => $r) {
                    if (($r['from'] ?? 0) < 1) {
                        throw new InvalidArgumentException(sprintf('Bulk range #%d needs from ≥ 1', $i + 1));
                    }
                    if (($r['value'] ?? 0) <= 0) {
                        throw new InvalidArgumentException(sprintf('Bulk range #%d needs value > 0', $i + 1));
                    }
                }
                return;
            case 'set':
                if (($config['bundle_size'] ?? 0) < 2) {
                    throw new InvalidArgumentException('Set rule bundle_size must be ≥ 2.');
                }
                if (!in_array($config['method'] ?? '', ['set_price', 'set_percentage', 'set_flat_off'], true)) {
                    throw new InvalidArgumentException('Invalid set method');
                }
                if (($config['value'] ?? -1) < 0) {
                    throw new InvalidArgumentException('Set value must be ≥ 0');
                }
                return;
            case 'buy_x_get_y':
                if (($config['trigger']['qty'] ?? 0) < 1) {
                    throw new InvalidArgumentException('BuyXGetY trigger qty must be ≥ 1');
                }
                if (($config['reward']['qty'] ?? 0) < 1) {
                    throw new InvalidArgumentException('BuyXGetY reward qty must be ≥ 1');
                }
                return;
            case 'nth_item':
                if (empty($config['tiers'])) {
                    throw new InvalidArgumentException('Nth item rule needs at least one tier.');
                }
                return;
            case 'cross_category':
                if (count($config['groups'] ?? []) < 2) {
                    throw new InvalidArgumentException('Cross category needs ≥ 2 groups.');
                }
                return;
            case 'free_shipping':
                if (!in_array($config['method'] ?? '', ['remove_shipping', 'percentage_off_shipping'], true)) {
                    throw new InvalidArgumentException('Invalid free_shipping method');
                }
                return;
        }
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normaliseFilters(array $raw): array
    {
        $items = [];
        foreach ((array) ($raw['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $type = (string) ($item['type'] ?? '');
            if ($type === '') continue;
            $normalised = ['type' => $type];
            switch ($type) {
                case 'all_products':
                case 'on_sale':
                    break;
                case 'products':
                case 'categories':
                case 'tags':
                    $normalised['method'] = (string) ($item['method'] ?? 'in');
                    $normalised['ids'] = array_values(array_filter(
                        array_map('intval', (array) ($item['ids'] ?? [])),
                        static fn (int $id): bool => $id > 0
                    ));
                    if ($type === 'categories' && !empty($item['include_subcategories'])) {
                        $normalised['include_subcategories'] = true;
                    }
                    break;
                case 'attributes':
                    $normalised['method'] = (string) ($item['method'] ?? 'in');
                    $normalised['attribute'] = (string) ($item['attribute'] ?? '');
                    $values = (array) ($item['values'] ?? []);
                    $normalised['values'] = array_values(array_filter(
                        array_map('strval', $values),
                        static fn (string $v): bool => $v !== ''
                    ));
                    break;
                default:
                    continue 2;
            }
            $items[] = $normalised;
        }
        return $items === [] ? [] : ['items' => $items];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function normaliseConditions(array $raw): array
    {
        $items = [];
        foreach ((array) ($raw['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $type = (string) ($item['type'] ?? '');
            if ($type === '') continue;
            $normalised = ['type' => $type];
            switch ($type) {
                case 'cart_subtotal':
                case 'cart_quantity':
                case 'cart_line_items':
                case 'total_spent':
                    $normalised['operator'] = (string) ($item['operator'] ?? '>=');
                    $normalised['value'] = isset($item['value']) ? (float) $item['value'] : 0.0;
                    break;
                case 'user_role':
                    $normalised['roles'] = array_values(array_filter(
                        array_map('strval', (array) ($item['roles'] ?? [])),
                        static fn (string $r): bool => $r !== ''
                    ));
                    break;
                case 'user_logged_in':
                    $normalised['is_logged_in'] = !empty($item['is_logged_in']);
                    break;
                case 'payment_method':
                case 'shipping_method':
                    $normalised['methods'] = array_values(array_filter(
                        array_map('strval', (array) ($item['methods'] ?? [])),
                        static fn (string $m): bool => $m !== ''
                    ));
                    break;
                case 'date_range':
                    $normalised['from'] = (string) ($item['from'] ?? '');
                    $normalised['to'] = (string) ($item['to'] ?? '');
                    break;
                case 'day_of_week':
                    $normalised['days'] = array_values(array_filter(
                        array_map('intval', (array) ($item['days'] ?? [])),
                        static fn (int $d): bool => $d >= 1 && $d <= 7
                    ));
                    break;
                case 'time_of_day':
                    $normalised['from'] = (string) ($item['from'] ?? '');
                    $normalised['to'] = (string) ($item['to'] ?? '');
                    break;
                case 'first_order':
                    $normalised['is_first_order'] = !empty($item['is_first_order']);
                    break;
                case 'birthday_month':
                    $normalised['match_current_month'] = !empty($item['match_current_month']);
                    break;
                default:
                    continue 2;
            }
            $items[] = $normalised;
        }
        if ($items === []) {
            return [];
        }
        return [
            'logic' => (string) ($raw['logic'] ?? 'and') === 'or' ? 'or' : 'and',
            'items' => $items,
        ];
    }

    private static function isValidDateString(string $value): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        return $dt !== false && $dt->format('Y-m-d H:i:s') === $value;
    }
}
```

### 1b. Rewrite `tests/Unit/Admin/RuleFormMapperTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Admin\RuleFormMapper;
use PowerDiscount\Domain\RuleStatus;

final class RuleFormMapperTest extends TestCase
{
    public function testSimpleMinimal(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'Test',
            'type'  => 'simple',
            'config_by_type' => [
                'simple' => ['method' => 'percentage', 'value' => 10],
            ],
        ]);

        self::assertSame('Test', $rule->getTitle());
        self::assertSame('simple', $rule->getType());
        self::assertSame(RuleStatus::ENABLED, $rule->getStatus());
        self::assertSame(10, $rule->getPriority());
        self::assertFalse($rule->isExclusive());
        self::assertSame(['method' => 'percentage', 'value' => 10.0], $rule->getConfig());
        self::assertSame([], $rule->getFilters());
        self::assertSame([], $rule->getConditions());
        self::assertNull($rule->getNotes());
    }

    public function testIgnoresOtherTypesConfig(): void
    {
        // When type=simple, any config_by_type[bulk] data must be discarded.
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X',
            'type'  => 'simple',
            'config_by_type' => [
                'simple' => ['method' => 'percentage', 'value' => 5],
                'bulk'   => ['ranges' => [['from' => 1, 'method' => 'percentage', 'value' => 20]]],
            ],
        ]);
        self::assertSame(['method' => 'percentage', 'value' => 5.0], $rule->getConfig());
    }

    public function testBulkConfig(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'B', 'type' => 'bulk',
            'config_by_type' => [
                'bulk' => [
                    'count_scope' => 'cumulative',
                    'ranges' => [
                        ['from' => 1, 'to' => 4, 'method' => 'percentage', 'value' => 5],
                        ['from' => 5, 'to' => '', 'method' => 'percentage', 'value' => 10],
                    ],
                ],
            ],
        ]);
        $config = $rule->getConfig();
        self::assertSame('cumulative', $config['count_scope']);
        self::assertCount(2, $config['ranges']);
        self::assertSame(1, $config['ranges'][0]['from']);
        self::assertSame(4, $config['ranges'][0]['to']);
        self::assertNull($config['ranges'][1]['to']);
    }

    public function testSetConfig(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'S', 'type' => 'set',
            'config_by_type' => [
                'set' => ['bundle_size' => 2, 'method' => 'set_flat_off', 'value' => 100, 'repeat' => 1],
            ],
        ]);
        $c = $rule->getConfig();
        self::assertSame(2, $c['bundle_size']);
        self::assertSame('set_flat_off', $c['method']);
        self::assertTrue($c['repeat']);
    }

    public function testFiltersNormalised(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'simple',
            'config_by_type' => ['simple' => ['method' => 'percentage', 'value' => 10]],
            'filters' => [
                'items' => [
                    ['type' => 'categories', 'method' => 'in', 'ids' => [12, '13', 'abc']],
                    ['type' => 'all_products'],
                    ['type' => '', 'method' => 'in'], // dropped
                ],
            ],
        ]);
        $filters = $rule->getFilters();
        self::assertCount(2, $filters['items']);
        self::assertSame([12, 13], $filters['items'][0]['ids']);
        self::assertSame('all_products', $filters['items'][1]['type']);
    }

    public function testConditionsNormalised(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'simple',
            'config_by_type' => ['simple' => ['method' => 'percentage', 'value' => 10]],
            'conditions' => [
                'logic' => 'or',
                'items' => [
                    ['type' => 'cart_subtotal', 'operator' => '>=', 'value' => 500],
                    ['type' => 'user_role', 'roles' => ['customer', 'subscriber']],
                ],
            ],
        ]);
        $conditions = $rule->getConditions();
        self::assertSame('or', $conditions['logic']);
        self::assertCount(2, $conditions['items']);
        self::assertSame(500.0, $conditions['items'][0]['value']);
        self::assertSame(['customer', 'subscriber'], $conditions['items'][1]['roles']);
    }

    public function testRejectsMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title/i');
        RuleFormMapper::fromFormData([
            'title' => '', 'type' => 'simple',
            'config_by_type' => ['simple' => ['method' => 'percentage', 'value' => 10]],
        ]);
    }

    public function testRejectsInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type/i');
        RuleFormMapper::fromFormData(['title' => 'X', 'type' => 'bogus']);
    }

    public function testRejectsSimpleWithoutMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/method/i');
        RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'simple',
            'config_by_type' => ['simple' => ['value' => 10]],
        ]);
    }

    public function testRejectsSimpleWithZeroValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'simple',
            'config_by_type' => ['simple' => ['method' => 'percentage', 'value' => 0]],
        ]);
    }

    public function testRejectsBulkWithNoRanges(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/range/i');
        RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'bulk',
            'config_by_type' => ['bulk' => ['count_scope' => 'cumulative', 'ranges' => []]],
        ]);
    }

    public function testRejectsSetWithBundleSizeLessThanTwo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'set',
            'config_by_type' => ['set' => ['bundle_size' => 1, 'method' => 'set_price', 'value' => 50]],
        ]);
    }

    public function testRejectsCrossCategoryWithOneGroup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'cross_category',
            'config_by_type' => [
                'cross_category' => [
                    'groups' => [['name' => 'A', 'category_ids' => [1], 'min_qty' => 1]],
                    'reward' => ['method' => 'percentage', 'value' => 10],
                ],
            ],
        ]);
    }

    public function testRejectsBadDateFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/starts_at/i');
        RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'simple',
            'config_by_type' => ['simple' => ['method' => 'percentage', 'value' => 10]],
            'starts_at' => 'tomorrow',
        ]);
    }

    public function testAcceptsValidDateRangeAndUsageLimit(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'simple',
            'config_by_type' => ['simple' => ['method' => 'percentage', 'value' => 10]],
            'starts_at'   => '2026-04-15 00:00:00',
            'ends_at'     => '2026-04-30 23:59:59',
            'usage_limit' => '100',
        ]);
        self::assertSame('2026-04-15 00:00:00', $rule->getStartsAt());
        self::assertSame('2026-04-30 23:59:59', $rule->getEndsAt());
        self::assertSame(100, $rule->getUsageLimit());
    }

    public function testNotesIsAlwaysNull(): void
    {
        // notes is a dev-internal field; never accepted from POST.
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X', 'type' => 'simple',
            'config_by_type' => ['simple' => ['method' => 'percentage', 'value' => 10]],
            'notes' => 'should be ignored',
        ]);
        self::assertNull($rule->getNotes());
    }
}
```

Run tests → expect fail on many because old test methods reference deleted `testFromFormDataMinimal` etc. But we're **replacing** the file entirely, so delete old methods and keep only the new ones above.

Run → expect 16 passes (or however many the above defines).

Commit:
```bash
git add src/Admin/RuleFormMapper.php tests/Unit/Admin/RuleFormMapperTest.php
git commit -m "refactor(admin): RuleFormMapper accepts structured arrays; per-type validation"
```

Expected test count after task: baseline was 257. Old RuleFormMapperTest had 11 tests. New has 16. Net +5 → **262 tests**.

Actually: re-count old vs new. Old (including `testRejectsBadDateFormat` and `testAcceptsValidDateFormat` added in Phase 4b fix-up) had 13 tests. New has 16. Net +3 → **260 tests**. Either way, verify the actual count after running.

---

### Task 2: Rewrite rule-edit.php view

**File:** `src/Admin/views/rule-edit.php` (replace entirely)

```php
<?php
/**
 * @var \PowerDiscount\Domain\Rule $rule
 * @var bool $isNew
 * @var array<string, string> $strategyTypes
 */
if (!defined('ABSPATH')) {
    exit;
}

$pageTitle = $isNew ? __('Add Rule', 'power-discount') : __('Edit Rule', 'power-discount');
$listUrl = admin_url('admin.php?page=power-discount');
$currentType = $rule->getType() ?: 'simple';
$partialsDir = POWER_DISCOUNT_DIR . 'src/Admin/views/partials/';
?>
<div class="wrap pd-rule-editor">
    <h1 class="wp-heading-inline"><?php echo esc_html($pageTitle); ?></h1>
    <a href="<?php echo esc_url($listUrl); ?>" class="page-title-action">← <?php esc_html_e('Back to list', 'power-discount'); ?></a>
    <hr class="wp-header-end">

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="pd-rule-form">
        <input type="hidden" name="action" value="pd_save_rule">
        <input type="hidden" name="id" value="<?php echo (int) $rule->getId(); ?>">
        <?php wp_nonce_field('pd_save_rule_' . (int) $rule->getId()); ?>

        <div class="pd-section">
            <h2 class="pd-section-title"><?php esc_html_e('1. Basic details', 'power-discount'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="pd-title"><?php esc_html_e('Rule name', 'power-discount'); ?> <span class="pd-required">*</span></label></th>
                    <td><input type="text" id="pd-title" name="title" value="<?php echo esc_attr($rule->getTitle()); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label for="pd-type"><?php esc_html_e('Discount type', 'power-discount'); ?></label></th>
                    <td>
                        <select id="pd-type" name="type">
                            <?php foreach ($strategyTypes as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>"<?php selected($currentType, $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="pd-status"><?php esc_html_e('Status', 'power-discount'); ?></label></th>
                    <td>
                        <select id="pd-status" name="status">
                            <option value="1"<?php selected($rule->getStatus(), 1); ?>><?php esc_html_e('Enabled', 'power-discount'); ?></option>
                            <option value="0"<?php selected($rule->getStatus(), 0); ?>><?php esc_html_e('Disabled', 'power-discount'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="pd-priority"><?php esc_html_e('Priority', 'power-discount'); ?></label></th>
                    <td>
                        <input type="number" id="pd-priority" name="priority" value="<?php echo (int) $rule->getPriority(); ?>" min="0" class="small-text">
                        <p class="description"><?php esc_html_e('Lower number = higher priority.', 'power-discount'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Exclusive', 'power-discount'); ?></label></th>
                    <td>
                        <label><input type="checkbox" name="exclusive" value="1"<?php checked($rule->isExclusive(), true); ?>> <?php esc_html_e('Stop after this rule matches', 'power-discount'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Schedule', 'power-discount'); ?></th>
                    <td>
                        <input type="text" name="starts_at" value="<?php echo esc_attr((string) $rule->getStartsAt()); ?>" placeholder="YYYY-MM-DD HH:MM:SS" class="regular-text">
                        <?php esc_html_e('to', 'power-discount'); ?>
                        <input type="text" name="ends_at" value="<?php echo esc_attr((string) $rule->getEndsAt()); ?>" placeholder="YYYY-MM-DD HH:MM:SS" class="regular-text">
                        <p class="description"><?php esc_html_e('Leave blank for no schedule limit.', 'power-discount'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="pd-usage"><?php esc_html_e('Usage limit', 'power-discount'); ?></label></th>
                    <td>
                        <input type="number" id="pd-usage" name="usage_limit" value="<?php echo $rule->getUsageLimit() === null ? '' : (int) $rule->getUsageLimit(); ?>" class="small-text" min="0">
                        <span class="description"><?php printf(esc_html__('Used: %d', 'power-discount'), (int) $rule->getUsedCount()); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><label for="pd-label"><?php esc_html_e('Cart label', 'power-discount'); ?></label></th>
                    <td><input type="text" id="pd-label" name="label" value="<?php echo esc_attr((string) $rule->getLabel()); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('Shown to customers in the cart when this rule applies.', 'power-discount'); ?></p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="pd-section">
            <h2 class="pd-section-title"><?php esc_html_e('2. Discount settings', 'power-discount'); ?></h2>
            <div id="pd-strategy-sections">
                <?php foreach (array_keys($strategyTypes) as $type): ?>
                    <div class="pd-strategy-section" data-type="<?php echo esc_attr($type); ?>"<?php echo $type === $currentType ? '' : ' style="display:none"'; ?>>
                        <?php
                        $config = $type === $currentType ? $rule->getConfig() : [];
                        $partial = $partialsDir . 'strategy-' . $type . '.php';
                        if (file_exists($partial)) {
                            include $partial;
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="pd-section">
            <h2 class="pd-section-title"><?php esc_html_e('3. Product filters', 'power-discount'); ?></h2>
            <p class="description"><?php esc_html_e('Which products in the cart should this rule apply to? Leave empty to apply to all products.', 'power-discount'); ?></p>
            <?php
            $filters = $rule->getFilters();
            $filterItems = is_array($filters['items'] ?? null) ? $filters['items'] : [];
            include $partialsDir . 'filter-builder.php';
            ?>
        </div>

        <div class="pd-section">
            <h2 class="pd-section-title"><?php esc_html_e('4. Conditions', 'power-discount'); ?></h2>
            <p class="description"><?php esc_html_e('When should this rule apply? Leave empty to apply always.', 'power-discount'); ?></p>
            <?php
            $conditions = $rule->getConditions();
            $conditionLogic = (string) ($conditions['logic'] ?? 'and');
            $conditionItems = is_array($conditions['items'] ?? null) ? $conditions['items'] : [];
            include $partialsDir . 'condition-builder.php';
            ?>
        </div>

        <?php submit_button($isNew ? __('Create rule', 'power-discount') : __('Save rule', 'power-discount')); ?>
    </form>
</div>
```

Also update `src/Admin/RuleEditPage.php::render()` — remove `toFormData` call and instead pass `$rule` and `$strategyTypes` directly. The variable contract changes from `$formData` to `$rule`. Replace the existing method body:

```php
    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $rule = $id > 0 ? $this->rules->findById($id) : null;

        if ($rule === null) {
            $rule = new Rule([
                'title'    => '',
                'type'     => 'simple',
                'status'   => 1,
                'priority' => 10,
                'config'   => ['method' => 'percentage', 'value' => 10],
            ]);
        }

        $isNew = $rule->getId() === 0;
        $strategyTypes = [
            'simple'         => __('Simple — percentage / flat / fixed price', 'power-discount'),
            'bulk'           => __('Bulk — quantity tier discount', 'power-discount'),
            'cart'           => __('Cart — whole cart discount', 'power-discount'),
            'set'            => __('Set — 任選 N 件 (bundle)', 'power-discount'),
            'buy_x_get_y'    => __('Buy X Get Y', 'power-discount'),
            'nth_item'       => __('Nth item — 第 N 件 X 折', 'power-discount'),
            'cross_category' => __('Cross-category — 紅配綠', 'power-discount'),
            'free_shipping'  => __('Free Shipping', 'power-discount'),
        ];

        require POWER_DISCOUNT_DIR . 'src/Admin/views/rule-edit.php';
    }
```

Commit:
```bash
git add src/Admin/views/rule-edit.php src/Admin/RuleEditPage.php
git commit -m "feat(admin): rewrite rule-edit view with section layout and strategy partials"
```

---

### Task 3: Strategy config partials (8 files)

Create `src/Admin/views/partials/strategy-simple.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$method = (string) ($config['method'] ?? 'percentage');
$value = $config['value'] ?? '';
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Discount method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[simple][method]" value="percentage"<?php checked($method, 'percentage'); ?>> <?php esc_html_e('Percentage off', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[simple][method]" value="flat"<?php checked($method, 'flat'); ?>> <?php esc_html_e('Flat amount off (per item)', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[simple][method]" value="fixed_price"<?php checked($method, 'fixed_price'); ?>> <?php esc_html_e('Fixed price (each item becomes this price)', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label for="pd-simple-value"><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td>
            <input type="number" id="pd-simple-value" name="config_by_type[simple][value]" value="<?php echo esc_attr((string) $value); ?>" step="0.01" min="0" class="small-text">
            <span class="description"><?php esc_html_e('% for percentage, NT$ for flat/fixed price', 'power-discount'); ?></span>
        </td>
    </tr>
</table>
```

Create `src/Admin/views/partials/strategy-bulk.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$countScope = (string) ($config['count_scope'] ?? 'cumulative');
$ranges = $config['ranges'] ?? [];
if (empty($ranges)) {
    $ranges = [['from' => 1, 'to' => null, 'method' => 'percentage', 'value' => 0]];
}
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Count scope', 'power-discount'); ?></label></th>
        <td>
            <select name="config_by_type[bulk][count_scope]">
                <option value="cumulative"<?php selected($countScope, 'cumulative'); ?>><?php esc_html_e('Cumulative — sum qty across all matched items', 'power-discount'); ?></option>
                <option value="per_item"<?php selected($countScope, 'per_item'); ?>><?php esc_html_e('Per item — each line counts on its own', 'power-discount'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><?php esc_html_e('Quantity tiers', 'power-discount'); ?></th>
        <td>
            <div class="pd-repeater" data-pd-repeater="bulk-range" data-name-prefix="config_by_type[bulk][ranges]">
                <?php foreach ($ranges as $i => $r): ?>
                    <div class="pd-repeater-row">
                        <label><?php esc_html_e('From', 'power-discount'); ?> <input type="number" name="config_by_type[bulk][ranges][<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr((string) ($r['from'] ?? '1')); ?>" class="small-text" min="1"></label>
                        <label><?php esc_html_e('to', 'power-discount'); ?> <input type="number" name="config_by_type[bulk][ranges][<?php echo (int) $i; ?>][to]" value="<?php echo esc_attr($r['to'] !== null ? (string) $r['to'] : ''); ?>" class="small-text" min="1" placeholder="∞"></label>
                        <select name="config_by_type[bulk][ranges][<?php echo (int) $i; ?>][method]">
                            <option value="percentage"<?php selected($r['method'] ?? 'percentage', 'percentage'); ?>>%</option>
                            <option value="flat"<?php selected($r['method'] ?? '', 'flat'); ?>>Flat NT$</option>
                        </select>
                        <input type="number" step="0.01" min="0" name="config_by_type[bulk][ranges][<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr((string) ($r['value'] ?? '')); ?>" class="small-text">
                        <button type="button" class="button button-small pd-repeater-remove">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template class="pd-repeater-template">
                <div class="pd-repeater-row">
                    <label><?php esc_html_e('From', 'power-discount'); ?> <input type="number" name="config_by_type[bulk][ranges][__INDEX__][from]" value="1" class="small-text" min="1"></label>
                    <label><?php esc_html_e('to', 'power-discount'); ?> <input type="number" name="config_by_type[bulk][ranges][__INDEX__][to]" value="" class="small-text" min="1" placeholder="∞"></label>
                    <select name="config_by_type[bulk][ranges][__INDEX__][method]">
                        <option value="percentage">%</option>
                        <option value="flat">Flat NT$</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="config_by_type[bulk][ranges][__INDEX__][value]" value="" class="small-text">
                    <button type="button" class="button button-small pd-repeater-remove">×</button>
                </div>
            </template>
            <p><button type="button" class="button pd-repeater-add" data-pd-add="bulk-range">+ <?php esc_html_e('Add tier', 'power-discount'); ?></button></p>
        </td>
    </tr>
</table>
```

Create `src/Admin/views/partials/strategy-cart.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$method = (string) ($config['method'] ?? 'percentage');
$value = $config['value'] ?? '';
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[cart][method]" value="percentage"<?php checked($method, 'percentage'); ?>> <?php esc_html_e('Percentage off whole cart', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[cart][method]" value="flat_total"<?php checked($method, 'flat_total'); ?>> <?php esc_html_e('Fixed amount off cart total', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[cart][method]" value="flat_per_item"<?php checked($method, 'flat_per_item'); ?>> <?php esc_html_e('Fixed amount off per item', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td>
            <input type="number" step="0.01" min="0" name="config_by_type[cart][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text">
        </td>
    </tr>
</table>
```

Create `src/Admin/views/partials/strategy-set.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$bundleSize = (int) ($config['bundle_size'] ?? 2);
$method = (string) ($config['method'] ?? 'set_price');
$value = $config['value'] ?? '';
$repeat = !empty($config['repeat']);
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Bundle size (N items)', 'power-discount'); ?></label></th>
        <td><input type="number" name="config_by_type[set][bundle_size]" value="<?php echo esc_attr((string) $bundleSize); ?>" min="2" class="small-text"></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Set method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[set][method]" value="set_price"<?php checked($method, 'set_price'); ?>> <?php esc_html_e('Set price — N items for NT$X', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[set][method]" value="set_percentage"<?php checked($method, 'set_percentage'); ?>> <?php esc_html_e('Set percentage — N items for X% off', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[set][method]" value="set_flat_off"<?php checked($method, 'set_flat_off'); ?>> <?php esc_html_e('Set flat off — N items for flat NT$X off (Taiwan exclusive)', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Value', 'power-discount'); ?></label></th>
        <td><input type="number" step="0.01" min="0" name="config_by_type[set][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text"></td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Repeat', 'power-discount'); ?></label></th>
        <td><label><input type="checkbox" name="config_by_type[set][repeat]" value="1"<?php checked($repeat, true); ?>> <?php esc_html_e('Apply multiple bundles if customer has enough items', 'power-discount'); ?></label></td>
    </tr>
</table>
```

Create `src/Admin/views/partials/strategy-buy_x_get_y.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$trigger = (array) ($config['trigger'] ?? []);
$reward = (array) ($config['reward'] ?? []);
$triggerSource = (string) ($trigger['source'] ?? 'filter');
$triggerQty = (int) ($trigger['qty'] ?? 1);
$rewardTarget = (string) ($reward['target'] ?? 'same');
$rewardQty = (int) ($reward['qty'] ?? 1);
$rewardMethod = (string) ($reward['method'] ?? 'free');
$rewardValue = $reward['value'] ?? 0;
$recursive = !empty($config['recursive']);
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Trigger — buy this many', 'power-discount'); ?></label></th>
        <td>
            <input type="number" name="config_by_type[buy_x_get_y][trigger][qty]" value="<?php echo (int) $triggerQty; ?>" min="1" class="small-text">
            <select name="config_by_type[buy_x_get_y][trigger][source]">
                <option value="filter"<?php selected($triggerSource, 'filter'); ?>><?php esc_html_e('Any filter-matching item', 'power-discount'); ?></option>
                <option value="specific"<?php selected($triggerSource, 'specific'); ?>><?php esc_html_e('Specific products (set via filter below)', 'power-discount'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Reward — get this many', 'power-discount'); ?></label></th>
        <td>
            <input type="number" name="config_by_type[buy_x_get_y][reward][qty]" value="<?php echo (int) $rewardQty; ?>" min="1" class="small-text">
            <select name="config_by_type[buy_x_get_y][reward][target]">
                <option value="same"<?php selected($rewardTarget, 'same'); ?>><?php esc_html_e('Of the same triggering product', 'power-discount'); ?></option>
                <option value="cheapest_in_cart"<?php selected($rewardTarget, 'cheapest_in_cart'); ?>><?php esc_html_e('Cheapest item in cart', 'power-discount'); ?></option>
                <option value="specific"<?php selected($rewardTarget, 'specific'); ?>><?php esc_html_e('Specific products (enter IDs)', 'power-discount'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Reward discount', 'power-discount'); ?></label></th>
        <td>
            <select name="config_by_type[buy_x_get_y][reward][method]">
                <option value="free"<?php selected($rewardMethod, 'free'); ?>><?php esc_html_e('Free', 'power-discount'); ?></option>
                <option value="percentage"<?php selected($rewardMethod, 'percentage'); ?>><?php esc_html_e('Percentage off', 'power-discount'); ?></option>
                <option value="flat"<?php selected($rewardMethod, 'flat'); ?>><?php esc_html_e('Flat amount off', 'power-discount'); ?></option>
            </select>
            <input type="number" step="0.01" min="0" name="config_by_type[buy_x_get_y][reward][value]" value="<?php echo esc_attr((string) $rewardValue); ?>" class="small-text">
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Recursive', 'power-discount'); ?></label></th>
        <td><label><input type="checkbox" name="config_by_type[buy_x_get_y][recursive]" value="1"<?php checked($recursive, true); ?>> <?php esc_html_e('Apply the rule repeatedly while the cart allows it', 'power-discount'); ?></label></td>
    </tr>
</table>
```

Create `src/Admin/views/partials/strategy-nth_item.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$tiers = $config['tiers'] ?? [];
if (empty($tiers)) {
    $tiers = [
        ['nth' => 1, 'method' => 'percentage', 'value' => 0],
        ['nth' => 2, 'method' => 'percentage', 'value' => 40],
    ];
}
$sortBy = (string) ($config['sort_by'] ?? 'price_desc');
$recursive = !empty($config['recursive']);
?>
<table class="form-table">
    <tr>
        <th><?php esc_html_e('Per-position discount', 'power-discount'); ?></th>
        <td>
            <div class="pd-repeater" data-pd-repeater="nth-tier">
                <?php foreach ($tiers as $i => $t): ?>
                    <div class="pd-repeater-row">
                        <label><?php esc_html_e('Nth item:', 'power-discount'); ?>
                            <input type="number" name="config_by_type[nth_item][tiers][<?php echo (int) $i; ?>][nth]" value="<?php echo esc_attr((string) ($t['nth'] ?? '')); ?>" class="small-text" min="1">
                        </label>
                        <select name="config_by_type[nth_item][tiers][<?php echo (int) $i; ?>][method]">
                            <option value="percentage"<?php selected($t['method'] ?? 'percentage', 'percentage'); ?>>%</option>
                            <option value="flat"<?php selected($t['method'] ?? '', 'flat'); ?>>Flat NT$</option>
                            <option value="free"<?php selected($t['method'] ?? '', 'free'); ?>>Free</option>
                        </select>
                        <input type="number" step="0.01" min="0" name="config_by_type[nth_item][tiers][<?php echo (int) $i; ?>][value]" value="<?php echo esc_attr((string) ($t['value'] ?? '')); ?>" class="small-text">
                        <button type="button" class="button button-small pd-repeater-remove">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template class="pd-repeater-template">
                <div class="pd-repeater-row">
                    <label><?php esc_html_e('Nth item:', 'power-discount'); ?>
                        <input type="number" name="config_by_type[nth_item][tiers][__INDEX__][nth]" value="1" class="small-text" min="1">
                    </label>
                    <select name="config_by_type[nth_item][tiers][__INDEX__][method]">
                        <option value="percentage">%</option>
                        <option value="flat">Flat NT$</option>
                        <option value="free">Free</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="config_by_type[nth_item][tiers][__INDEX__][value]" value="" class="small-text">
                    <button type="button" class="button button-small pd-repeater-remove">×</button>
                </div>
            </template>
            <p><button type="button" class="button pd-repeater-add" data-pd-add="nth-tier">+ <?php esc_html_e('Add tier', 'power-discount'); ?></button></p>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Sort items by', 'power-discount'); ?></label></th>
        <td>
            <select name="config_by_type[nth_item][sort_by]">
                <option value="price_desc"<?php selected($sortBy, 'price_desc'); ?>><?php esc_html_e('Price (high → low)', 'power-discount'); ?></option>
                <option value="price_asc"<?php selected($sortBy, 'price_asc'); ?>><?php esc_html_e('Price (low → high)', 'power-discount'); ?></option>
            </select>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Recursive', 'power-discount'); ?></label></th>
        <td><label><input type="checkbox" name="config_by_type[nth_item][recursive]" value="1"<?php checked($recursive, true); ?>> <?php esc_html_e('Cycle tiers every K items', 'power-discount'); ?></label></td>
    </tr>
</table>
```

Create `src/Admin/views/partials/strategy-cross_category.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$groups = $config['groups'] ?? [];
if (empty($groups)) {
    $groups = [
        ['name' => '', 'category_ids' => [], 'min_qty' => 1],
        ['name' => '', 'category_ids' => [], 'min_qty' => 1],
    ];
}
$reward = (array) ($config['reward'] ?? []);
$rewardMethod = (string) ($reward['method'] ?? 'percentage');
$rewardValue = $reward['value'] ?? '';
$repeat = !empty($config['repeat']);
// Normalise existing groups that stored category ids under filter.value
foreach ($groups as $i => $g) {
    if (!isset($g['category_ids']) && isset($g['filter']['value'])) {
        $groups[$i]['category_ids'] = (array) $g['filter']['value'];
    }
}
?>
<table class="form-table">
    <tr>
        <th><?php esc_html_e('Groups (all must be satisfied)', 'power-discount'); ?></th>
        <td>
            <div class="pd-repeater" data-pd-repeater="xcat-group">
                <?php foreach ($groups as $i => $g):
                    $catIds = (array) ($g['category_ids'] ?? []);
                ?>
                    <div class="pd-repeater-row pd-group-row">
                        <label><?php esc_html_e('Group name', 'power-discount'); ?>
                            <input type="text" name="config_by_type[cross_category][groups][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr((string) ($g['name'] ?? '')); ?>" class="regular-text">
                        </label>
                        <br>
                        <label><?php esc_html_e('Categories', 'power-discount'); ?>
                            <select name="config_by_type[cross_category][groups][<?php echo (int) $i; ?>][category_ids][]" multiple class="pd-category-select" data-placeholder="<?php esc_attr_e('Select categories', 'power-discount'); ?>" style="min-width:300px;">
                                <?php foreach ($catIds as $cid): $cid = (int) $cid; $term = get_term($cid, 'product_cat'); if ($term && !is_wp_error($term)): ?>
                                    <option value="<?php echo $cid; ?>" selected><?php echo esc_html($term->name); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </label>
                        <label><?php esc_html_e('Min qty', 'power-discount'); ?>
                            <input type="number" name="config_by_type[cross_category][groups][<?php echo (int) $i; ?>][min_qty]" value="<?php echo (int) ($g['min_qty'] ?? 1); ?>" min="1" class="small-text">
                        </label>
                        <button type="button" class="button button-small pd-repeater-remove">×</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button pd-repeater-add" data-pd-add="xcat-group">+ <?php esc_html_e('Add group', 'power-discount'); ?></button></p>
            <p class="description"><?php esc_html_e('Need at least 2 groups.', 'power-discount'); ?></p>
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Reward', 'power-discount'); ?></label></th>
        <td>
            <select name="config_by_type[cross_category][reward][method]">
                <option value="percentage"<?php selected($rewardMethod, 'percentage'); ?>><?php esc_html_e('Percentage off bundle', 'power-discount'); ?></option>
                <option value="flat"<?php selected($rewardMethod, 'flat'); ?>><?php esc_html_e('Flat amount off bundle', 'power-discount'); ?></option>
                <option value="fixed_bundle_price"<?php selected($rewardMethod, 'fixed_bundle_price'); ?>><?php esc_html_e('Fixed bundle price', 'power-discount'); ?></option>
            </select>
            <input type="number" step="0.01" min="0" name="config_by_type[cross_category][reward][value]" value="<?php echo esc_attr((string) $rewardValue); ?>" class="small-text">
        </td>
    </tr>
    <tr>
        <th><label><?php esc_html_e('Repeat', 'power-discount'); ?></label></th>
        <td><label><input type="checkbox" name="config_by_type[cross_category][repeat]" value="1"<?php checked($repeat, true); ?>> <?php esc_html_e('Form multiple bundles when possible', 'power-discount'); ?></label></td>
    </tr>
</table>
```

> The xcat-group template for JS cloning will be a simpler version without pre-selected options; the repeater JS renders a fresh empty row when user clicks Add group. For simplicity in Phase 4d the template is embedded directly in the JS add handler.

Create `src/Admin/views/partials/strategy-free_shipping.php`:

```php
<?php
/** @var array<string, mixed> $config */
if (!defined('ABSPATH')) exit;
$method = (string) ($config['method'] ?? 'remove_shipping');
$value = $config['value'] ?? '';
?>
<table class="form-table">
    <tr>
        <th><label><?php esc_html_e('Method', 'power-discount'); ?></label></th>
        <td>
            <label><input type="radio" name="config_by_type[free_shipping][method]" value="remove_shipping"<?php checked($method, 'remove_shipping'); ?>> <?php esc_html_e('Remove shipping entirely', 'power-discount'); ?></label><br>
            <label><input type="radio" name="config_by_type[free_shipping][method]" value="percentage_off_shipping"<?php checked($method, 'percentage_off_shipping'); ?>> <?php esc_html_e('Percentage off shipping cost', 'power-discount'); ?></label>
        </td>
    </tr>
    <tr class="pd-fs-value">
        <th><label><?php esc_html_e('Percentage off (1–100)', 'power-discount'); ?></label></th>
        <td><input type="number" min="1" max="100" name="config_by_type[free_shipping][value]" value="<?php echo esc_attr((string) $value); ?>" class="small-text"></td>
    </tr>
</table>
```

Commit after all 8 are in:
```bash
git add src/Admin/views/partials/
git commit -m "feat(admin): add 8 strategy-specific config partials"
```

---

### Task 4: Filter builder partial

**File:** `src/Admin/views/partials/filter-builder.php`

```php
<?php
/** @var array<int, array<string, mixed>> $filterItems */
if (!defined('ABSPATH')) exit;
?>
<div class="pd-repeater" data-pd-repeater="filter-row">
    <?php foreach ($filterItems as $i => $item):
        $type = (string) ($item['type'] ?? 'all_products');
        $method = (string) ($item['method'] ?? 'in');
        $ids = (array) ($item['ids'] ?? []);
    ?>
        <div class="pd-repeater-row pd-filter-row">
            <select name="filters[items][<?php echo (int) $i; ?>][type]" class="pd-filter-type">
                <option value="all_products"<?php selected($type, 'all_products'); ?>><?php esc_html_e('All products', 'power-discount'); ?></option>
                <option value="products"<?php selected($type, 'products'); ?>><?php esc_html_e('Specific products', 'power-discount'); ?></option>
                <option value="categories"<?php selected($type, 'categories'); ?>><?php esc_html_e('Categories', 'power-discount'); ?></option>
                <option value="tags"<?php selected($type, 'tags'); ?>><?php esc_html_e('Tags', 'power-discount'); ?></option>
                <option value="attributes"<?php selected($type, 'attributes'); ?>><?php esc_html_e('Attributes', 'power-discount'); ?></option>
                <option value="on_sale"<?php selected($type, 'on_sale'); ?>><?php esc_html_e('On sale', 'power-discount'); ?></option>
            </select>

            <select name="filters[items][<?php echo (int) $i; ?>][method]" class="pd-filter-method">
                <option value="in"<?php selected($method, 'in'); ?>><?php esc_html_e('in list', 'power-discount'); ?></option>
                <option value="not_in"<?php selected($method, 'not_in'); ?>><?php esc_html_e('not in list', 'power-discount'); ?></option>
            </select>

            <span class="pd-filter-value pd-filter-value-products"<?php echo $type === 'products' ? '' : ' style="display:none"'; ?>>
                <select name="filters[items][<?php echo (int) $i; ?>][ids][]" class="wc-product-search" multiple data-placeholder="<?php esc_attr_e('Search products', 'power-discount'); ?>" data-action="woocommerce_json_search_products_and_variations" style="min-width:300px;">
                    <?php if ($type === 'products') foreach ($ids as $pid): $pid = (int) $pid; $prod = function_exists('wc_get_product') ? wc_get_product($pid) : null; if ($prod): ?>
                        <option value="<?php echo $pid; ?>" selected><?php echo esc_html($prod->get_formatted_name()); ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </span>

            <span class="pd-filter-value pd-filter-value-categories"<?php echo $type === 'categories' ? '' : ' style="display:none"'; ?>>
                <select name="filters[items][<?php echo (int) $i; ?>][ids][]" class="pd-category-select" multiple data-placeholder="<?php esc_attr_e('Select categories', 'power-discount'); ?>" style="min-width:300px;">
                    <?php if ($type === 'categories') foreach ($ids as $cid): $cid = (int) $cid; $term = get_term($cid, 'product_cat'); if ($term && !is_wp_error($term)): ?>
                        <option value="<?php echo $cid; ?>" selected><?php echo esc_html($term->name); ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </span>

            <span class="pd-filter-value pd-filter-value-tags"<?php echo $type === 'tags' ? '' : ' style="display:none"'; ?>>
                <select name="filters[items][<?php echo (int) $i; ?>][ids][]" class="pd-tag-select" multiple data-placeholder="<?php esc_attr_e('Select tags', 'power-discount'); ?>" style="min-width:300px;">
                    <?php if ($type === 'tags') foreach ($ids as $tid): $tid = (int) $tid; $term = get_term($tid, 'product_tag'); if ($term && !is_wp_error($term)): ?>
                        <option value="<?php echo $tid; ?>" selected><?php echo esc_html($term->name); ?></option>
                    <?php endif; endforeach; ?>
                </select>
            </span>

            <button type="button" class="button button-small pd-repeater-remove">×</button>
        </div>
    <?php endforeach; ?>
</div>
<p><button type="button" class="button pd-repeater-add" data-pd-add="filter-row">+ <?php esc_html_e('Add filter', 'power-discount'); ?></button></p>
```

---

### Task 5: Condition builder partial

**File:** `src/Admin/views/partials/condition-builder.php`

```php
<?php
/**
 * @var string $conditionLogic
 * @var array<int, array<string, mixed>> $conditionItems
 */
if (!defined('ABSPATH')) exit;
$types = [
    'cart_subtotal'    => __('Cart subtotal', 'power-discount'),
    'cart_quantity'    => __('Cart total quantity', 'power-discount'),
    'cart_line_items'  => __('Number of line items', 'power-discount'),
    'total_spent'      => __('Customer total spent (lifetime)', 'power-discount'),
    'user_role'        => __('User role', 'power-discount'),
    'user_logged_in'   => __('User logged in', 'power-discount'),
    'payment_method'   => __('Payment method', 'power-discount'),
    'shipping_method'  => __('Shipping method', 'power-discount'),
    'date_range'       => __('Date range', 'power-discount'),
    'day_of_week'      => __('Day of week', 'power-discount'),
    'time_of_day'      => __('Time of day', 'power-discount'),
    'first_order'      => __('First order', 'power-discount'),
    'birthday_month'   => __('Birthday month', 'power-discount'),
];
$render_row = function (int $i, array $item) use ($types) {
    $t = (string) ($item['type'] ?? 'cart_subtotal');
    ?>
    <div class="pd-repeater-row pd-condition-row">
        <select name="conditions[items][<?php echo $i; ?>][type]" class="pd-condition-type">
            <?php foreach ($types as $v => $l): ?>
                <option value="<?php echo esc_attr($v); ?>"<?php selected($t, $v); ?>><?php echo esc_html($l); ?></option>
            <?php endforeach; ?>
        </select>

        <!-- value pattern: operator + number (cart_subtotal/qty/line_items/total_spent) -->
        <span class="pd-cond-fields" data-for="cart_subtotal,cart_quantity,cart_line_items,total_spent"<?php echo in_array($t, ['cart_subtotal','cart_quantity','cart_line_items','total_spent'], true) ? '' : ' style="display:none"'; ?>>
            <select name="conditions[items][<?php echo $i; ?>][operator]">
                <?php foreach (['>=','>','=','<=','<','!='] as $op):
                    $current = (string) ($item['operator'] ?? '>=');
                ?>
                    <option value="<?php echo esc_attr($op); ?>"<?php selected($current, $op); ?>><?php echo esc_html($op); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" step="0.01" name="conditions[items][<?php echo $i; ?>][value]" value="<?php echo esc_attr((string) ($item['value'] ?? '')); ?>" class="small-text">
        </span>

        <!-- user_role -->
        <span class="pd-cond-fields" data-for="user_role"<?php echo $t === 'user_role' ? '' : ' style="display:none"'; ?>>
            <input type="text" name="conditions[items][<?php echo $i; ?>][roles_csv]" value="<?php echo esc_attr(implode(',', (array) ($item['roles'] ?? []))); ?>" class="regular-text" placeholder="customer, subscriber">
            <span class="description"><?php esc_html_e('Comma-separated role slugs', 'power-discount'); ?></span>
        </span>

        <!-- user_logged_in -->
        <span class="pd-cond-fields" data-for="user_logged_in"<?php echo $t === 'user_logged_in' ? '' : ' style="display:none"'; ?>>
            <label><input type="checkbox" name="conditions[items][<?php echo $i; ?>][is_logged_in]" value="1"<?php checked(!empty($item['is_logged_in'])); ?>> <?php esc_html_e('Require logged in', 'power-discount'); ?></label>
        </span>

        <!-- payment_method / shipping_method -->
        <span class="pd-cond-fields" data-for="payment_method,shipping_method"<?php echo in_array($t, ['payment_method','shipping_method'], true) ? '' : ' style="display:none"'; ?>>
            <input type="text" name="conditions[items][<?php echo $i; ?>][methods_csv]" value="<?php echo esc_attr(implode(',', (array) ($item['methods'] ?? []))); ?>" class="regular-text" placeholder="e.g. cod, bacs, stripe">
            <span class="description"><?php esc_html_e('Comma-separated method slugs', 'power-discount'); ?></span>
        </span>

        <!-- date_range -->
        <span class="pd-cond-fields" data-for="date_range"<?php echo $t === 'date_range' ? '' : ' style="display:none"'; ?>>
            <input type="text" name="conditions[items][<?php echo $i; ?>][from]" value="<?php echo esc_attr((string) ($item['from'] ?? '')); ?>" placeholder="YYYY-MM-DD HH:MM:SS">
            →
            <input type="text" name="conditions[items][<?php echo $i; ?>][to]" value="<?php echo esc_attr((string) ($item['to'] ?? '')); ?>" placeholder="YYYY-MM-DD HH:MM:SS">
        </span>

        <!-- day_of_week -->
        <span class="pd-cond-fields" data-for="day_of_week"<?php echo $t === 'day_of_week' ? '' : ' style="display:none"'; ?>>
            <?php
            $dayLabels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
            $selected = array_map('intval', (array) ($item['days'] ?? []));
            foreach ($dayLabels as $dv => $dl): ?>
                <label><input type="checkbox" name="conditions[items][<?php echo $i; ?>][days][]" value="<?php echo $dv; ?>"<?php checked(in_array($dv, $selected, true)); ?>> <?php echo $dl; ?></label>
            <?php endforeach; ?>
        </span>

        <!-- time_of_day -->
        <span class="pd-cond-fields" data-for="time_of_day"<?php echo $t === 'time_of_day' ? '' : ' style="display:none"'; ?>>
            <input type="text" name="conditions[items][<?php echo $i; ?>][from]" value="<?php echo esc_attr((string) ($item['from'] ?? '')); ?>" placeholder="HH:MM" style="width:70px;">
            →
            <input type="text" name="conditions[items][<?php echo $i; ?>][to]" value="<?php echo esc_attr((string) ($item['to'] ?? '')); ?>" placeholder="HH:MM" style="width:70px;">
        </span>

        <!-- first_order -->
        <span class="pd-cond-fields" data-for="first_order"<?php echo $t === 'first_order' ? '' : ' style="display:none"'; ?>>
            <label><input type="checkbox" name="conditions[items][<?php echo $i; ?>][is_first_order]" value="1"<?php checked(!empty($item['is_first_order'])); ?>> <?php esc_html_e('Customer first order only', 'power-discount'); ?></label>
        </span>

        <!-- birthday_month -->
        <span class="pd-cond-fields" data-for="birthday_month"<?php echo $t === 'birthday_month' ? '' : ' style="display:none"'; ?>>
            <label><input type="checkbox" name="conditions[items][<?php echo $i; ?>][match_current_month]" value="1"<?php checked(!empty($item['match_current_month'])); ?>> <?php esc_html_e('Match current month', 'power-discount'); ?></label>
        </span>

        <button type="button" class="button button-small pd-repeater-remove">×</button>
    </div>
    <?php
};
?>
<p>
    <label><?php esc_html_e('Logic', 'power-discount'); ?>
        <select name="conditions[logic]">
            <option value="and"<?php selected($conditionLogic, 'and'); ?>><?php esc_html_e('AND (all)', 'power-discount'); ?></option>
            <option value="or"<?php selected($conditionLogic, 'or'); ?>><?php esc_html_e('OR (any)', 'power-discount'); ?></option>
        </select>
    </label>
</p>
<div class="pd-repeater" data-pd-repeater="condition-row">
    <?php foreach ($conditionItems as $i => $item) { $render_row((int) $i, (array) $item); } ?>
</div>
<p><button type="button" class="button pd-repeater-add" data-pd-add="condition-row">+ <?php esc_html_e('Add condition', 'power-discount'); ?></button></p>
```

> Note: the `roles_csv` / `methods_csv` form fields are flattened to arrays by a small JS on-submit handler that splits them into `roles[]` / `methods[]` arrays (so PHP sees them as lists). Alternatively, `RuleFormMapper::normaliseConditions` can accept the csv form. Simpler: update `normaliseConditions` to handle `roles_csv` and `methods_csv` too. Let me add that in Task 1.

**Revision to Task 1**: in `normaliseConditions`, also handle `roles_csv` and `methods_csv`:

```php
case 'user_role':
    if (isset($item['roles_csv'])) {
        $normalised['roles'] = array_values(array_filter(
            array_map('trim', explode(',', (string) $item['roles_csv'])),
            static fn (string $r): bool => $r !== ''
        ));
    } else {
        $normalised['roles'] = array_values(array_filter(
            array_map('strval', (array) ($item['roles'] ?? [])),
            static fn (string $r): bool => $r !== ''
        ));
    }
    break;
case 'payment_method':
case 'shipping_method':
    if (isset($item['methods_csv'])) {
        $normalised['methods'] = array_values(array_filter(
            array_map('trim', explode(',', (string) $item['methods_csv'])),
            static fn (string $m): bool => $m !== ''
        ));
    } else {
        $normalised['methods'] = array_values(array_filter(
            array_map('strval', (array) ($item['methods'] ?? [])),
            static fn (string $m): bool => $m !== ''
        ));
    }
    break;
```

Include this change in the Task 1 file rewrite.

Commit Task 4+5 together:
```bash
git add src/Admin/views/partials/filter-builder.php src/Admin/views/partials/condition-builder.php
git commit -m "feat(admin): add filter-builder and condition-builder partials"
```

---

### Task 6: admin.js repeater + type-swap + condition field toggler + admin.css

**File:** Replace `assets/admin/admin.js`

```javascript
(function ($) {
    'use strict';

    // --- Status toggle (existing) ---
    $(document).on('click', '.pd-toggle-status', function (e) {
        e.preventDefault();
        var $link = $(this);
        if ($link.hasClass('pd-disabled')) {
            return;
        }
        var id = $link.data('id');
        var nonce = $link.data('nonce');
        if (!id) {
            return;
        }
        $link.addClass('pd-disabled').css('opacity', 0.5);
        $.post(PowerDiscountAdmin.ajaxUrl, {
            action: 'pd_toggle_rule_status',
            id: id,
            nonce: nonce
        }).done(function () {
            window.location.reload();
        }).fail(function (xhr) {
            $link.removeClass('pd-disabled').css('opacity', 1);
            var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Toggle failed';
            window.alert(msg);
        });
    });

    // --- Strategy type swap (show matching section, hide others) ---
    $(document).on('change', '#pd-type', function () {
        var selected = $(this).val();
        $('.pd-strategy-section').each(function () {
            $(this).toggle($(this).data('type') === selected);
        });
    });

    // --- Condition type field toggler ---
    $(document).on('change', '.pd-condition-type', function () {
        var type = $(this).val();
        var $row = $(this).closest('.pd-condition-row');
        $row.find('.pd-cond-fields').each(function () {
            var types = ($(this).data('for') || '').toString().split(',');
            $(this).toggle(types.indexOf(type) !== -1);
        });
    });

    // --- Filter type field toggler ---
    $(document).on('change', '.pd-filter-type', function () {
        var type = $(this).val();
        var $row = $(this).closest('.pd-filter-row');
        $row.find('.pd-filter-value').hide();
        $row.find('.pd-filter-value-' + type).show();
        // method is irrelevant for all_products / on_sale
        if (type === 'all_products' || type === 'on_sale') {
            $row.find('.pd-filter-method').hide();
        } else {
            $row.find('.pd-filter-method').show();
        }
    });

    // --- Generic repeater (add/remove rows) ---
    // Uses <template class="pd-repeater-template"> INSIDE the target container for row cloning,
    // OR an ad-hoc build function for special types.
    function nextIndex($container) {
        var max = -1;
        $container.find('.pd-repeater-row').each(function (idx) {
            max = Math.max(max, idx);
        });
        return max + 1;
    }

    function reindexNames($container) {
        $container.find('.pd-repeater-row').each(function (newIdx) {
            $(this).find('[name]').each(function () {
                var name = $(this).attr('name');
                // Replace the first [N] occurrence with [newIdx]
                name = name.replace(/\[(\d+)\]/, '[' + newIdx + ']');
                $(this).attr('name', name);
            });
        });
    }

    function addFilterRow($container) {
        var idx = nextIndex($container);
        var html = ''
            + '<div class="pd-repeater-row pd-filter-row">'
            + '<select name="filters[items][' + idx + '][type]" class="pd-filter-type">'
            + '<option value="all_products">All products</option>'
            + '<option value="products">Specific products</option>'
            + '<option value="categories">Categories</option>'
            + '<option value="tags">Tags</option>'
            + '<option value="attributes">Attributes</option>'
            + '<option value="on_sale">On sale</option>'
            + '</select>'
            + '<select name="filters[items][' + idx + '][method]" class="pd-filter-method" style="display:none">'
            + '<option value="in">in list</option>'
            + '<option value="not_in">not in list</option>'
            + '</select>'
            + '<span class="pd-filter-value pd-filter-value-products" style="display:none">'
            + '<select name="filters[items][' + idx + '][ids][]" class="wc-product-search" multiple data-placeholder="Search products" data-action="woocommerce_json_search_products_and_variations" style="min-width:300px;"></select>'
            + '</span>'
            + '<span class="pd-filter-value pd-filter-value-categories" style="display:none">'
            + '<select name="filters[items][' + idx + '][ids][]" class="pd-category-select" multiple data-placeholder="Select categories" style="min-width:300px;"></select>'
            + '</span>'
            + '<span class="pd-filter-value pd-filter-value-tags" style="display:none">'
            + '<select name="filters[items][' + idx + '][ids][]" class="pd-tag-select" multiple data-placeholder="Select tags" style="min-width:300px;"></select>'
            + '</span>'
            + '<button type="button" class="button button-small pd-repeater-remove">×</button>'
            + '</div>';
        $container.append(html);
        initEnhancedSelects($container);
    }

    function addConditionRow($container) {
        var idx = nextIndex($container);
        var html = ''
            + '<div class="pd-repeater-row pd-condition-row">'
            + '<select name="conditions[items][' + idx + '][type]" class="pd-condition-type">'
            + '<option value="cart_subtotal">Cart subtotal</option>'
            + '<option value="cart_quantity">Cart total quantity</option>'
            + '<option value="cart_line_items">Number of line items</option>'
            + '<option value="total_spent">Customer total spent (lifetime)</option>'
            + '<option value="user_role">User role</option>'
            + '<option value="user_logged_in">User logged in</option>'
            + '<option value="payment_method">Payment method</option>'
            + '<option value="shipping_method">Shipping method</option>'
            + '<option value="date_range">Date range</option>'
            + '<option value="day_of_week">Day of week</option>'
            + '<option value="time_of_day">Time of day</option>'
            + '<option value="first_order">First order</option>'
            + '<option value="birthday_month">Birthday month</option>'
            + '</select>'
            + '<span class="pd-cond-fields" data-for="cart_subtotal,cart_quantity,cart_line_items,total_spent">'
            + '<select name="conditions[items][' + idx + '][operator]">'
            + '<option value=">=">&ge;</option><option value=">">&gt;</option><option value="=">=</option><option value="<=">&le;</option><option value="<">&lt;</option><option value="!=">&ne;</option>'
            + '</select>'
            + '<input type="number" step="0.01" name="conditions[items][' + idx + '][value]" class="small-text">'
            + '</span>'
            + '<span class="pd-cond-fields" data-for="user_role" style="display:none">'
            + '<input type="text" name="conditions[items][' + idx + '][roles_csv]" class="regular-text" placeholder="customer, subscriber">'
            + '</span>'
            + '<span class="pd-cond-fields" data-for="user_logged_in" style="display:none">'
            + '<label><input type="checkbox" name="conditions[items][' + idx + '][is_logged_in]" value="1"> Require logged in</label>'
            + '</span>'
            + '<span class="pd-cond-fields" data-for="payment_method,shipping_method" style="display:none">'
            + '<input type="text" name="conditions[items][' + idx + '][methods_csv]" class="regular-text" placeholder="cod, bacs, stripe">'
            + '</span>'
            + '<span class="pd-cond-fields" data-for="date_range" style="display:none">'
            + '<input type="text" name="conditions[items][' + idx + '][from]" placeholder="YYYY-MM-DD HH:MM:SS">&nbsp;→&nbsp;'
            + '<input type="text" name="conditions[items][' + idx + '][to]" placeholder="YYYY-MM-DD HH:MM:SS">'
            + '</span>'
            + '<span class="pd-cond-fields" data-for="day_of_week" style="display:none">'
            + ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].map(function(l, k){
                return '<label><input type="checkbox" name="conditions[items][' + idx + '][days][]" value="' + (k+1) + '"> ' + l + '</label>';
            }).join(' ')
            + '</span>'
            + '<span class="pd-cond-fields" data-for="time_of_day" style="display:none">'
            + '<input type="text" name="conditions[items][' + idx + '][from]" placeholder="HH:MM" style="width:70px;">&nbsp;→&nbsp;'
            + '<input type="text" name="conditions[items][' + idx + '][to]" placeholder="HH:MM" style="width:70px;">'
            + '</span>'
            + '<span class="pd-cond-fields" data-for="first_order" style="display:none">'
            + '<label><input type="checkbox" name="conditions[items][' + idx + '][is_first_order]" value="1"> First order only</label>'
            + '</span>'
            + '<span class="pd-cond-fields" data-for="birthday_month" style="display:none">'
            + '<label><input type="checkbox" name="conditions[items][' + idx + '][match_current_month]" value="1"> Match current month</label>'
            + '</span>'
            + '<button type="button" class="button button-small pd-repeater-remove">×</button>'
            + '</div>';
        $container.append(html);
    }

    function addTemplateRow($container) {
        var $tpl = $container.find('.pd-repeater-template').first();
        if (!$tpl.length) {
            return;
        }
        var idx = nextIndex($container);
        var html = $tpl.html().replace(/__INDEX__/g, idx);
        $container.append(html);
    }

    function addXCatGroupRow($container) {
        var idx = nextIndex($container);
        var html = ''
            + '<div class="pd-repeater-row pd-group-row">'
            + '<label>Group name <input type="text" name="config_by_type[cross_category][groups][' + idx + '][name]" class="regular-text"></label><br>'
            + '<label>Categories <select name="config_by_type[cross_category][groups][' + idx + '][category_ids][]" class="pd-category-select" multiple style="min-width:300px;" data-placeholder="Select categories"></select></label>'
            + '<label>Min qty <input type="number" name="config_by_type[cross_category][groups][' + idx + '][min_qty]" value="1" min="1" class="small-text"></label>'
            + '<button type="button" class="button button-small pd-repeater-remove">×</button>'
            + '</div>';
        $container.append(html);
        initEnhancedSelects($container);
    }

    $(document).on('click', '.pd-repeater-add', function () {
        var kind = $(this).data('pd-add');
        var $container = $(this).closest('.pd-section, td, div').find('.pd-repeater[data-pd-repeater="' + kind + '"]').first();
        if (!$container.length) {
            return;
        }
        if (kind === 'filter-row') {
            addFilterRow($container);
        } else if (kind === 'condition-row') {
            addConditionRow($container);
        } else if (kind === 'xcat-group') {
            addXCatGroupRow($container);
        } else {
            addTemplateRow($container);
        }
    });

    $(document).on('click', '.pd-repeater-remove', function () {
        var $row = $(this).closest('.pd-repeater-row');
        var $container = $row.closest('.pd-repeater');
        $row.remove();
        reindexNames($container);
    });

    // --- Enhanced selects (WC categories/tags/products) ---
    function initEnhancedSelects($scope) {
        if (typeof $.fn.selectWoo === 'undefined' && typeof $.fn.select2 === 'undefined') {
            return;
        }
        $scope = $scope || $(document);
        // Categories
        $scope.find('.pd-category-select:not(.enhanced)').each(function () {
            var $sel = $(this);
            $sel.addClass('enhanced');
            $sel.selectWoo({
                placeholder: $sel.data('placeholder') || 'Select',
                minimumInputLength: 0,
                ajax: {
                    url: PowerDiscountAdmin.ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { action: 'pd_search_terms', taxonomy: 'product_cat', q: params.term, nonce: PowerDiscountAdmin.nonce };
                    },
                    processResults: function (data) {
                        return { results: data.data || [] };
                    }
                }
            });
        });
        // Tags
        $scope.find('.pd-tag-select:not(.enhanced)').each(function () {
            var $sel = $(this);
            $sel.addClass('enhanced');
            $sel.selectWoo({
                placeholder: $sel.data('placeholder') || 'Select',
                minimumInputLength: 0,
                ajax: {
                    url: PowerDiscountAdmin.ajaxUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return { action: 'pd_search_terms', taxonomy: 'product_tag', q: params.term, nonce: PowerDiscountAdmin.nonce };
                    },
                    processResults: function (data) {
                        return { results: data.data || [] };
                    }
                }
            });
        });
        // Products (use WC's own handler)
        if (typeof $.fn.selectWoo !== 'undefined') {
            $scope.find('.wc-product-search:not(.enhanced)').each(function () {
                $(this).addClass('enhanced');
                // Let WC's own init pick it up if available
                if (typeof wc_enhanced_select_params !== 'undefined' && $(this).data('action')) {
                    $(this).selectWoo({
                        minimumInputLength: 2,
                        ajax: {
                            url: PowerDiscountAdmin.ajaxUrl,
                            dataType: 'json',
                            delay: 250,
                            data: function (params) {
                                return { action: $(this).data('action'), term: params.term, security: wc_enhanced_select_params.search_products_nonce };
                            }.bind(this),
                            processResults: function (data) {
                                var results = [];
                                $.each(data, function (id, text) { results.push({ id: id, text: text }); });
                                return { results: results };
                            }
                        }
                    });
                }
            });
        }
    }

    $(function () {
        initEnhancedSelects();
    });

})(jQuery);
```

**File:** `assets/admin/admin.css` (new)

```css
.pd-rule-editor .pd-section { background:#fff; border:1px solid #c3c4c7; padding:16px 20px; margin:16px 0; border-radius:4px; }
.pd-rule-editor .pd-section-title { margin-top:0; padding-bottom:8px; border-bottom:1px solid #eee; }
.pd-rule-editor .pd-required { color:#dc3232; }
.pd-rule-editor .pd-repeater-row { display:flex; align-items:center; gap:8px; margin:6px 0; flex-wrap:wrap; }
.pd-rule-editor .pd-repeater-row > * { margin-right:4px; }
.pd-rule-editor .pd-group-row { background:#f9f9f9; padding:10px; border:1px solid #e5e5e5; border-radius:4px; display:block; }
.pd-rule-editor .pd-group-row label { display:inline-block; margin-right:12px; }
.pd-rule-editor .pd-cond-fields { display:inline-flex; align-items:center; gap:4px; }
.pd-rule-editor .form-table th { width:180px; }
```

Also need to add a tiny AJAX endpoint `pd_search_terms` for the category/tag search. Add to `src/Admin/AjaxController.php`:

```php
    public function register(): void
    {
        add_action('wp_ajax_pd_toggle_rule_status', [$this, 'toggleStatus']);
        add_action('wp_ajax_pd_search_terms', [$this, 'searchTerms']);
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
```

Also modify `src/Admin/AdminMenu::enqueueAssets` to load `wc-enhanced-select`, select2 styles, admin.css, and tighten the hook-suffix filter:

```php
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
```

Commit:
```bash
git add assets/admin/admin.js assets/admin/admin.css src/Admin/AjaxController.php src/Admin/AdminMenu.php
git commit -m "feat(admin): repeater JS, type toggles, WC enhanced selects, term search ajax"
```

---

### Task 7: seed.php cleanup + purge existing notes

**Files:** `dev/seed.php`

In the rule insert loop, change:
```php
        'notes'       => 'Seeded by dev/seed.php',
```
to:
```php
        'notes'       => null,
```

And add a cleanup at the top of the rule section to clear previously-seeded notes:

After `// --- 4. Discount rules ---` add:
```php
// Clear stale "Seeded by" notes from previous seed runs
$wpdb->query("UPDATE {$rules_table} SET notes = NULL WHERE notes = 'Seeded by dev/seed.php'");
```

Commit:
```bash
git add dev/seed.php
git commit -m "chore(seed): drop notes annotation, clean legacy values"
```

---

### Task 8: Run seed to purge existing notes in the dev DB (operational)

Run the updated seed on the live dev container to clean:
```bash
docker exec power-discount-dev-cli wp --url=http://localhost:3303 eval-file /var/www/html/wp-content/plugins/power-discount/dev/seed.php
```

This is an operational step, not a code change, not a commit.

---

### Task 9: README bump

Update `README.md` Status section:

```markdown
## Status

**Phase 4d (GUI Rule Builder)** — complete. **MVP polish.**

Rule editor now provides:
- Strategy-specific config forms for all 8 types (no more raw JSON)
- Filter row builder with WC enhanced-select for products / categories / tags
- Condition row builder with 13 condition types and type-specific fields
- AND / OR condition logic toggle
- Schedule, usage limit, priority, exclusive mode
- Cart label for customer-facing messaging
- No internal notes field (was a dev-only field)

All previous features remain: 8 strategies, 13 conditions, 6 filters, ShippingHooks, Reports, frontend shipping bar, price table shortcode.

Pending (post-MVP polish): live discount preview, drag-sort priority, CSV export on reports.
```

Commit:
```bash
git add README.md
git commit -m "docs: README status bump to Phase 4d GUI rule builder"
```

---

## Phase 4d Exit Criteria

- ✅ `vendor/bin/phpunit` all green, ≥260 tests
- ✅ `php -l` clean across all files
- ✅ Rule editor has no JSON textarea
- ✅ Rule editor has no Internal notes field
- ✅ Seeded rules no longer show "Seeded by dev/seed.php"
- ✅ Strategy type select swaps the visible config form
- ✅ Filter builder can add/remove rows and switch types
- ✅ Condition builder can add/remove rows and switch types
- ✅ Category/tag enhanced selects work via `pd_search_terms` AJAX
- ✅ All 8 strategies editable through GUI (no raw JSON needed)

## Known Gaps (post-MVP)

- Attribute filter UI uses text inputs, not a proper attribute taxonomy picker
- Products picker relies on WC's built-in `woocommerce_json_search_products_and_variations` action
- BuyXGetY `specific` product IDs still use a numeric ID input (no search)
- No visual validation feedback before submit (validation errors surface as notices after save)
