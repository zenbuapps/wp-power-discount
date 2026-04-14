# Power Discount — Phase 4b: PHP Admin UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 讓使用者能在 WP 後台用網頁介面建立、編輯、啟用/停用、刪除折扣規則，而不必再去 SQL 改資料庫。Phase 4b 用純 PHP form + WP_List_Table 完成（沒有 React），編輯頁的 strategy config / filters / conditions 用 JSON textarea 接受輸入並在儲存時驗證。

**Architecture:** 沿用 Phase 2 的 `RuleRepository`。新增 `Admin/` 命名空間：`AdminMenu`（註冊選單）、`RuleFormMapper`（pure helper，POST → Rule、Rule → form data，可單元測試）、`RulesListTable`（WP_List_Table 子類）、`RulesListPage`（controller，列表/啟停/刪除/複製）、`RuleEditPage`（controller，新增/編輯表單 GET+POST + nonce）。Form submit 走 `admin-post.php`，`AjaxController` 處理 status toggle 與 delete 的 AJAX 請求。

**Tech Stack:** PHP 7.4+、PHPUnit 9.6、jQuery（toggle 切換用，WP 內建）

**Phase 定位:**
- Phase 1 ✅ Foundation
- Phase 2 ✅ Repository + Engine
- Phase 3 ✅ Taiwan Strategies
- Phase 4a ✅ Conditions + Filters + ShippingHooks
- **Phase 4b（本文）** PHP Admin UI（List + Edit）
- Phase 4c Frontend + Reports
- Phase 4d React rule builder（替換 Phase 4b 編輯頁，optional）

---

## Scope

**包含**：
- 後台選單 `WooCommerce → Power Discount`
- 規則列表頁（WP_List_Table）：欄位 = 標題 / 類型 / 狀態 / 優先序 / 期間 / 已使用次數 / 操作
- 操作：新增、編輯、複製、刪除、啟用/停用切換（AJAX）
- 編輯頁（PHP form）：基本欄位 + strategy config (JSON textarea) + filters (JSON textarea) + conditions (JSON textarea)
- 儲存時 JSON 驗證、nonce check、capability check
- Notice 系統（成功/失敗訊息）

**刻意不做（YAGNI）**：
- 視覺化 strategy form（每個 strategy 一個 GUI）— Phase 4d React
- Drag-sort priority — Phase 4d
- Live preview — Phase 4d
- Bulk actions on the list — Phase 4d
- 設定頁 — Phase 4c

---

## File Structure

新增：

```
src/Admin/
├── AdminMenu.php             # 註冊 menu + 路由
├── RuleFormMapper.php        # POST array <-> Rule + JSON validation (pure, testable)
├── RulesListTable.php        # WP_List_Table 子類
├── RulesListPage.php         # 列表頁 controller
├── RuleEditPage.php          # 編輯頁 controller
├── AjaxController.php        # toggle/delete AJAX endpoints
├── Notices.php               # admin notice queue (transient-based)
└── views/
    ├── rule-list.php         # 列表頁 view template
    └── rule-edit.php         # 編輯頁 view template

assets/admin/
└── admin.js                  # status toggle / delete confirm（極小檔，~50 行）

tests/Unit/Admin/
└── RuleFormMapperTest.php
```

修改：
- `src/Plugin.php` — 在 `boot()` 註冊 `AdminMenu` 與 `AjaxController`
- `power-discount.php` 不需要動

---

## Key Design: RuleFormMapper

唯一可單元測試的部分。其他類別都依賴 WP API，靠手動驗證。

`RuleFormMapper` 提供：

```php
class RuleFormMapper {
    /**
     * Build a Rule from a sanitized $_POST array.
     * Throws InvalidArgumentException on validation failure with field-level errors.
     */
    public static function fromFormData(array $post): Rule;

    /**
     * Convert a Rule back to form-friendly data for editing.
     */
    public static function toFormData(Rule $rule): array;
}
```

JSON fields (`config`, `filters`, `conditions`) are stored as strings in form input and parsed via `json_decode` with error guards. `RuleFormMapper` is the single point where POST → domain conversion happens.

---

## Ground Rules

- `<?php declare(strict_types=1);`
- PHP 7.4 相容
- TDD for `RuleFormMapper`
- 其他類別只 `php -l` 通過即可（手動驗證）
- Per-task commits with Conventional Commits messages
- `git -c user.email=luke@local -c user.name=Luke commit -m "..."`

---

## Tasks

### Task 1: RuleFormMapper (TDD)

**Files:**
- Create: `tests/Unit/Admin/RuleFormMapperTest.php`
- Create: `src/Admin/RuleFormMapper.php`

