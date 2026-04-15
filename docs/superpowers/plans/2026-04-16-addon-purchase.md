# 加價購 (Add-on Purchase) 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 為 Power Discount 外掛新增「加價購」子系統，讓商家在 WooCommerce 商品頁顯示可勾選的 add-on 商品（自訂特價），並支援雙向設定、功能 opt-in、排除其他折扣等行為。

**Architecture:** 獨立 DB 表 `pd_addon_rules` + 獨立 Domain 物件 + 獨立 Admin / Frontend / Integration 元件。與折扣引擎僅透過 `CartContextBuilder` 的一個過濾點交集（實作 `exclude_from_discounts` 旗標），**不改動 Calculator / Rule / Strategy / Condition / Filter**。

**Tech Stack:** PHP 7.4+ · WordPress 6.0+ · WooCommerce 7.0+ · PHPUnit 9.6（沿用既有 `DatabaseAdapter` / `InMemoryDatabaseAdapter` / `JsonSerializer` / `Notices` 模式）

**Version target:** 1.1.0

**Reference spec:** [`docs/superpowers/specs/2026-04-16-addon-purchase-design.md`](../specs/2026-04-16-addon-purchase-design.md)

---

## Phase A — Domain + Persistence

### Task A1: `Domain\AddonItem` 值物件

**Files:**
- Create: `src/Domain/AddonItem.php`
- Test: `tests/Unit/Domain/AddonItemTest.php`

- [ ] **Write the failing test** (`tests/Unit/Domain/AddonItemTest.php`)

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\AddonItem;

final class AddonItemTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $item = new AddonItem(101, 90.0);
        self::assertSame(101, $item->getProductId());
        self::assertSame(90.0, $item->getSpecialPrice());
    }

    public function testFromArray(): void
    {
        $item = AddonItem::fromArray(['product_id' => 5, 'special_price' => 120]);
        self::assertSame(5, $item->getProductId());
        self::assertSame(120.0, $item->getSpecialPrice());
    }

    public function testToArray(): void
    {
        $item = new AddonItem(42, 75.5);
        self::assertSame(['product_id' => 42, 'special_price' => 75.5], $item->toArray());
    }

    public function testRejectsInvalidProductId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AddonItem(0, 10.0);
    }

    public function testRejectsNegativeSpecialPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AddonItem(1, -1.0);
    }
}
```

- [ ] **Run test** — `vendor/bin/phpunit tests/Unit/Domain/AddonItemTest.php`. Expected: class not found.

- [ ] **Implement** (`src/Domain/AddonItem.php`)

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

use InvalidArgumentException;

final class AddonItem
{
    private int $productId;
    private float $specialPrice;

    public function __construct(int $productId, float $specialPrice)
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('AddonItem product_id must be > 0');
        }
        if ($specialPrice < 0) {
            throw new InvalidArgumentException('AddonItem special_price must be >= 0');
        }
        $this->productId = $productId;
        $this->specialPrice = $specialPrice;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['product_id'] ?? 0),
            (float) ($data['special_price'] ?? 0)
        );
    }

    public function getProductId(): int { return $this->productId; }
    public function getSpecialPrice(): float { return $this->specialPrice; }

    /** @return array{product_id: int, special_price: float} */
    public function toArray(): array
    {
        return [
            'product_id'    => $this->productId,
            'special_price' => $this->specialPrice,
        ];
    }
}
```

- [ ] **Run test** — Expected: 5/5 pass.
- [ ] **Commit** — `feat(addon): add AddonItem value object`

---

### Task A2: `Domain\AddonRule` 值物件

**Files:**
- Create: `src/Domain/AddonRule.php`
- Test: `tests/Unit/Domain/AddonRuleTest.php`

- [ ] **Write the failing test**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\AddonItem;
use PowerDiscount\Domain\AddonRule;

final class AddonRuleTest extends TestCase
{
    private function makeRule(array $overrides = []): AddonRule
    {
        return new AddonRule(array_merge([
            'id'                     => 1,
            'title'                  => '咖啡豆加價購濾紙',
            'status'                 => 1,
            'priority'               => 10,
            'addon_items'            => [
                ['product_id' => 101, 'special_price' => 90],
                ['product_id' => 102, 'special_price' => 150],
            ],
            'target_product_ids'     => [12, 34, 56],
            'exclude_from_discounts' => false,
        ], $overrides));
    }

    public function testConstructAndGetters(): void
    {
        $rule = $this->makeRule();
        self::assertSame(1, $rule->getId());
        self::assertSame('咖啡豆加價購濾紙', $rule->getTitle());
        self::assertTrue($rule->isEnabled());
        self::assertSame(10, $rule->getPriority());
        self::assertFalse($rule->isExcludeFromDiscounts());
        self::assertCount(2, $rule->getAddonItems());
        self::assertSame([12, 34, 56], $rule->getTargetProductIds());
    }

    public function testAddonItemsAreValueObjects(): void
    {
        $rule = $this->makeRule();
        $items = $rule->getAddonItems();
        self::assertInstanceOf(AddonItem::class, $items[0]);
        self::assertSame(101, $items[0]->getProductId());
        self::assertSame(90.0, $items[0]->getSpecialPrice());
    }

    public function testMatchesTarget(): void
    {
        $rule = $this->makeRule();
        self::assertTrue($rule->matchesTarget(12));
        self::assertTrue($rule->matchesTarget(34));
        self::assertFalse($rule->matchesTarget(99));
    }

    public function testGetSpecialPriceFor(): void
    {
        $rule = $this->makeRule();
        self::assertSame(90.0, $rule->getSpecialPriceFor(101));
        self::assertSame(150.0, $rule->getSpecialPriceFor(102));
        self::assertNull($rule->getSpecialPriceFor(999));
    }

    public function testContainsAddon(): void
    {
        $rule = $this->makeRule();
        self::assertTrue($rule->containsAddon(101));
        self::assertFalse($rule->containsAddon(999));
    }

    public function testExcludeFromDiscountsFlag(): void
    {
        $rule = $this->makeRule(['exclude_from_discounts' => true]);
        self::assertTrue($rule->isExcludeFromDiscounts());
    }