`RuleFormMapper` validates and constructs `Rule` from form input. Form fields:

| Field name | Type | Notes |
|---|---|---|
| `id` | int | 0 for new |
| `title` | string | required, max 255 |
| `type` | string | required, one of the 8 strategy types |
| `status` | int | 0 or 1 |
| `priority` | int | default 10 |
| `exclusive` | int | 0 or 1 |
| `starts_at` | string | optional `Y-m-d H:i:s` or empty |
| `ends_at` | string | optional |
| `usage_limit` | string | optional, empty = unlimited |
| `label` | string | optional |
| `notes` | string | optional |
| `config_json` | string | required JSON, empty = `{}` |
| `filters_json` | string | optional JSON, empty = `{}` |
| `conditions_json` | string | optional JSON, empty = `{}` |

#### Step 1: Test file

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Admin\RuleFormMapper;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Domain\RuleStatus;

final class RuleFormMapperTest extends TestCase
{
    public function testFromFormDataMinimal(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'Test',
            'type' => 'simple',
            'config_json' => '{"method":"percentage","value":10}',
        ]);

        self::assertSame('Test', $rule->getTitle());
        self::assertSame('simple', $rule->getType());
        self::assertSame(RuleStatus::ENABLED, $rule->getStatus());
        self::assertSame(10, $rule->getPriority());
        self::assertFalse($rule->isExclusive());
        self::assertSame(['method' => 'percentage', 'value' => 10], $rule->getConfig());
        self::assertSame([], $rule->getFilters());
        self::assertSame([], $rule->getConditions());
    }

    public function testFromFormDataFull(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'id' => '42',
            'title' => 'Full',
            'type' => 'bulk',
            'status' => '0',
            'priority' => '5',
            'exclusive' => '1',
            'starts_at' => '2026-04-01 00:00:00',
            'ends_at' => '2026-04-30 23:59:59',
            'usage_limit' => '100',
            'label' => 'Big sale',
            'notes' => 'Internal note',
            'config_json' => '{"count_scope":"cumulative","ranges":[]}',
            'filters_json' => '{"items":[]}',
            'conditions_json' => '{"logic":"and","items":[]}',
        ]);

        self::assertSame(42, $rule->getId());
        self::assertSame('Full', $rule->getTitle());
        self::assertSame('bulk', $rule->getType());
        self::assertSame(RuleStatus::DISABLED, $rule->getStatus());
        self::assertSame(5, $rule->getPriority());
        self::assertTrue($rule->isExclusive());
        self::assertSame('2026-04-01 00:00:00', $rule->getStartsAt());
        self::assertSame('2026-04-30 23:59:59', $rule->getEndsAt());
        self::assertSame(100, $rule->getUsageLimit());
        self::assertSame('Big sale', $rule->getLabel());
        self::assertSame('Internal note', $rule->getNotes());
    }

    public function testRejectsMissingTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/title/i');
        RuleFormMapper::fromFormData([
            'title' => '',
            'type' => 'simple',
            'config_json' => '{}',
        ]);
    }

    public function testRejectsInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/type/i');
        RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'nonsense',
            'config_json' => '{}',
        ]);
    }

    public function testRejectsInvalidConfigJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/config/i');
        RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '{not json',
        ]);
    }

    public function testEmptyDateFieldsAcceptedAsNull(): void
    {
        $rule = RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '{}',
            'starts_at' => '',
            'ends_at' => '',
            'usage_limit' => '',
        ]);
        self::assertNull($rule->getStartsAt());
        self::assertNull($rule->getEndsAt());
        self::assertNull($rule->getUsageLimit());
    }

    public function testToFormDataRoundTrip(): void
    {
        $original = RuleFormMapper::fromFormData([
            'title' => 'Round',
            'type' => 'cart',
            'priority' => '15',
            'config_json' => '{"method":"percentage","value":10}',
            'conditions_json' => '{"logic":"and","items":[{"type":"cart_subtotal","operator":">=","value":500}]}',
        ]);

        $formData = RuleFormMapper::toFormData($original);

        self::assertSame('Round', $formData['title']);
        self::assertSame('cart', $formData['type']);
        self::assertSame(15, $formData['priority']);

        // config_json should pretty-print or at least round-trip via decode
        $configBack = json_decode($formData['config_json'], true);
        self::assertSame(['method' => 'percentage', 'value' => 10], $configBack);

        $conditionsBack = json_decode($formData['conditions_json'], true);
        self::assertSame('and', $conditionsBack['logic']);
    }

    public function testAcceptsAllValidStrategyTypes(): void
    {
        $types = ['simple', 'bulk', 'cart', 'set', 'buy_x_get_y', 'nth_item', 'cross_category', 'free_shipping'];
        foreach ($types as $type) {
            $rule = RuleFormMapper::fromFormData([
                'title' => 't',
                'type' => $type,
                'config_json' => '{}',
            ]);
            self::assertSame($type, $rule->getType());
        }
    }

    public function testNonObjectJsonRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RuleFormMapper::fromFormData([
            'title' => 'X',
            'type' => 'simple',
            'config_json' => '"a string"',
        ]);
    }
}
```

#### Step 2: Run test → expect fail (class not found)

`vendor/bin/phpunit tests/Unit/Admin/RuleFormMapperTest.php`

#### Step 3: Implement `src/Admin/RuleFormMapper.php`

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

        $config = self::decodeJsonField($post['config_json'] ?? '', 'config');
        $filters = self::decodeJsonField($post['filters_json'] ?? '', 'filters');
        $conditions = self::decodeJsonField($post['conditions_json'] ?? '', 'conditions');

        $usageLimitRaw = trim((string) ($post['usage_limit'] ?? ''));
        $usageLimit = $usageLimitRaw === '' ? null : (int) $usageLimitRaw;

        $startsAt = trim((string) ($post['starts_at'] ?? ''));
        $endsAt = trim((string) ($post['ends_at'] ?? ''));

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
            'used_count'  => (int) ($post['used_count'] ?? 0),
            'config'      => $config,
            'filters'     => $filters,
            'conditions'  => $conditions,
            'label'       => isset($post['label']) ? (string) $post['label'] : null,
            'notes'       => isset($post['notes']) ? (string) $post['notes'] : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toFormData(Rule $rule): array
    {
        return [
            'id'              => $rule->getId(),
            'title'           => $rule->getTitle(),
            'type'            => $rule->getType(),
            'status'          => $rule->getStatus(),
            'priority'        => $rule->getPriority(),
            'exclusive'       => $rule->isExclusive() ? 1 : 0,
            'starts_at'       => $rule->getStartsAt() ?? '',
            'ends_at'         => $rule->getEndsAt() ?? '',
            'usage_limit'    => $rule->getUsageLimit() === null ? '' : (string) $rule->getUsageLimit(),
            'used_count'      => $rule->getUsedCount(),
            'label'           => $rule->getLabel() ?? '',
            'notes'           => $rule->getNotes() ?? '',
            'config_json'     => self::encodePretty($rule->getConfig()),
            'filters_json'    => self::encodePretty($rule->getFilters()),
            'conditions_json' => self::encodePretty($rule->getConditions()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonField($value, string $field): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('Invalid JSON in %s field.', $field));
        }
        return $decoded;
    }

    private static function encodePretty(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json === false ? '{}' : $json;
    }
}
```