    public function testDisabledStatus(): void
    {
        $rule = $this->makeRule(['status' => 0]);
        self::assertFalse($rule->isEnabled());
    }
}
```

- [ ] **Run test** — Expected: class not found.

- [ ] **Implement** (`src/Domain/AddonRule.php`)

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

final class AddonRule
{
    private int $id;
    private string $title;
    private int $status;
    private int $priority;
    /** @var AddonItem[] */
    private array $addonItems;
    /** @var int[] */
    private array $targetProductIds;
    private bool $excludeFromDiscounts;

    public function __construct(array $data)
    {
        $this->id       = (int) ($data['id'] ?? 0);
        $this->title    = (string) ($data['title'] ?? '');
        $this->status   = (int) ($data['status'] ?? 1);
        $this->priority = (int) ($data['priority'] ?? 10);

        $this->addonItems = [];
        foreach ((array) ($data['addon_items'] ?? []) as $raw) {
            if (!is_array($raw)) continue;
            try {
                $this->addonItems[] = AddonItem::fromArray($raw);
            } catch (\InvalidArgumentException $e) {
                // skip invalid entries silently — form validation enforces at POST time
            }
        }

        $this->targetProductIds = array_values(array_filter(
            array_map('intval', (array) ($data['target_product_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        ));

        $this->excludeFromDiscounts = (bool) ($data['exclude_from_discounts'] ?? false);
    }

    public function getId(): int                   { return $this->id; }
    public function getTitle(): string             { return $this->title; }
    public function getStatus(): int               { return $this->status; }
    public function getPriority(): int             { return $this->priority; }
    public function isEnabled(): bool              { return $this->status === 1; }
    public function isExcludeFromDiscounts(): bool { return $this->excludeFromDiscounts; }

    /** @return AddonItem[] */
    public function getAddonItems(): array         { return $this->addonItems; }

    /** @return int[] */
    public function getTargetProductIds(): array   { return $this->targetProductIds; }

    public function matchesTarget(int $productId): bool
    {
        return in_array($productId, $this->targetProductIds, true);
    }

    public function containsAddon(int $productId): bool
    {
        foreach ($this->addonItems as $item) {
            if ($item->getProductId() === $productId) {
                return true;
            }
        }
        return false;
    }

    public function getSpecialPriceFor(int $addonProductId): ?float
    {
        foreach ($this->addonItems as $item) {
            if ($item->getProductId() === $addonProductId) {
                return $item->getSpecialPrice();
            }
        }
        return null;
    }
}
```

- [ ] **Run test** — Expected: 7/7 pass.
- [ ] **Commit** — `feat(addon): add AddonRule domain entity`

---

### Task A3: Schema v3 migration — `pd_addon_rules` 表

**Files:**
- Modify: `src/Install/Migrator.php`

- [ ] **Edit** `Migrator.php`

```php
private const SCHEMA_VERSION = '3';
```

After the existing `$order_discounts_sql` dbDelta call, add:

```php
$addon_rules_table = $wpdb->prefix . 'pd_addon_rules';
$addon_rules_sql = "CREATE TABLE {$addon_rules_table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    priority INT NOT NULL DEFAULT 10,
    addon_items LONGTEXT NOT NULL,
    target_product_ids LONGTEXT NOT NULL,
    exclude_from_discounts TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY  (id),
    KEY idx_status_priority (status, priority)
) {$charset_collate};";
dbDelta($addon_rules_sql);
```

- [ ] **Verify** — In dev WP container:
  ```bash
  docker exec power-discount-dev-db mysql -uwp -pwppass wordpress -e "DESCRIBE wp_pd_addon_rules"
  ```
  Expected: table exists with all columns.

- [ ] **Run tests** — `vendor/bin/phpunit` — Expected: all pass (existing tests unaffected).
- [ ] **Commit** — `feat(addon): schema v3 adds pd_addon_rules table`

---

### Task A4: `AddonRuleRepository`

**Files:**
- Create: `src/Repository/AddonRuleRepository.php`
- Test: `tests/Unit/Repository/AddonRuleRepositoryTest.php`

- [ ] **Write test** using `InMemoryDatabaseAdapter` (pattern from existing `RuleRepositoryTest`)

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Persistence\InMemoryDatabaseAdapter;
use PowerDiscount\Repository\AddonRuleRepository;

final class AddonRuleRepositoryTest extends TestCase
{
    private AddonRuleRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new AddonRuleRepository(new InMemoryDatabaseAdapter());
    }

    private function make(array $overrides = []): AddonRule
    {
        return new AddonRule(array_merge([
            'title'              => 'Test rule',
            'status'             => 1,
            'priority'           => 10,
            'addon_items'        => [['product_id' => 101, 'special_price' => 90]],
            'target_product_ids' => [12, 34],
        ], $overrides));
    }

    public function testInsertAndFindById(): void
    {
        $id = $this->repo->insert($this->make());
        self::assertGreaterThan(0, $id);
        $rule = $this->repo->findById($id);
        self::assertNotNull($rule);
        self::assertSame('Test rule', $rule->getTitle());
        self::assertSame([12, 34], $rule->getTargetProductIds());
    }

    public function testFindAllOrdersByPriority(): void
    {
        $this->repo->insert($this->make(['title' => 'B', 'priority' => 20]));
        $this->repo->insert($this->make(['title' => 'A', 'priority' => 10]));
        $this->repo->insert($this->make(['title' => 'C', 'priority' => 30]));
        $all = $this->repo->findAll();
        self::assertCount(3, $all);
        self::assertSame('A', $all[0]->getTitle());
        self::assertSame('B', $all[1]->getTitle());
        self::assertSame('C', $all[2]->getTitle());
    }

    public function testFindActiveForTargetFiltersDisabledAndNonMatching(): void
    {
        $this->repo->insert($this->make(['title' => 'enabled match', 'target_product_ids' => [12]]));
        $this->repo->insert($this->make(['title' => 'disabled match', 'status' => 0, 'target_product_ids' => [12]]));
        $this->repo->insert($this->make(['title' => 'enabled no match', 'target_product_ids' => [99]]));
        $matched = $this->repo->findActiveForTarget(12);
        self::assertCount(1, $matched);
        self::assertSame('enabled match', $matched[0]->getTitle());
    }

    public function testFindContainingAddon(): void
    {
        $this->repo->insert($this->make(['title' => 'has 101', 'addon_items' => [['product_id' => 101, 'special_price' => 90]]]));
        $this->repo->insert($this->make(['title' => 'has 200', 'addon_items' => [['product_id' => 200, 'special_price' => 50]]]));
        $rules = $this->repo->findContainingAddon(101);
        self::assertCount(1, $rules);
        self::assertSame('has 101', $rules[0]->getTitle());
    }

    public function testFindContainingTarget(): void
    {
        $this->repo->insert($this->make(['title' => 'targets 12', 'target_product_ids' => [12]]));
        $this->repo->insert($this->make(['title' => 'targets 99', 'target_product_ids' => [99]]));
        $rules = $this->repo->findContainingTarget(12);
        self::assertCount(1, $rules);
        self::assertSame('targets 12', $rules[0]->getTitle());
    }

    public function testUpdate(): void
    {
        $id = $this->repo->insert($this->make());
        $rule = $this->repo->findById($id);
        // rebuild with new data preserving id
        $updated = new AddonRule([
            'id'                 => $id,
            'title'              => 'Renamed',
            'status'             => 0,
            'priority'           => 50,
            'addon_items'        => $rule->getAddonItems() ? array_map(fn($i) => $i->toArray(), $rule->getAddonItems()) : [],
            'target_product_ids' => $rule->getTargetProductIds(),
        ]);
        $this->repo->update($updated);
        $reloaded = $this->repo->findById($id);
        self::assertSame('Renamed', $reloaded->getTitle());
        self::assertFalse($reloaded->isEnabled());
        self::assertSame(50, $reloaded->getPriority());
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert($this->make());
        $this->repo->delete($id);
        self::assertNull($this->repo->findById($id));
    }

    public function testReorderAssignsPriorityByPosition(): void
    {
        $a = $this->repo->insert($this->make(['title' => 'A']));
        $b = $this->repo->insert($this->make(['title' => 'B']));
        $c = $this->repo->insert($this->make(['title' => 'C']));
        $this->repo->reorder([$c, $a, $b]);
        $all = $this->repo->findAll();
        self::assertSame('C', $all[0]->getTitle());
        self::assertSame(1, $all[0]->getPriority());
        self::assertSame('A', $all[1]->getTitle());
        self::assertSame(2, $all[1]->getPriority());
        self::assertSame('B', $all[2]->getTitle());
        self::assertSame(3, $all[2]->getPriority());
    }
}
```

- [ ] **Implement** (`src/Repository/AddonRuleRepository.php`)

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Repository;

use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Persistence\DatabaseAdapter;
use PowerDiscount\Persistence\JsonSerializer;

final class AddonRuleRepository
{
    private const TABLE = 'pd_addon_rules';

    private DatabaseAdapter $db;

    public function __construct(DatabaseAdapter $db)
    {
        $this->db = $db;
    }

    public function insert(AddonRule $rule): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $row = $this->toRow($rule);
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        return $this->db->insert($this->table(), $row);
    }

    public function update(AddonRule $rule): int
    {
        $row = $this->toRow($rule);
        $row['updated_at'] = gmdate('Y-m-d H:i:s');
        return $this->db->update($this->table(), $row, ['id' => $rule->getId()]);
    }

    public function delete(int $id): int
    {
        return $this->db->delete($this->table(), ['id' => $id]);
    }

    public function findById(int $id): ?AddonRule
    {
        $row = $this->db->findById($this->table(), $id);
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return AddonRule[] */
    public function findAll(): array
    {
        $rows = $this->db->findWhere($this->table(), [], ['priority' => 'ASC', 'id' => 'ASC']);
        return array_map([$this, 'hydrate'], $rows);
    }

    /** @return AddonRule[] */
    public function findActiveForTarget(int $productId): array
    {
        $matched = [];
        foreach ($this->findAll() as $rule) {
            if ($rule->isEnabled() && $rule->matchesTarget($productId)) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    /** @return AddonRule[] */
    public function findContainingAddon(int $addonProductId): array
    {
        $matched = [];
        foreach ($this->findAll() as $rule) {
            if ($rule->containsAddon($addonProductId)) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    /** @return AddonRule[] */
    public function findContainingTarget(int $targetProductId): array
    {
        $matched = [];
        foreach ($this->findAll() as $rule) {
            if ($rule->matchesTarget($targetProductId)) {
                $matched[] = $rule;
            }
        }
        return $matched;
    }

    public function getMaxPriority(): int
    {
        $max = 0;
        foreach ($this->findAll() as $rule) {
            if ($rule->getPriority() > $max) {
                $max = $rule->getPriority();
            }
        }
        return $max;
    }

    /** @param int[] $orderedIds */
    public function reorder(array $orderedIds): void
    {
        $position = 1;
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            $this->db->update(
                $this->table(),
                ['priority' => $position, 'updated_at' => gmdate('Y-m-d H:i:s')],
                ['id' => $id]
            );
            $position++;
        }
    }

    private function table(): string
    {
        return $this->db->table(self::TABLE);
    }

    /** @return array<string, mixed> */
    private function toRow(AddonRule $rule): array
    {
        $addonArray = array_map(
            static fn ($item) => $item->toArray(),
            $rule->getAddonItems()
        );
        return [
            'title'                  => $rule->getTitle(),
            'status'                 => $rule->getStatus(),
            'priority'               => $rule->getPriority(),
            'addon_items'            => JsonSerializer::encode($addonArray),
            'target_product_ids'     => JsonSerializer::encode($rule->getTargetProductIds()),
            'exclude_from_discounts' => $rule->isExcludeFromDiscounts() ? 1 : 0,
        ];
    }

    private function hydrate(array $row): AddonRule
    {
        return new AddonRule([
            'id'                     => (int) ($row['id'] ?? 0),
            'title'                  => (string) ($row['title'] ?? ''),
            'status'                 => (int) ($row['status'] ?? 1),
            'priority'               => (int) ($row['priority'] ?? 10),
            'addon_items'            => JsonSerializer::decode((string) ($row['addon_items'] ?? '')),
            'target_product_ids'     => JsonSerializer::decode((string) ($row['target_product_ids'] ?? '')),
            'exclude_from_discounts' => (bool) ($row['exclude_from_discounts'] ?? false),
        ]);
    }
}
```

- [ ] **Run test** — Expected: 8/8 pass.
- [ ] **Commit** — `feat(addon): AddonRuleRepository with CRUD + query helpers`

---

## Phase B — Admin shell

### Task B1: 選單註冊 + 路由 + 啟用 option

**Files:**
- Create: `src/Admin/AddonMenu.php`
- Create: `src/Admin/AddonActivationPage.php`
- Create: `src/Admin/views/addon-activation.php`
- Modify: `src/Plugin.php` (wire in AddonMenu)
- Modify: `src/Admin/AdminMenu.php` (add submenu)

- [ ] **Implement `AddonMenu`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Repository\AddonRuleRepository;

final class AddonMenu
{
    public const OPTION_ENABLED = 'power_discount_addon_enabled';

    private AddonRuleRepository $rules;
    private AddonActivationPage $activationPage;
    private AddonRulesListPage $listPage;
    private AddonRuleEditPage $editPage;

    public function __construct(
        AddonRuleRepository $rules,
        AddonActivationPage $activationPage,
        AddonRulesListPage $listPage,
        AddonRuleEditPage $editPage
    ) {
        $this->rules = $rules;
        $this->activationPage = $activationPage;
        $this->listPage = $listPage;
        $this->editPage = $editPage;
    }