#### Step 4: Re-run → expect 9 passes.

#### Step 5: Commit

```bash
git add src/Admin/RuleFormMapper.php tests/Unit/Admin/RuleFormMapperTest.php
git commit -m "feat(admin): add RuleFormMapper with TDD coverage"
```

After Task 1: total tests = 229 + 9 = **238**.

---

### Task 2: AdminMenu + Notices

**Files:**
- Create: `src/Admin/AdminMenu.php`
- Create: `src/Admin/Notices.php`

`AdminMenu` registers a single submenu under WooCommerce. `Notices` provides a transient-based admin notice queue (set in one request, displayed in next).

#### Step 1: `src/Admin/Notices.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

final class Notices
{
    private const TRANSIENT_PREFIX = 'power_discount_notice_';

    public static function add(string $message, string $type = 'success'): void
    {
        if (!function_exists('set_transient') || !function_exists('get_current_user_id')) {
            return;
        }
        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $existing = get_transient($key);
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing[] = ['message' => $message, 'type' => $type];
        set_transient($key, $existing, 60);
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'renderNotices']);
    }

    public function renderNotices(): void
    {
        if (!function_exists('get_transient') || !function_exists('get_current_user_id')) {
            return;
        }
        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $notices = get_transient($key);
        if (!is_array($notices) || $notices === []) {
            return;
        }
        delete_transient($key);

        foreach ($notices as $notice) {
            $type = in_array($notice['type'] ?? 'success', ['success', 'error', 'warning', 'info'], true)
                ? $notice['type']
                : 'info';
            $message = (string) ($notice['message'] ?? '');
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        }
    }
}
```

#### Step 2: `src/Admin/AdminMenu.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Repository\RuleRepository;