    public function register(): void
    {
        add_action('admin_post_pd_activate_addons', [$this->activationPage, 'handleActivate']);
        add_action('admin_post_pd_deactivate_addons', [$this->activationPage, 'handleDeactivate']);
        add_action('admin_post_pd_save_addon_rule', [$this->editPage, 'handleSave']);
        add_action('admin_post_pd_delete_addon_rule', [$this->listPage, 'handleDelete']);
    }

    public static function isEnabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    public function route(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'power-discount'));
        }
        if (!self::isEnabled()) {
            $this->activationPage->render();
            return;
        }
        $action = isset($_GET['action']) ? sanitize_key((string) $_GET['action']) : '';
        if ($action === 'edit' || $action === 'new') {
            $this->editPage->render();
            return;
        }
        $this->listPage->render();
    }
}
```

- [ ] **Implement `AddonActivationPage`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

final class AddonActivationPage
{
    public function render(): void
    {
        $activateUrl = wp_nonce_url(
            add_query_arg(['action' => 'pd_activate_addons'], admin_url('admin-post.php')),
            'pd_activate_addons'
        );
        require POWER_DISCOUNT_DIR . 'src/Admin/views/addon-activation.php';
    }

    public function handleActivate(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }
        check_admin_referer('pd_activate_addons');
        update_option(AddonMenu::OPTION_ENABLED, true, false);
        Notices::add(__('加價購功能已啟用。', 'power-discount'), 'success');
        wp_safe_redirect(add_query_arg(['page' => 'power-discount-addons'], admin_url('admin.php')));
        exit;
    }

    public function handleDeactivate(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'power-discount'));
        }
        check_admin_referer('pd_deactivate_addons');
        update_option(AddonMenu::OPTION_ENABLED, false, false);
        Notices::add(__('加價購功能已停用。既有規則未刪除，再次啟用即可繼續使用。', 'power-discount'), 'info');
        wp_safe_redirect(add_query_arg(['page' => 'power-discount-addons'], admin_url('admin.php')));
        exit;
    }
}
```

- [ ] **Create `addon-activation.php` view**

```php
<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap pd-addon-activation">
    <h1 class="wp-heading-inline"><?php esc_html_e('加價購', 'power-discount'); ?></h1>
    <hr class="wp-header-end">

    <div class="pd-activation-card">
        <div class="pd-activation-icon">🛍️</div>
        <h2><?php esc_html_e('啟用加價購功能', 'power-discount'); ?></h2>
        <p class="pd-activation-lede">
            <?php esc_html_e('讓顧客在購買特定商品時，以特價加購其他商品。例如買咖啡豆特價 $30 加購濾紙。', 'power-discount'); ?>
        </p>
        <ul class="pd-activation-features">
            <li>✓ <?php esc_html_e('商品頁面自動顯示加價購專區', 'power-discount'); ?></li>
            <li>✓ <?php esc_html_e('雙向設定：規則管理頁與商品編輯頁互通', 'power-discount'); ?></li>
            <li>✓ <?php esc_html_e('每個加價購商品可自訂特價', 'power-discount'); ?></li>
            <li>✓ <?php esc_html_e('可選擇將加價購商品排除於其他折扣規則之外', 'power-discount'); ?></li>
        </ul>
        <p>
            <a href="<?php echo esc_url($activateUrl); ?>" class="button button-primary button-large">
                <?php esc_html_e('啟用加價購功能', 'power-discount'); ?>
            </a>
        </p>
    </div>
</div>
```

- [ ] **Update `Plugin::boot()`** — construct `AddonRuleRepository`, `AddonActivationPage`, `AddonRulesListPage`, `AddonRuleEditPage`, `AddonMenu`, call `register()`. Pass `AddonMenu` instance into `AdminMenu` constructor.

- [ ] **Update `AdminMenu::registerMenu()`** to add the third submenu:

```php
add_submenu_page(
    'power-discount',
    __('加價購', 'power-discount'),
    __('加價購', 'power-discount'),
    'manage_woocommerce',
    'power-discount-addons',
    [$this->addonMenu, 'route']
);
```

- [ ] **CSS stub** — `assets/admin/admin.css` add:

```css
.pd-activation-card {
    max-width: 640px;
    margin: 40px auto;
    padding: 48px 56px;
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 12px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    text-align: center;
}
.pd-activation-icon { font-size: 56px; line-height: 1; margin-bottom: 16px; }
.pd-activation-lede { color: #6b7280; font-size: 15px; max-width: 440px; margin: 12px auto 24px; }
.pd-activation-features {
    text-align: left;
    display: inline-block;
    color: #374151;
    list-style: none;
    padding: 0;
    margin: 0 0 32px;
    font-size: 14px;
    line-height: 2;
}
```

- [ ] **Manual verify** — Load `wp-admin/admin.php?page=power-discount-addons`, see activation card. Click 啟用 → option=true → refresh → see empty list page (will be created in next task).
- [ ] **Run tests** — Expected: all existing tests still pass.
- [ ] **Commit** — `feat(addon): menu + opt-in activation page`

---

### Task B2: 規則清單頁 (空殼 + List Table)

**Files:**
- Create: `src/Admin/AddonRulesListPage.php`
- Create: `src/Admin/AddonRulesListTable.php`
- Create: `src/Admin/views/addon-list.php`

- [ ] **Implement `AddonRulesListPage`** — mirrors `RulesListPage` structure.

```php
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
        $table = new AddonRulesListTable($this->rules);
        $table->prepare_items();
        $newUrl = add_query_arg(['page' => 'power-discount-addons', 'action' => 'new'], admin_url('admin.php'));
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
```

- [ ] **Implement `AddonRulesListTable`** — mirrors `RulesListTable`. Columns:
  - `order` — drag handle + position pill
  - `status` — toggle switch
  - `title` — title + edit/delete row actions
  - `addons` — summary ("3 項加價購商品")
  - `targets` — summary ("5 個目標商品")
  - `exclusive` — ✓ or —

Use existing `.pd-toggle-switch`, `.pd-drag-handle`, `.pd-priority-pill`, `.pd-help-tip` patterns. Emit `<tr data-id>` via `single_row()` override.

- [ ] **Create `addon-list.php` view** — structurally identical to `rule-list.php` but with an extra "停用加價購功能" link in the header:

```php
<div class="wrap pd-rules-list pd-addons-list">
    <h1 class="wp-heading-inline"><?php esc_html_e('加價購規則', 'power-discount'); ?></h1>
    <a href="<?php echo esc_url($newUrl); ?>" class="page-title-action"><?php esc_html_e('新增規則', 'power-discount'); ?></a>
    <a href="<?php echo esc_url($deactivateUrl); ?>" class="page-title-action"
       onclick="return confirm('<?php echo esc_js(__('確定要停用加價購功能嗎？既有規則資料會保留。', 'power-discount')); ?>');">
        <?php esc_html_e('停用功能', 'power-discount'); ?>
    </a>
    <hr class="wp-header-end">
    <form method="get">
        <input type="hidden" name="page" value="power-discount-addons">
        <?php $table->display(); ?>
    </form>
</div>
```

- [ ] **Manual verify** — Navigate to the page, see empty list with "新增規則" and "停用功能" buttons.
- [ ] **Commit** — `feat(addon): rules list page + WP_List_Table`

---

## Phase C — 規則編輯頁

### Task C1: `AddonRuleFormMapper` + tests

**Files:**
- Create: `src/Admin/AddonRuleFormMapper.php`
- Test: `tests/Unit/Admin/AddonRuleFormMapperTest.php`

- [ ] **Write test** — cover cases:
  - Normal valid POST → AddonRule with correct fields
  - Missing title → throw 中文 exception
  - Empty addon_items → throw
  - Empty target_product_ids → throw
  - addon_item with product_id=0 → throw
  - addon_item with negative special_price → throw
  - `exclude_from_discounts` checkbox parses correctly
  - Duplicate addon product_id → throw

- [ ] **Implement** — Structure similar to `RuleFormMapper::fromFormData`:

```php
public static function fromFormData(array $post): AddonRule
{
    return self::build($post, true);
}

public static function fromFormDataLoose(array $post): AddonRule
{
    return self::build($post, false);
}

private static function build(array $post, bool $validate): AddonRule
{
    $title = trim((string) ($post['title'] ?? ''));
    if ($validate && $title === '') {
        throw new InvalidArgumentException(__('請輸入規則名稱。', 'power-discount'));
    }

    $rawItems = (array) ($post['addon_items'] ?? []);
    $items = [];
    $seen = [];
    foreach ($rawItems as $raw) {
        if (!is_array($raw)) continue;
        $pid = (int) ($raw['product_id'] ?? 0);
        $price = (float) ($raw['special_price'] ?? 0);
        if ($pid <= 0) continue;
        if ($validate && $price < 0) {
            throw new InvalidArgumentException(__('加價購商品的特價必須 ≥ 0。', 'power-discount'));
        }
        if ($validate && isset($seen[$pid])) {
            throw new InvalidArgumentException(__('同一個加價購商品不能在規則中重複列出。', 'power-discount'));
        }
        $items[] = ['product_id' => $pid, 'special_price' => $price];
        $seen[$pid] = true;
    }
    if ($validate && $items === []) {
        throw new InvalidArgumentException(__('至少需要指定一個加價購商品。', 'power-discount'));
    }

    $targets = array_values(array_filter(
        array_map('intval', (array) ($post['target_product_ids'] ?? [])),
        static fn (int $id): bool => $id > 0
    ));
    if ($validate && $targets === []) {
        throw new InvalidArgumentException(__('至少需要指定一個目標商品。', 'power-discount'));
    }

    return new AddonRule([
        'id'                     => (int) ($post['id'] ?? 0),
        'title'                  => $title,
        'status'                 => isset($post['status']) ? (int) $post['status'] : 1,
        'priority'               => isset($post['priority']) ? (int) $post['priority'] : 10,
        'addon_items'            => $items,
        'target_product_ids'     => $targets,
        'exclude_from_discounts' => !empty($post['exclude_from_discounts']),
    ]);
}
```

- [ ] **Run tests** — Expected: all pass.
- [ ] **Commit** — `feat(addon): AddonRuleFormMapper with validation`

---

### Task C2: `AddonRuleEditPage` + view

**Files:**
- Create: `src/Admin/AddonRuleEditPage.php`
- Create: `src/Admin/views/addon-edit.php`

- [ ] **Implement `AddonRuleEditPage`** — mirrors `RuleEditPage`:
  - `render()` — looks up rule or builds empty one, sets `$pendingRule`/`$pendingError` if set
  - `handleSave()` — `RuleFormMapper::fromFormData` try/catch → on error: `fromFormDataLoose` + render() + return
  - `withPriority()` private helper — new rules go to bottom via `getMaxPriority() + 1`

- [ ] **Create `addon-edit.php` view** with three sections:

Section 1 — 基本設定:
- 規則名稱 (text input)
- 狀態 (select: 啟用 / 停用)
- 排除其他折扣 (checkbox + 說明)
- (hidden priority field)

Section 2 — 加價購商品:
- Repeater block with `<template class="pd-repeater-template">` containing one row:
  - WC enhanced select with `data-action="woocommerce_json_search_products_and_variations"` for product
  - number input for special_price
  - remove button
- "新增加價購商品" button (uses existing `.pd-repeater-add[data-pd-add="addon-item"]`)
- Hidden template row uses `__INDEX__` placeholder
- Saved items pre-rendered

Section 3 — 目標商品:
- Single `wc-product-search multiple` select with saved target IDs pre-populated

Submit button "儲存規則".

The existing `addTemplateRow()` function in `admin.js` already handles `__INDEX__` replacement — we get repeater behavior for free.

- [ ] **Manual verify** — Create a new rule, add two addon items with search, select three targets, save. Reload → values preserved.
- [ ] **Commit** — `feat(addon): rule edit page with repeater + product search`

---

### Task C3: 拖拉排序 + Toggle AJAX

**Files:**
- Modify: `src/Admin/AjaxController.php` (add 2 actions)
- Modify: `assets/admin/admin.js` (reuse `initRulesSortable` pattern against `.pd-addons-list`)

- [ ] **Add `AddonRuleRepository` dependency** to `AjaxController` (or create a new `AddonAjaxController` — cleaner). Actually create **`AddonAjaxController`** to avoid bloating existing class.

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Admin;

use PowerDiscount\Domain\AddonRule;
use PowerDiscount\Repository\AddonRuleRepository;

final class AddonAjaxController
{
    private AddonRuleRepository $rules;

    public function __construct(AddonRuleRepository $rules)
    {
        $this->rules = $rules;
    }

    public function register(): void
    {
        add_action('wp_ajax_pd_toggle_addon_rule_status', [$this, 'toggleStatus']);
        add_action('wp_ajax_pd_reorder_addon_rules', [$this, 'reorder']);
        add_action('wp_ajax_pd_toggle_addon_metabox_rule', [$this, 'toggleMetaboxRule']);
    }