final class AdminMenu
{
    private RuleRepository $rules;
    private RulesListPage $listPage;
    private RuleEditPage $editPage;

    public function __construct(RuleRepository $rules, RulesListPage $listPage, RuleEditPage $editPage)
    {
        $this->rules = $rules;
        $this->listPage = $listPage;
        $this->editPage = $editPage;
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
        wp_enqueue_script(
            'power-discount-admin',
            POWER_DISCOUNT_URL . 'assets/admin/admin.js',
            ['jquery'],
            POWER_DISCOUNT_VERSION,
            true
        );
        wp_localize_script('power-discount-admin', 'PowerDiscountAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('power_discount_admin'),
        ]);
    }
}
```

#### Step 3: `php -l` both files.

#### Step 4: Commit

```bash
git add src/Admin/AdminMenu.php src/Admin/Notices.php
git commit -m "feat(admin): add AdminMenu router and Notices queue"
```

---

### Task 3: RulesListTable + RulesListPage

**Files:**
- Create: `src/Admin/RulesListTable.php`
- Create: `src/Admin/RulesListPage.php`
- Create: `src/Admin/views/rule-list.php`

#### Step 1: `src/Admin/RulesListTable.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Domain\Rule;
use PowerDiscount\Repository\RuleRepository;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class RulesListTable extends \WP_List_Table
{
    private RuleRepository $rules;

    public function __construct(RuleRepository $rules)
    {
        parent::__construct([
            'singular' => 'rule',
            'plural'   => 'rules',
            'ajax'     => false,
        ]);
        $this->rules = $rules;
    }

    public function get_columns(): array
    {
        return [
            'title'      => __('Title', 'power-discount'),
            'type'       => __('Type', 'power-discount'),
            'status'     => __('Status', 'power-discount'),
            'priority'   => __('Priority', 'power-discount'),
            'schedule'   => __('Schedule', 'power-discount'),
            'used_count' => __('Used', 'power-discount'),
        ];
    }

    public function prepare_items(): void
    {
        $this->_column_headers = [$this->get_columns(), [], []];
        // Phase 4b: show ALL rules (no pagination, status filter), sorted by priority.
        $rules = $this->rules->getActiveRules(); // active only first
        $allRows = [];
        foreach ($rules as $rule) {
            $allRows[] = $this->ruleToRow($rule);
        }
        $this->items = $allRows;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleToRow(Rule $rule): array
    {
        return [
            'id'         => $rule->getId(),
            'title'      => $rule->getTitle(),
            'type'       => $rule->getType(),
            'status'     => $rule->getStatus(),
            'priority'   => $rule->getPriority(),
            'starts_at'  => $rule->getStartsAt(),
            'ends_at'    => $rule->getEndsAt(),
            'used_count' => $rule->getUsedCount(),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    public function column_title($item): string
    {
        $editUrl = add_query_arg([
            'page'   => 'power-discount',
            'action' => 'edit',
            'id'     => (int) $item['id'],
        ], admin_url('admin.php'));

        $deleteUrl = wp_nonce_url(add_query_arg([
            'action' => 'pd_delete_rule',
            'id'     => (int) $item['id'],
        ], admin_url('admin-post.php')), 'pd_delete_rule_' . $item['id']);

        $duplicateUrl = wp_nonce_url(add_query_arg([
            'action' => 'pd_duplicate_rule',
            'id'     => (int) $item['id'],
        ], admin_url('admin-post.php')), 'pd_duplicate_rule_' . $item['id']);

        $title = sprintf(
            '<strong><a href="%s">%s</a></strong>',
            esc_url($editUrl),
            esc_html((string) $item['title'])
        );
        $actions = sprintf(
            '<div class="row-actions"><span class="edit"><a href="%s">%s</a> | </span><span class="duplicate"><a href="%s">%s</a> | </span><span class="delete"><a href="%s" onclick="return confirm(\'%s\')">%s</a></span></div>',
            esc_url($editUrl),
            esc_html__('Edit', 'power-discount'),
            esc_url($duplicateUrl),
            esc_html__('Duplicate', 'power-discount'),
            esc_url($deleteUrl),
            esc_js(__('Delete this rule?', 'power-discount')),
            esc_html__('Delete', 'power-discount')
        );
        return $title . $actions;
    }

    /** @param array<string, mixed> $item */
    public function column_type($item): string
    {
        return esc_html((string) $item['type']);
    }

    /** @param array<string, mixed> $item */
    public function column_status($item): string
    {
        $enabled = (int) $item['status'] === 1;
        $label = $enabled
            ? '<span style="color:#46b450">' . esc_html__('Enabled', 'power-discount') . '</span>'
            : '<span style="color:#dc3232">' . esc_html__('Disabled', 'power-discount') . '</span>';
        $toggleNonce = wp_create_nonce('power_discount_admin');
        return sprintf(
            '%s &nbsp;<a href="#" class="pd-toggle-status" data-id="%d" data-nonce="%s">%s</a>',
            $label,
            (int) $item['id'],
            esc_attr($toggleNonce),
            esc_html__('Toggle', 'power-discount')
        );
    }

    /** @param array<string, mixed> $item */
    public function column_priority($item): string
    {
        return (string) (int) $item['priority'];
    }

    /** @param array<string, mixed> $item */
    public function column_schedule($item): string
    {
        $start = (string) ($item['starts_at'] ?? '');
        $end = (string) ($item['ends_at'] ?? '');
        if ($start === '' && $end === '') {
            return '<span style="color:#999">' . esc_html__('Always', 'power-discount') . '</span>';
        }
        return esc_html(($start ?: '...') . ' → ' . ($end ?: '...'));
    }

    /** @param array<string, mixed> $item */
    public function column_used_count($item): string
    {
        return (string) (int) $item['used_count'];
    }
}
```

#### Step 2: `src/Admin/RulesListPage.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Repository\RuleRepository;

final class RulesListPage
{
    private RuleRepository $rules;

    public function __construct(RuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function render(): void
    {
        $table = new RulesListTable($this->rules);
        $table->prepare_items();

        $newUrl = add_query_arg([
            'page'   => 'power-discount',
            'action' => 'new',
        ], admin_url('admin.php'));

        require POWER_DISCOUNT_DIR . 'src/Admin/views/rule-list.php';
    }

    public function handleDelete(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('pd_delete_rule_' . $id);

        if ($id > 0) {
            $this->rules->delete($id);
            Notices::add(__('Rule deleted.', 'power-discount'), 'success');
        }

        wp_safe_redirect(admin_url('admin.php?page=power-discount'));
        exit;
    }

    public function handleDuplicate(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('pd_duplicate_rule_' . $id);

        $original = $this->rules->findById($id);
        if ($original === null) {
            wp_safe_redirect(admin_url('admin.php?page=power-discount'));
            exit;
        }

        $copy = new \PowerDiscount\Domain\Rule([
            'title'       => $original->getTitle() . ' (copy)',
            'type'        => $original->getType(),
            'status'      => 0, // duplicates start disabled
            'priority'    => $original->getPriority(),
            'exclusive'   => $original->isExclusive(),
            'starts_at'   => $original->getStartsAt(),
            'ends_at'     => $original->getEndsAt(),
            'usage_limit' => $original->getUsageLimit(),
            'config'      => $original->getConfig(),
            'filters'     => $original->getFilters(),
            'conditions'  => $original->getConditions(),
            'label'       => $original->getLabel(),
            'notes'       => $original->getNotes(),
        ]);

        $newId = $this->rules->insert($copy);
        Notices::add(__('Rule duplicated.', 'power-discount'), 'success');

        wp_safe_redirect(add_query_arg([
            'page'   => 'power-discount',
            'action' => 'edit',
            'id'     => $newId,
        ], admin_url('admin.php')));
        exit;
    }
}
```

#### Step 3: `src/Admin/views/rule-list.php`

```php
<?php
/** @var \PowerDiscount\Admin\RulesListTable $table */
/** @var string $newUrl */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Power Discount Rules', 'power-discount'); ?></h1>
    <a href="<?php echo esc_url($newUrl); ?>" class="page-title-action"><?php esc_html_e('Add New', 'power-discount'); ?></a>
    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="power-discount">
        <?php $table->display(); ?>
    </form>
</div>
```

#### Step 4: `php -l` all 3 files.

#### Step 5: Commit

```bash
git add src/Admin/RulesListTable.php src/Admin/RulesListPage.php src/Admin/views/rule-list.php
git commit -m "feat(admin): add RulesListTable and RulesListPage with delete/duplicate"
```

---

### Task 4: RuleEditPage + view

**Files:**
- Create: `src/Admin/RuleEditPage.php`
- Create: `src/Admin/views/rule-edit.php`

#### Step 1: `src/Admin/RuleEditPage.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use InvalidArgumentException;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Repository\RuleRepository;

final class RuleEditPage
{
    private RuleRepository $rules;

    public function __construct(RuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function render(): void
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $rule = $id > 0 ? $this->rules->findById($id) : null;

        if ($rule === null) {
            // New rule scaffold with sensible defaults.
            $rule = new Rule([
                'title'    => '',
                'type'     => 'simple',
                'status'   => 1,
                'priority' => 10,
                'config'   => ['method' => 'percentage', 'value' => 10],
            ]);
        }

        $formData = RuleFormMapper::toFormData($rule);
        $isNew = $rule->getId() === 0;
        $strategyTypes = [
            'simple'         => __('Simple (per-product)', 'power-discount'),
            'bulk'           => __('Bulk (quantity tiers)', 'power-discount'),
            'cart'           => __('Cart (whole-cart discount)', 'power-discount'),
            'set'            => __('Set (任選 N 件)', 'power-discount'),
            'buy_x_get_y'    => __('Buy X Get Y', 'power-discount'),
            'nth_item'       => __('Nth item (第 N 件 X 折)', 'power-discount'),
            'cross_category' => __('Cross-category (紅配綠)', 'power-discount'),
            'free_shipping'  => __('Free Shipping', 'power-discount'),
        ];

        require POWER_DISCOUNT_DIR . 'src/Admin/views/rule-edit.php';
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }
        check_admin_referer('pd_save_rule');

        $post = wp_unslash($_POST);
        if (!is_array($post)) {
            $post = [];
        }

        try {
            $rule = RuleFormMapper::fromFormData($post);
        } catch (InvalidArgumentException $e) {
            Notices::add($e->getMessage(), 'error');
            $redirectId = (int) ($post['id'] ?? 0);
            $redirectAction = $redirectId > 0 ? 'edit' : 'new';
            $args = ['page' => 'power-discount', 'action' => $redirectAction];
            if ($redirectId > 0) {
                $args['id'] = $redirectId;
            }
            wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            exit;
        }

        if ($rule->getId() > 0) {
            $this->rules->update($rule);
            Notices::add(__('Rule updated.', 'power-discount'), 'success');
        } else {
            $newId = $this->rules->insert($rule);
            Notices::add(__('Rule created.', 'power-discount'), 'success');
            wp_safe_redirect(add_query_arg([
                'page'   => 'power-discount',
                'action' => 'edit',
                'id'     => $newId,
            ], admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg([
            'page'   => 'power-discount',
            'action' => 'edit',
            'id'     => $rule->getId(),
        ], admin_url('admin.php')));
        exit;
    }
}
```

#### Step 2: `src/Admin/views/rule-edit.php`

```php
<?php
/**
 * @var array<string, mixed> $formData
 * @var bool $isNew
 * @var array<string, string> $strategyTypes
 */
if (!defined('ABSPATH')) {
    exit;
}

$pageTitle = $isNew ? __('Add Rule', 'power-discount') : __('Edit Rule', 'power-discount');
$listUrl = admin_url('admin.php?page=power-discount');
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html($pageTitle); ?></h1>
    <a href="<?php echo esc_url($listUrl); ?>" class="page-title-action">
        <?php esc_html_e('Back to list', 'power-discount'); ?>
    </a>
    <hr class="wp-header-end">

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="pd_save_rule">
        <input type="hidden" name="id" value="<?php echo (int) $formData['id']; ?>">
        <?php wp_nonce_field('pd_save_rule'); ?>

        <table class="form-table">
            <tr>
                <th><label for="pd-title"><?php esc_html_e('Title', 'power-discount'); ?> <span style="color:red">*</span></label></th>
                <td>
                    <input type="text" id="pd-title" name="title" value="<?php echo esc_attr((string) $formData['title']); ?>" class="regular-text" required>
                </td>
            </tr>
            <tr>
                <th><label for="pd-type"><?php esc_html_e('Discount type', 'power-discount'); ?></label></th>
                <td>
                    <select id="pd-type" name="type">
                        <?php foreach ($strategyTypes as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"<?php selected($formData['type'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="pd-status"><?php esc_html_e('Status', 'power-discount'); ?></label></th>
                <td>
                    <select id="pd-status" name="status">
                        <option value="1"<?php selected($formData['status'], 1); ?>><?php esc_html_e('Enabled', 'power-discount'); ?></option>
                        <option value="0"<?php selected($formData['status'], 0); ?>><?php esc_html_e('Disabled', 'power-discount'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="pd-priority"><?php esc_html_e('Priority', 'power-discount'); ?></label></th>
                <td>
                    <input type="number" id="pd-priority" name="priority" value="<?php echo (int) $formData['priority']; ?>" min="0" class="small-text">
                    <p class="description"><?php esc_html_e('Lower number = higher priority. Rules run in priority ASC order.', 'power-discount'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pd-exclusive"><?php esc_html_e('Exclusive', 'power-discount'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="pd-exclusive" name="exclusive" value="1"<?php checked($formData['exclusive'], 1); ?>>
                        <?php esc_html_e('Stop processing further rules after this matches', 'power-discount'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label><?php esc_html_e('Schedule', 'power-discount'); ?></label></th>
                <td>
                    <input type="text" name="starts_at" value="<?php echo esc_attr((string) $formData['starts_at']); ?>" placeholder="YYYY-MM-DD HH:MM:SS"> →
                    <input type="text" name="ends_at" value="<?php echo esc_attr((string) $formData['ends_at']); ?>" placeholder="YYYY-MM-DD HH:MM:SS">
                    <p class="description"><?php esc_html_e('Leave both blank for no schedule restriction.', 'power-discount'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pd-usage-limit"><?php esc_html_e('Usage limit', 'power-discount'); ?></label></th>
                <td>
                    <input type="number" id="pd-usage-limit" name="usage_limit" value="<?php echo esc_attr((string) $formData['usage_limit']); ?>" class="small-text" min="0">
                    <span class="description"><?php esc_html_e('Used:', 'power-discount'); ?> <?php echo (int) $formData['used_count']; ?></span>
                    <p class="description"><?php esc_html_e('Leave blank for unlimited.', 'power-discount'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pd-label"><?php esc_html_e('Cart label', 'power-discount'); ?></label></th>
                <td>
                    <input type="text" id="pd-label" name="label" value="<?php echo esc_attr((string) $formData['label']); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Shown to the customer in the cart when this rule applies.', 'power-discount'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pd-config"><?php esc_html_e('Config (JSON)', 'power-discount'); ?></label></th>
                <td>
                    <textarea id="pd-config" name="config_json" rows="8" class="large-text code"><?php echo esc_textarea((string) $formData['config_json']); ?></textarea>
                    <p class="description"><?php esc_html_e('Strategy-specific settings as JSON. See documentation for each type.', 'power-discount'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pd-filters"><?php esc_html_e('Product filters (JSON)', 'power-discount'); ?></label></th>
                <td>
                    <textarea id="pd-filters" name="filters_json" rows="6" class="large-text code"><?php echo esc_textarea((string) $formData['filters_json']); ?></textarea>
                    <p class="description"><?php echo wp_kses_post(__('Example: <code>{"items":[{"type":"categories","method":"in","ids":[12]}]}</code>', 'power-discount')); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pd-conditions"><?php esc_html_e('Conditions (JSON)', 'power-discount'); ?></label></th>
                <td>
                    <textarea id="pd-conditions" name="conditions_json" rows="6" class="large-text code"><?php echo esc_textarea((string) $formData['conditions_json']); ?></textarea>
                    <p class="description"><?php echo wp_kses_post(__('Example: <code>{"logic":"and","items":[{"type":"cart_subtotal","operator":"&gt;=","value":1000}]}</code>', 'power-discount')); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="pd-notes"><?php esc_html_e('Internal notes', 'power-discount'); ?></label></th>
                <td>
                    <textarea id="pd-notes" name="notes" rows="3" class="large-text"><?php echo esc_textarea((string) $formData['notes']); ?></textarea>
                </td>
            </tr>
        </table>

        <?php submit_button($isNew ? __('Create rule', 'power-discount') : __('Save rule', 'power-discount')); ?>
    </form>
</div>
```

#### Step 3: `php -l` both files.

#### Step 4: Commit

```bash
git add src/Admin/RuleEditPage.php src/Admin/views/rule-edit.php
git commit -m "feat(admin): add RuleEditPage with form-based editor and JSON validation"
```

---

### Task 5: AjaxController + admin.js + Plugin wire-up + README + manual verification

**Files:**
- Create: `src/Admin/AjaxController.php`
- Create: `assets/admin/admin.js`
- Modify: `src/Plugin.php`
- Modify: `README.md`
- Create: `docs/phase-4b-manual-verification.md`

#### Step 1: `src/Admin/AjaxController.php`

```php
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
}
```

#### Step 2: `assets/admin/admin.js`

```javascript
(function ($) {
    'use strict';

    $(document).on('click', '.pd-toggle-status', function (e) {
        e.preventDefault();
        var $link = $(this);
        var id = $link.data('id');
        var nonce = $link.data('nonce');
        if (!id) {
            return;
        }
        $.post(PowerDiscountAdmin.ajaxUrl, {
            action: 'pd_toggle_rule_status',
            id: id,
            nonce: nonce
        }).done(function () {
            window.location.reload();
        }).fail(function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Toggle failed';
            window.alert(msg);
        });
    });
})(jQuery);
```

#### Step 3: Modify `src/Plugin.php` — wire admin classes inside `boot()` (right after CartHooks/ShippingHooks registration):

Add imports:
```php
use PowerDiscount\Admin\AdminMenu;
use PowerDiscount\Admin\AjaxController;
use PowerDiscount\Admin\Notices;
use PowerDiscount\Admin\RuleEditPage;
use PowerDiscount\Admin\RulesListPage;
```

Inside `boot()`, after the integration hook registrations, add:

```php
        if (is_admin()) {
            $listPage = new RulesListPage($rulesRepo);
            $editPage = new RuleEditPage($rulesRepo);
            (new AdminMenu($rulesRepo, $listPage, $editPage))->register();
            (new AjaxController($rulesRepo))->register();
            (new Notices())->register();
        }
```

#### Step 4: Update `README.md` `## Status`:

```markdown
## Status

**Phase 4b (PHP Admin UI)** — complete.

- Admin menu under `WooCommerce → Power Discount`
- Rule list page (WP_List_Table): edit / duplicate / delete / AJAX status toggle
- Rule edit page (PHP form): title, type, status, priority, exclusive, schedule, usage limit, label, notes + JSON textareas for `config`, `filters`, `conditions`
- `RuleFormMapper` validates JSON and field requirements (unit-tested)
- Admin notices via transient queue

Pending: React rule builder (Phase 4d, optional), Frontend price table / shipping bar (Phase 4c), Reports page (Phase 4c).
```

#### Step 5: Create `docs/phase-4b-manual-verification.md`

````markdown
# Phase 4b Manual Verification

Activate `power-discount`. Confirm `WooCommerce → Power Discount` appears in the admin menu (requires `manage_woocommerce` capability).

## List page

- [ ] Visit `/wp-admin/admin.php?page=power-discount`. Empty table renders if no rules exist.
- [ ] Click "Add New" → goes to edit page with empty form scaffold.

## Create rule

- [ ] On the new rule form, enter title `Test 10%`, leave defaults, set `config_json` to `{"method":"percentage","value":10}`. Click Create.
- [ ] After save, redirected to edit page with success notice. List page now shows the rule.

## Edit existing

- [ ] Click rule title → edit form pre-fills.
- [ ] Modify priority to 5, save → success notice, list shows priority 5.

## Validation

- [ ] Open edit form, change `config_json` to `{not json`. Save → error notice "Invalid JSON in config field." Form returns with original input lost (acceptable Phase 4b behaviour).
- [ ] Clear title field. Save → error "Rule title is required."

## AJAX status toggle

- [ ] Click "Toggle" link in Status column → page reloads, status flips.

## Duplicate

- [ ] Click "Duplicate" → redirected to edit page of new copy with `(copy)` suffix, status disabled.

## Delete

- [ ] Click "Delete" → confirm dialog, then page reloads, rule gone.

## Functional verification with a real cart

Create a `simple` rule with `config_json = {"method":"percentage","value":10}`, no filters, no conditions, status enabled.

- [ ] Add product to cart → 10% off applies.

## Known Gaps → Phase 4c/4d

- No frontend price table / shipping bar / saved label
- No reports page
- No React GUI for strategy/condition/filter (currently raw JSON textarea — power-user only)
- Pagination on the list page (low priority for typical rule counts)
````

#### Step 6: `php -l` all modified files. `vendor/bin/phpunit` — should still be **238 tests** (Task 1 added 9 new tests; Tasks 2-5 added integration code with no new tests).

#### Step 7: Commit

```bash
git add src/Admin/AjaxController.php assets/admin/admin.js src/Plugin.php README.md docs/phase-4b-manual-verification.md
git commit -m "feat(admin): wire AdminMenu/AjaxController in Plugin::boot + Phase 4b docs"
```

---

## Phase 4b Exit Criteria

- ✅ `vendor/bin/phpunit` ≥ 238 tests green
- ✅ All `.php` files lint clean
- ✅ Admin menu registers under WooCommerce
- ✅ List page renders rules with edit/delete/duplicate/toggle actions
- ✅ Edit page handles new + existing rules with form validation
- ✅ AJAX status toggle works
- ✅ Capability checks + nonces on all mutating actions
- ✅ README + manual verification doc committed

## Known Gaps → Phase 4c/4d

- No frontend price table / shipping bar / saved label
- No reports page
- No React rule builder (JSON textareas only)
- No pagination / search / filter on list page
- No bulk actions