    public function toggleStatus(): void { /* mirror of AjaxController::toggleStatus */ }
    public function reorder(): void      { /* mirror of AjaxController::reorderRules */ }
    public function toggleMetaboxRule(): void { /* see Phase D */ }
}
```

Reuse logic from the existing ajax controller — changing `$this->rules` type and the status toggle to reconstruct `AddonRule`.

- [ ] **Update `admin.js`** — Initialise sortable on `.pd-addons-list .wp-list-table tbody` with action `pd_reorder_addon_rules`. Simplest: extract `initRulesSortable` to take a selector + action pair, call twice at document ready.

- [ ] **Manual verify** — Create 3 rules, drag to reorder, toggle status on one, reload — order and status persist.
- [ ] **Commit** — `feat(addon): sortable list + toggle status ajax`

---

## Phase D — 商品編輯頁 Metabox

### Task D1: `AddonProductMetabox`

**Files:**
- Create: `src/Admin/AddonProductMetabox.php`
- Create: `src/Admin/views/addon-metabox.php`

- [ ] **Implement metabox class**

```php
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
        $allRules = $this->rules->findAll();
        $nonce = wp_create_nonce('power_discount_admin');
        require POWER_DISCOUNT_DIR . 'src/Admin/views/addon-metabox.php';
    }
}
```

- [ ] **Create `addon-metabox.php` view** — two lists with checkboxes; each checkbox calls `pd_toggle_addon_metabox_rule` ajax with `rule_id`, `product_id`, `role` (`target` | `addon`), `attach` (0/1).

- [ ] **Implement `AddonAjaxController::toggleMetaboxRule`**

```php
public function toggleMetaboxRule(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    check_ajax_referer('power_discount_admin', 'nonce');

    $ruleId    = isset($_POST['rule_id']) ? (int) $_POST['rule_id'] : 0;
    $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
    $role      = isset($_POST['role']) ? (string) $_POST['role'] : '';
    $attach    = !empty($_POST['attach']);

    $rule = $this->rules->findById($ruleId);
    if ($rule === null || $productId <= 0 || !in_array($role, ['target', 'addon'], true)) {
        wp_send_json_error(['message' => 'Invalid request'], 400);
    }

    // Reconstruct AddonRule with updated target_product_ids or addon_items
    $targets = $rule->getTargetProductIds();
    $items = array_map(static fn ($i) => $i->toArray(), $rule->getAddonItems());

    if ($role === 'target') {
        if ($attach && !in_array($productId, $targets, true)) {
            $targets[] = $productId;
        } elseif (!$attach) {
            $targets = array_values(array_filter($targets, static fn (int $id): bool => $id !== $productId));
        }
    } else {
        // addon role: cannot set special_price via metabox — attach with default 0, detach removes
        if ($attach && !$rule->containsAddon($productId)) {
            $items[] = ['product_id' => $productId, 'special_price' => 0];
        } elseif (!$attach) {
            $items = array_values(array_filter(
                $items,
                static fn ($i) => (int) ($i['product_id'] ?? 0) !== $productId
            ));
        }
    }

    $updated = new \PowerDiscount\Domain\AddonRule([
        'id'                     => $rule->getId(),
        'title'                  => $rule->getTitle(),
        'status'                 => $rule->getStatus(),
        'priority'               => $rule->getPriority(),
        'addon_items'            => $items,
        'target_product_ids'     => $targets,
        'exclude_from_discounts' => $rule->isExcludeFromDiscounts(),
    ]);
    $this->rules->update($updated);
    wp_send_json_success();
}
```

- [ ] **JS in metabox view** — Inline small script that listens to `change` on `.pd-addon-metabox-checkbox`, fires AJAX, shows spinner / success flash.

- [ ] **Manual verify** — Open product edit page, metabox shows. Create a new rule via main page targeting this product. Reload product edit → see it checked under "目標商品". Uncheck → rule updated.
- [ ] **Commit** — `feat(addon): product metabox with bidirectional rule editing`

---

## Phase E — 前台 Widget

### Task E1: `AddonFrontend` + template

**Files:**
- Create: `src/Integration/AddonFrontend.php`
- Create: `assets/frontend/addon.css`

- [ ] **Implement AddonFrontend**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Integration;

use PowerDiscount\Admin\AddonMenu;
use PowerDiscount\Repository\AddonRuleRepository;

final class AddonFrontend
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
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_single_product_summary', [$this, 'renderWidget'], 35);
    }

    public function enqueueAssets(): void
    {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        wp_enqueue_style(
            'power-discount-addon',
            POWER_DISCOUNT_URL . 'assets/frontend/addon.css',
            [],
            POWER_DISCOUNT_VERSION
        );
        wp_enqueue_script(
            'power-discount-addon',
            POWER_DISCOUNT_URL . 'assets/frontend/addon.js',
            ['jquery'],
            POWER_DISCOUNT_VERSION,
            true
        );
    }

    public function renderWidget(): void
    {
        global $product;
        if (!$product || !function_exists('wc_get_product')) {
            return;
        }
        $productId = (int) $product->get_id();
        $rules = $this->rules->findActiveForTarget($productId);
        if ($rules === []) {
            return;
        }

        echo '<div class="pd-addon-section">';
        echo '<h3 class="pd-addon-section-title">' . esc_html__('加價購優惠', 'power-discount') . '</h3>';
        echo '<div class="pd-addon-list">';

        foreach ($rules as $rule) {
            foreach ($rule->getAddonItems() as $item) {
                $addonProduct = wc_get_product($item->getProductId());
                if (!$addonProduct || !$addonProduct->is_purchasable()) {
                    continue;
                }
                $this->renderCard($addonProduct, $item->getSpecialPrice(), $rule->getId());
            }
        }

        echo '</div></div>';
    }

    private function renderCard(\WC_Product $product, float $specialPrice, int $ruleId): void
    {
        $pid = (int) $product->get_id();
        $imgUrl = wp_get_attachment_image_url((int) $product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src();
        $title = $product->get_name();
        $regularPrice = (float) $product->get_regular_price();
        $excerpt = $product->get_short_description() ?: '';
        $content = apply_filters('the_content', $product->get_description());
        ?>
        <label class="pd-addon-card"
               data-product-id="<?php echo $pid; ?>"
               data-rule-id="<?php echo $ruleId; ?>"
               data-special-price="<?php echo esc_attr((string) $specialPrice); ?>">
            <input type="checkbox" name="pd_addon_ids[]" value="<?php echo $pid; ?>">
            <div class="pd-addon-thumb"><img src="<?php echo esc_url($imgUrl); ?>" alt=""></div>
            <div class="pd-addon-info">
                <div class="pd-addon-title"><?php echo esc_html($title); ?></div>
                <div class="pd-addon-price">
                    <?php if ($regularPrice > $specialPrice): ?>
                        <del><?php echo wp_kses_post(wc_price($regularPrice)); ?></del>
                    <?php endif; ?>
                    <strong class="pd-addon-special"><?php echo wp_kses_post(wc_price($specialPrice)); ?></strong>
                </div>
                <button type="button" class="pd-addon-details-btn" data-product-id="<?php echo $pid; ?>">
                    <?php esc_html_e('查看詳細', 'power-discount'); ?>
                </button>
            </div>
        </label>
        <template class="pd-addon-detail" data-product-id="<?php echo $pid; ?>">
            <div class="pd-addon-detail-header">
                <img src="<?php echo esc_url(wp_get_attachment_image_url((int) $product->get_image_id(), 'medium') ?: wc_placeholder_img_src()); ?>" alt="">
                <div>
                    <h3><?php echo esc_html($title); ?></h3>
                    <div class="pd-addon-detail-price">
                        <?php if ($regularPrice > $specialPrice): ?>
                            <del><?php echo wp_kses_post(wc_price($regularPrice)); ?></del>
                        <?php endif; ?>
                        <strong><?php echo wp_kses_post(wc_price($specialPrice)); ?></strong>
                    </div>
                    <div class="pd-addon-detail-excerpt"><?php echo wp_kses_post($excerpt); ?></div>
                </div>
            </div>
            <div class="pd-addon-detail-body"><?php echo wp_kses_post($content); ?></div>
        </template>
        <?php
    }
}
```

- [ ] **Create `addon.css`** — card layout, chip-selected state (reuse `.pd-shipping-chip` visual language), modal overlay + `<dialog>` styling.

- [ ] **Register in `Plugin::boot()`** — after existing frontend components:
  ```php
  (new AddonFrontend($addonRulesRepo))->register();
  ```

- [ ] **Manual verify** — Create a rule targeting a test product, visit the product frontend, widget shows.
- [ ] **Commit** — `feat(addon): frontend widget on product page`

---

### Task E2: Modal + 選擇加購 互動

**Files:**
- Create: `assets/frontend/addon.js`

- [ ] **Implement**

```javascript
(function ($) {
    'use strict';

    function openModal($card) {
        var productId = $card.data('product-id');
        var $template = $card.next('.pd-addon-detail[data-product-id="' + productId + '"]');
        if (!$template.length) return;

        var $dialog = $('<dialog class="pd-addon-modal"></dialog>');
        $dialog.append($template.html());

        // Sticky action button
        var checked = $card.find('input[type="checkbox"]').prop('checked');
        var $action = $('<div class="pd-addon-modal-footer"><button type="button" class="button button-primary pd-addon-confirm"></button></div>');
        $action.find('.pd-addon-confirm').text(checked ? '取消加購' : '選擇加購');
        $dialog.append($action);

        $('body').append($dialog);
        $dialog[0].showModal();

        $dialog.on('click', function (e) {
            if (e.target === $dialog[0]) {
                $dialog[0].close();
            }
        });
        $dialog.find('.pd-addon-confirm').on('click', function () {
            var $checkbox = $card.find('input[type="checkbox"]');
            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
            $dialog[0].close();
        });
        $dialog.on('close', function () {
            $dialog.remove();
        });
    }

    $(document).on('click', '.pd-addon-details-btn', function (e) {
        e.preventDefault();
        var $card = $(this).closest('.pd-addon-card');
        openModal($card);
    });

    $(document).on('change', '.pd-addon-card input[type="checkbox"]', function () {
        $(this).closest('.pd-addon-card').toggleClass('is-selected', this.checked);
    });
})(jQuery);
```

- [ ] **CSS for modal** — sticky footer, max-height 90vh, internal scrolling on body, overlay backdrop. Key rule:

```css
.pd-addon-modal {
    max-width: 640px;
    width: calc(100% - 32px);
    max-height: 90vh;
    padding: 0;
    border: none;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.pd-addon-modal::backdrop {
    background: rgba(0,0,0,0.5);
}
.pd-addon-detail-header { display: flex; gap: 16px; padding: 24px; flex-shrink: 0; }
.pd-addon-detail-header img { width: 200px; height: 200px; object-fit: cover; border-radius: 8px; }
.pd-addon-detail-body { flex: 1 1 auto; overflow-y: auto; padding: 0 24px 24px; }
.pd-addon-modal-footer { position: sticky; bottom: 0; padding: 16px 24px; background: #fff; border-top: 1px solid #e5e7eb; }
.pd-addon-modal-footer .button-primary { width: 100%; padding: 10px 0; font-size: 15px; }
```

- [ ] **Manual verify** — Click card's 查看詳細 button, modal opens with image + title + price + excerpt + scrollable content + sticky button. Click 選擇加購 → card checkbox toggles + modal closes. Re-open → button reads 取消加購.
- [ ] **Commit** — `feat(addon): detail modal + sticky select button`

---

## Phase F — 購物車整合

### Task F1: `AddonCartHandler` — 加入購物車 + 特價覆寫

**Files:**
- Create: `src/Integration/AddonCartHandler.php`

- [ ] **Implement**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Integration;

use PowerDiscount\Admin\AddonMenu;
use PowerDiscount\Repository\AddonRuleRepository;

final class AddonCartHandler
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
        add_action('woocommerce_add_to_cart', [$this, 'onAddToCart'], 10, 6);
        add_action('woocommerce_before_calculate_totals', [$this, 'applySpecialPrices'], 5);
        add_filter('woocommerce_get_item_data', [$this, 'renderCartItemMeta'], 10, 2);
    }

    /** Called after the main product is added. Read $_POST['pd_addon_ids'] and add addons. */
    public function onAddToCart(string $cartItemKey, int $productId, int $quantity, int $variationId, array $variation, array $cartItemData): void
    {
        // Guard against recursion: if we're adding an addon, skip
        if (isset($cartItemData['_pd_addon_from'])) {
            return;
        }
        $addonIds = isset($_POST['pd_addon_ids']) && is_array($_POST['pd_addon_ids'])
            ? array_map('intval', $_POST['pd_addon_ids'])
            : [];
        if ($addonIds === []) {
            return;
        }
        foreach ($addonIds as $addonId) {
            if ($addonId <= 0) continue;
            $rules = $this->rules->findContainingAddon($addonId);
            $specialPrice = null;
            $ruleId = 0;
            $exclude = false;
            foreach ($rules as $rule) {
                if (!$rule->isEnabled() || !$rule->matchesTarget($productId)) {
                    continue;
                }
                $specialPrice = $rule->getSpecialPriceFor($addonId);
                $ruleId = $rule->getId();
                $exclude = $rule->isExcludeFromDiscounts();
                break;
            }
            if ($specialPrice === null) {
                continue;
            }
            WC()->cart->add_to_cart($addonId, 1, 0, [], [
                '_pd_addon_from'                    => $productId,
                '_pd_addon_rule_id'                 => $ruleId,
                '_pd_addon_special_price'           => $specialPrice,
                '_pd_addon_excluded_from_discounts' => $exclude ? 1 : 0,
            ]);
        }
    }

    /** Override prices on every totals calculation. */
    public function applySpecialPrices(\WC_Cart $cart): void
    {
        if (did_action('woocommerce_before_calculate_totals') > 1) {
            // re-entrance safety
        }
        foreach ($cart->get_cart() as $item) {
            if (!isset($item['_pd_addon_special_price'])) continue;
            $price = (float) $item['_pd_addon_special_price'];
            if ($price >= 0 && isset($item['data']) && is_object($item['data'])) {
                $item['data']->set_price($price);
            }
        }
    }

    /** Show a "加購" badge under the item in cart display. */
    public function renderCartItemMeta(array $itemData, array $cartItem): array
    {
        if (!isset($cartItem['_pd_addon_from'])) {
            return $itemData;
        }
        $itemData[] = [
            'key'     => __('加購', 'power-discount'),
            'value'   => '✓',
            'display' => '',
        ];
        return $itemData;
    }
}
```

- [ ] **Register** in `Plugin::boot()`:
  ```php
  (new AddonCartHandler($addonRulesRepo))->register();
  ```

- [ ] **Manual verify** — On a target product page, tick two addons, Add to cart → cart shows main product + 2 addons at special price with 加購 badge.
- [ ] **Commit** — `feat(addon): cart integration with add-to-cart + price override`

---

### Task F2: 折扣引擎排除

**Files:**
- Modify: `src/Integration/CartContextBuilder.php`

- [ ] **Inspect current `CartContextBuilder::build()`** to find the item-iteration point.
- [ ] **Add filter**:

```php
foreach ($cart->get_cart() as $cartItemKey => $cartItem) {
    // Skip addon items that the rule explicitly excluded from the discount engine
    if (!empty($cartItem['_pd_addon_excluded_from_discounts'])) {
        continue;
    }
    // ... existing item processing
}
```

- [ ] **Test manually**:
  - Create an addon rule with `exclude_from_discounts = true`
  - Create a discount rule (e.g. 全站 95 折)
  - Add main product + addon to cart
  - Main product should get 95 折; addon should stay at its special price unchanged
- [ ] **Commit** — `feat(addon): exclude addon items from discount engine when flagged`

---

### Task F3: 鎖定加購 item 數量

**Files:**
- Modify: `src/Integration/AddonCartHandler.php`

- [ ] **Add filter** — lock quantity to 1 and hide quantity input:

```php
add_filter('woocommerce_cart_item_quantity', [$this, 'lockAddonQuantity'], 10, 3);
add_filter('woocommerce_checkout_cart_item_quantity', [$this, 'displayAddonQuantity'], 10, 3);

public function lockAddonQuantity(string $html, string $cartItemKey, array $cartItem): string
{
    if (isset($cartItem['_pd_addon_from'])) {
        return '<span class="pd-addon-qty">1</span>';
    }
    return $html;
}
```

- [ ] **Manual verify** — Cart shows addon with quantity locked to 1, only × remove button works.
- [ ] **Commit** — `feat(addon): lock addon quantity in cart`

---

## Phase G — Polish & Release

### Task G1: 翻譯補完

**Files:**
- Modify: `languages/zh_TW.php`

- [ ] Add all new strings:
  - 加價購, 加價購規則, 新增規則, 停用功能, 啟用加價購功能, 加價購優惠, 查看詳細, 選擇加購, 取消加購, 加購
  - All field labels in edit form and metabox
  - All error messages from `AddonRuleFormMapper`
- [ ] **Commit** — `i18n(addon): add zh_TW translations for addon purchase feature`

---

### Task G2: 測試 + Bug 掃除

- [ ] **Run full test suite** — `vendor/bin/phpunit`. Expected: all pass.
- [ ] **Manual integration test checklist**:
  - Activate / deactivate feature
  - Create / edit / delete rule with multiple addons + targets
  - Drag-reorder rules
  - Toggle rule status from list
  - Product metabox: check / uncheck updates rule in real time
  - Frontend widget: shows on target product only
  - Modal: open / close / confirm / deselect
  - Add main + addons to cart → correct pricing
  - With `exclude_from_discounts = true`: addon stays at special price even when other discount rules apply
  - With flag off: addon gets discounted further
  - Remove addon from cart works
  - Addon quantity locked to 1
  - Deactivate feature → widget hidden, metabox hidden, cart still shows existing addons
- [ ] **Fix any bugs found**, add regression tests if applicable
- [ ] **Commit** — `test(addon): integration pass + bug fixes`

---

### Task G3: 版本 bump + release

**Files:**
- Modify: `power-discount.php` (Version: 1.1.0, POWER_DISCOUNT_VERSION)
- Modify: `readme.txt` (Stable tag + Changelog)

- [ ] **Update changelog**:

```
= 1.1.0 =
* 新功能：加價購（Add-on Purchase）子系統
  - 全新「加價購」選單，支援 opt-in 啟用
  - 可建立加價購規則：一批商品（各自特價）投放到一批目標商品頁面
  - 目標商品的 single product 頁面自動顯示加價購專區
  - 詳細資訊 modal：商品圖、介紹、sticky「選擇加購」按鈕
  - 雙向設定：可在規則頁或個別商品編輯頁調整
  - 可選擇將加價購商品排除於其他折扣規則之外
  - 拖拉調整規則優先順序
  - 購物車自動顯示「加購」標記，數量鎖定為 1
```

- [ ] **Build zip**:
  ```bash
  # standard build pipeline from previous releases
  ```
- [ ] **Tag + push + release**:
  ```bash
  git tag -a v1.1.0 -m "Power Discount 1.1.0 — 加價購"
  git push origin master v1.1.0
  gh release create v1.1.0 power-discount-1.1.0.zip --repo zenbuapps/power-discount --title "Power Discount 1.1.0" --notes-file RELEASE_NOTES.md
  ```
- [ ] **Commit release bump** — `release(1.1.0): 加價購 功能正式上線`

---

## Out of Scope (for this plan)

- 變體商品（variable products）作為加價購 — 只支援 simple
- 加價購的庫存邏輯
- 加價購規則的排程（starts_at / ends_at）
- 報表頁統計加價購數據
- 主題模板覆寫 override
- REST API endpoint（本期全部 server-render）

---

## Total estimated tasks: 16

**Phase breakdown:**
- A (Domain/Persistence): 4 tasks
- B (Admin shell): 2 tasks
- C (Rule edit): 3 tasks
- D (Metabox): 1 task
- E (Frontend): 2 tasks
- F (Cart): 3 tasks
- G (Polish/Release): 1 task

Each task completes independently and can be committed on its own.
