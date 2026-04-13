# Power Discount — Phase 1: Foundation & Core Strategies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建立 `power-discount` WordPress 外掛的可活化骨架，實作純 PHP 的 Domain 值物件，以及 4 個核心折扣 Strategy（Simple / Bulk / Cart / Set）並以 PHPUnit 全數覆蓋。

**Architecture:** Strategy Pattern + Registry。每個折扣類型實作 `DiscountStrategyInterface` 並註冊到 `StrategyRegistry`。Domain 層為純 PHP，不依賴 WooCommerce；Strategy 接受 `CartContext` 回傳 `DiscountResult`，所有行為都可單元測試。

**Tech Stack:** PHP 7.4+、Composer PSR-4、PHPUnit 9.x、WordPress 6.0+、WooCommerce 7.0+（HPOS compatible）

**Phasing:** 本 plan 是 power-discount MVP 的 Phase 1（共 4 個）。

- **Phase 1（本文）**：骨架、Domain、4 個核心 Strategy + 單元測試
- Phase 2：Repository、Conditions、Filters、Engine、WC Cart hooks → 購物車實際套折扣
- Phase 3：Taiwan Strategies（BuyXGetY、NthItem、CrossCategory、FreeShipping）
- Phase 4：Admin UI（React + WP_List_Table）、REST API、Frontend、Reports

---

## File Structure

本 phase 會建立以下檔案：

```
/Users/luke/power-discount/
├── power-discount.php               # Plugin bootstrap
├── composer.json
├── phpunit.xml
├── uninstall.php
├── .gitignore
│
├── src/
│   ├── Plugin.php                   # 啟動器
│   ├── Install/
│   │   ├── Activator.php
│   │   ├── Deactivator.php
│   │   └── Migrator.php             # Schema v1
│   ├── I18n/
│   │   └── Loader.php
│   ├── Domain/
│   │   ├── RuleStatus.php
│   │   ├── Rule.php
│   │   ├── CartItem.php
│   │   ├── CartContext.php
│   │   └── DiscountResult.php
│   └── Strategy/
│       ├── DiscountStrategyInterface.php
│       ├── StrategyRegistry.php
│       ├── SimpleStrategy.php
│       ├── BulkStrategy.php
│       ├── CartStrategy.php
│       └── SetStrategy.php
│
└── tests/
    ├── bootstrap.php
    ├── Unit/
    │   ├── Domain/
    │   │   ├── RuleTest.php
    │   │   ├── CartContextTest.php
    │   │   └── DiscountResultTest.php
    │   └── Strategy/
    │       ├── StrategyRegistryTest.php
    │       ├── SimpleStrategyTest.php
    │       ├── BulkStrategyTest.php
    │       ├── CartStrategyTest.php
    │       └── SetStrategyTest.php
```

**負責分工**：

- `Domain/*`：純值物件，零相依。可用 `new` 直接建構並斷言。
- `Strategy/*`：無狀態、只依賴 `Rule` 與 `CartContext`，每個 strategy 一個 class，一個職責。
- `Install/*`、`I18n/*`、`Plugin.php`：WordPress 整合層，Phase 1 先寫不測（Phase 2 做 integration test）。
- `StrategyRegistry`：對外只有 `register` / `resolve` / `all`，是擴充點。

---

## Convention & Ground Rules

- 所有 PHP 檔首行：`<?php declare(strict_types=1);`
- Namespace：`PowerDiscount\` → `src/`；`PowerDiscount\Tests\` → `tests/`
- 類別使用 typed properties、constructor property promotion 不用（PHP 7.4 相容）
- 不加 DocBlock 除非是 public API 且型別表達不足
- PSR-12 縮排 4 空白
- 每個 Task 最後一個 Step 一定 commit
- Commit message 格式：Conventional Commits（`feat:`、`test:`、`chore:`、`refactor:`）

---

## Tasks

### Task 1: Composer 與 autoload

**Files:**
- Create: `composer.json`
- Create: `.gitignore`

- [ ] **Step 1: 建立 `composer.json`**

```json
{
  "name": "luke/power-discount",
  "description": "WooCommerce discount rules engine - Taiwan-first.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=7.4"
  },
  "require-dev": {
    "phpunit/phpunit": "^9.6"
  },
  "autoload": {
    "psr-4": {
      "PowerDiscount\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "PowerDiscount\\Tests\\": "tests/"
    }
  },
  "config": {
    "sort-packages": true
  }
}
```

- [ ] **Step 2: 建立 `.gitignore`**

```gitignore
/vendor/
/node_modules/
/assets/admin-editor/build/
/.phpunit.result.cache
/composer.lock
.DS_Store
```

- [ ] **Step 3: `composer install`**

Run: `cd /Users/luke/power-discount && composer install`
Expected：產生 `vendor/`、PHPUnit 9.x 可用。

- [ ] **Step 4: Commit**

```bash
git add composer.json .gitignore
git commit -m "chore: add composer config with PSR-4 autoload"
```

---

### Task 2: Plugin 主檔 + HPOS 宣告

**Files:**
- Create: `power-discount.php`

- [ ] **Step 1: 建立 `power-discount.php`**

```php
<?php
/**
 * Plugin Name: Power Discount
 * Plugin URI:  https://github.com/luke/power-discount
 * Description: WooCommerce discount rules engine - Taiwan-first.
 * Version:     0.1.0
 * Author:      Luke
 * License:     GPL-2.0-or-later
 * Text Domain: power-discount
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('POWER_DISCOUNT_VERSION', '0.1.0');
define('POWER_DISCOUNT_FILE', __FILE__);
define('POWER_DISCOUNT_DIR', plugin_dir_path(__FILE__));
define('POWER_DISCOUNT_URL', plugin_dir_url(__FILE__));
define('POWER_DISCOUNT_BASENAME', plugin_basename(__FILE__));

$autoload = POWER_DISCOUNT_DIR . 'vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Power Discount: composer install has not been run.', 'power-discount');
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

register_activation_hook(__FILE__, [\PowerDiscount\Install\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\PowerDiscount\Install\Deactivator::class, 'deactivate']);

add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

add_action('plugins_loaded', static function (): void {
    \PowerDiscount\Plugin::instance()->boot();
}, 5);
```

- [ ] **Step 2: Commit**

```bash
git add power-discount.php
git commit -m "feat: add plugin bootstrap with HPOS declaration"
```

---

### Task 3: Plugin 啟動器

**Files:**
- Create: `src/Plugin.php`

- [ ] **Step 1: 建立 `src/Plugin.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount;

use PowerDiscount\I18n\Loader as I18nLoader;

final class Plugin
{
    private static ?Plugin $instance = null;
    private bool $booted = false;

    public static function instance(): Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        (new I18nLoader())->register();
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Plugin.php
git commit -m "feat: add Plugin bootstrap class"
```

---

### Task 4: Activator / Deactivator

**Files:**
- Create: `src/Install/Activator.php`
- Create: `src/Install/Deactivator.php`

- [ ] **Step 1: 建立 `src/Install/Activator.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Install;

final class Activator
{
    public static function activate(): void
    {
        Migrator::migrate();
        if (get_option('power_discount_installed_at') === false) {
            update_option('power_discount_installed_at', gmdate('Y-m-d H:i:s'), false);
        }
    }
}
```

- [ ] **Step 2: 建立 `src/Install/Deactivator.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Install;

final class Deactivator
{
    public static function deactivate(): void
    {
        // Intentionally empty: keep data on deactivation.
        // Uninstall logic lives in uninstall.php.
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Install/Activator.php src/Install/Deactivator.php
git commit -m "feat: add Activator and Deactivator"
```

---

### Task 5: Migrator + Schema v1

**Files:**
- Create: `src/Install/Migrator.php`

- [ ] **Step 1: 建立 `src/Install/Migrator.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Install;

final class Migrator
{
    private const SCHEMA_VERSION = '1';
    private const OPTION_KEY = 'power_discount_schema_version';

    public static function migrate(): void
    {
        global $wpdb;

        $current = get_option(self::OPTION_KEY, '0');
        if ((string) $current === self::SCHEMA_VERSION) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $rules_table = $wpdb->prefix . 'pd_rules';
        $order_discounts_table = $wpdb->prefix . 'pd_order_discounts';

        $rules_sql = "CREATE TABLE {$rules_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            type VARCHAR(64) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 10,
            exclusive TINYINT(1) NOT NULL DEFAULT 0,
            starts_at DATETIME NULL DEFAULT NULL,
            ends_at DATETIME NULL DEFAULT NULL,
            usage_limit INT NULL DEFAULT NULL,
            used_count INT NOT NULL DEFAULT 0,
            filters LONGTEXT NOT NULL,
            conditions LONGTEXT NOT NULL,
            config LONGTEXT NOT NULL,
            label VARCHAR(255) NULL DEFAULT NULL,
            notes TEXT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_status_priority (status, priority),
            KEY idx_type (type),
            KEY idx_dates (starts_at, ends_at)
        ) {$charset_collate};";

        $order_discounts_sql = "CREATE TABLE {$order_discounts_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            rule_id BIGINT UNSIGNED NOT NULL,
            rule_title VARCHAR(255) NOT NULL,
            rule_type VARCHAR(64) NOT NULL,
            discount_amount DECIMAL(18,4) NOT NULL,
            scope VARCHAR(32) NOT NULL,
            meta LONGTEXT NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_order (order_id),
            KEY idx_rule (rule_id)
        ) {$charset_collate};";

        dbDelta($rules_sql);
        dbDelta($order_discounts_sql);

        update_option(self::OPTION_KEY, self::SCHEMA_VERSION, false);
    }

    public static function currentVersion(): string
    {
        return self::SCHEMA_VERSION;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Install/Migrator.php
git commit -m "feat: add Migrator with schema v1 for pd_rules and pd_order_discounts"
```

---

### Task 6: Uninstaller

**Files:**
- Create: `uninstall.php`

- [ ] **Step 1: 建立 `uninstall.php`**

```php
<?php
declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// MVP policy: keep data by default to avoid destroying historical order records.
// Only remove transient-style options here. "Delete all data" lives in the settings page.
delete_option('power_discount_installed_at');
```

- [ ] **Step 2: Commit**

```bash
git add uninstall.php
git commit -m "feat: add uninstall.php (keep data by default)"
```

---

### Task 7: I18n Loader

**Files:**
- Create: `src/I18n/Loader.php`

- [ ] **Step 1: 建立 `src/I18n/Loader.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\I18n;

final class Loader
{
    public function register(): void
    {
        add_action('init', [$this, 'loadTextDomain']);
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'power-discount',
            false,
            dirname(POWER_DISCOUNT_BASENAME) . '/languages'
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/I18n/Loader.php
git commit -m "feat: add i18n text domain loader"
```

---

### Task 8: PHPUnit baseline

**Files:**
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`
- Create: `tests/Unit/SmokeTest.php`

- [ ] **Step 1: 建立 `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheResultFile=".phpunit.result.cache"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
    failOnRisky="true"
    failOnWarning="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: 建立 `tests/bootstrap.php`**

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
```

- [ ] **Step 3: 寫 smoke test `tests/Unit/SmokeTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testPhpUnitRuns(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 4: 跑測試**

Run: `cd /Users/luke/power-discount && vendor/bin/phpunit`
Expected：`OK (1 test, 1 assertion)`

- [ ] **Step 5: Commit**

```bash
git add phpunit.xml tests/bootstrap.php tests/Unit/SmokeTest.php
git commit -m "test: add phpunit config and smoke test"
```

---

### Task 9: Domain — RuleStatus

**Files:**
- Create: `src/Domain/RuleStatus.php`

- [ ] **Step 1: 建立 `src/Domain/RuleStatus.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

final class RuleStatus
{
    public const DISABLED = 0;
    public const ENABLED = 1;

    public static function isValid(int $status): bool
    {
        return in_array($status, [self::DISABLED, self::ENABLED], true);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Domain/RuleStatus.php
git commit -m "feat: add RuleStatus enum-like constants"
```

---

### Task 10: Domain — Rule 值物件

**Files:**
- Create: `tests/Unit/Domain/RuleTest.php`
- Create: `src/Domain/Rule.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Domain/RuleTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Domain\RuleStatus;

final class RuleTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $rule = new Rule([
            'id' => 42,
            'title' => '任選 2 件 $90',
            'type' => 'set',
            'status' => RuleStatus::ENABLED,
            'priority' => 5,
            'exclusive' => false,
            'config' => ['bundle_size' => 2, 'method' => 'set_price', 'value' => 90],
            'label' => '組合優惠',
        ]);

        self::assertSame(42, $rule->getId());
        self::assertSame('任選 2 件 $90', $rule->getTitle());
        self::assertSame('set', $rule->getType());
        self::assertTrue($rule->isEnabled());
        self::assertFalse($rule->isExclusive());
        self::assertSame(5, $rule->getPriority());
        self::assertSame('組合優惠', $rule->getLabel());
        self::assertSame(90, $rule->getConfig()['value']);
    }

    public function testDefaults(): void
    {
        $rule = new Rule(['title' => 'x', 'type' => 'simple']);

        self::assertSame(0, $rule->getId());
        self::assertSame(RuleStatus::ENABLED, $rule->getStatus());
        self::assertSame(10, $rule->getPriority());
        self::assertFalse($rule->isExclusive());
        self::assertSame([], $rule->getConfig());
        self::assertNull($rule->getLabel());
    }

    public function testIsActiveAtRespectsDateRange(): void
    {
        $rule = new Rule([
            'title' => 't', 'type' => 'simple',
            'starts_at' => '2026-04-01 00:00:00',
            'ends_at'   => '2026-04-30 23:59:59',
        ]);

        self::assertFalse($rule->isActiveAt(strtotime('2026-03-31 23:59:59')));
        self::assertTrue($rule->isActiveAt(strtotime('2026-04-15 12:00:00')));
        self::assertFalse($rule->isActiveAt(strtotime('2026-05-01 00:00:00')));
    }

    public function testIsActiveAtWithNoDateBounds(): void
    {
        $rule = new Rule(['title' => 't', 'type' => 'simple']);
        self::assertTrue($rule->isActiveAt(time()));
    }

    public function testUsageLimitExhausted(): void
    {
        $unlimited = new Rule(['title' => 't', 'type' => 'simple']);
        self::assertFalse($unlimited->isUsageLimitExhausted());

        $capped = new Rule(['title' => 't', 'type' => 'simple', 'usage_limit' => 100, 'used_count' => 100]);
        self::assertTrue($capped->isUsageLimitExhausted());

        $under = new Rule(['title' => 't', 'type' => 'simple', 'usage_limit' => 100, 'used_count' => 99]);
        self::assertFalse($under->isUsageLimitExhausted());
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Domain/RuleTest.php`
Expected：FAIL，`Class PowerDiscount\Domain\Rule not found`。

- [ ] **Step 3: 實作 `src/Domain/Rule.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

final class Rule
{
    private int $id;
    private string $title;
    private string $type;
    private int $status;
    private int $priority;
    private bool $exclusive;
    private ?int $startsAt;
    private ?int $endsAt;
    private ?int $usageLimit;
    private int $usedCount;
    private array $filters;
    private array $conditions;
    private array $config;
    private ?string $label;
    private ?string $notes;

    public function __construct(array $data)
    {
        $this->id         = (int) ($data['id'] ?? 0);
        $this->title      = (string) ($data['title'] ?? '');
        $this->type       = (string) ($data['type'] ?? '');
        $this->status     = (int) ($data['status'] ?? RuleStatus::ENABLED);
        $this->priority   = (int) ($data['priority'] ?? 10);
        $this->exclusive  = (bool) ($data['exclusive'] ?? false);
        $this->startsAt   = self::parseDate($data['starts_at'] ?? null);
        $this->endsAt     = self::parseDate($data['ends_at'] ?? null);
        $this->usageLimit = isset($data['usage_limit']) && $data['usage_limit'] !== null
            ? (int) $data['usage_limit']
            : null;
        $this->usedCount  = (int) ($data['used_count'] ?? 0);
        $this->filters    = (array) ($data['filters'] ?? []);
        $this->conditions = (array) ($data['conditions'] ?? []);
        $this->config     = (array) ($data['config'] ?? []);
        $this->label      = isset($data['label']) && $data['label'] !== '' ? (string) $data['label'] : null;
        $this->notes      = isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null;
    }

    public function getId(): int            { return $this->id; }
    public function getTitle(): string      { return $this->title; }
    public function getType(): string       { return $this->type; }
    public function getStatus(): int        { return $this->status; }
    public function getPriority(): int      { return $this->priority; }
    public function isEnabled(): bool       { return $this->status === RuleStatus::ENABLED; }
    public function isExclusive(): bool     { return $this->exclusive; }
    public function getFilters(): array     { return $this->filters; }
    public function getConditions(): array  { return $this->conditions; }
    public function getConfig(): array      { return $this->config; }
    public function getLabel(): ?string     { return $this->label; }
    public function getNotes(): ?string     { return $this->notes; }
    public function getUsageLimit(): ?int   { return $this->usageLimit; }
    public function getUsedCount(): int     { return $this->usedCount; }

    public function isActiveAt(int $timestamp): bool
    {
        if ($this->startsAt !== null && $timestamp < $this->startsAt) {
            return false;
        }
        if ($this->endsAt !== null && $timestamp > $this->endsAt) {
            return false;
        }
        return true;
    }

    public function isUsageLimitExhausted(): bool
    {
        if ($this->usageLimit === null) {
            return false;
        }
        return $this->usedCount >= $this->usageLimit;
    }

    private static function parseDate($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : $ts;
    }
}
```

- [ ] **Step 4: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Domain/RuleTest.php`
Expected：PASS（5 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Rule.php tests/Unit/Domain/RuleTest.php
git commit -m "feat: add Rule value object with tests"
```

---

### Task 11: Domain — CartItem + CartContext

**Files:**
- Create: `tests/Unit/Domain/CartContextTest.php`
- Create: `src/Domain/CartItem.php`
- Create: `src/Domain/CartContext.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Domain/CartContextTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;

final class CartContextTest extends TestCase
{
    public function testEmptyContext(): void
    {
        $ctx = new CartContext([]);
        self::assertTrue($ctx->isEmpty());
        self::assertSame(0, $ctx->getTotalQuantity());
        self::assertSame(0.0, $ctx->getSubtotal());
        self::assertSame([], $ctx->getItems());
    }

    public function testSubtotalAndQuantity(): void
    {
        $items = [
            new CartItem(101, 'Coffee Beans', 300.0, 2, [12]),
            new CartItem(102, 'Filter', 50.0, 3, [13]),
        ];
        $ctx = new CartContext($items);

        self::assertFalse($ctx->isEmpty());
        self::assertSame(5, $ctx->getTotalQuantity());
        self::assertSame(300.0 * 2 + 50.0 * 3, $ctx->getSubtotal());
        self::assertCount(2, $ctx->getItems());
    }

    public function testGetItemsInCategories(): void
    {
        $items = [
            new CartItem(1, 'A', 100.0, 1, [10, 20]),
            new CartItem(2, 'B', 100.0, 1, [20]),
            new CartItem(3, 'C', 100.0, 1, [30]),
        ];
        $ctx = new CartContext($items);

        $matched = $ctx->getItemsInCategories([20]);
        self::assertCount(2, $matched);

        $none = $ctx->getItemsInCategories([99]);
        self::assertCount(0, $none);
    }

    public function testGetItemsByProductIds(): void
    {
        $items = [
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
        ];
        $ctx = new CartContext($items);

        self::assertCount(1, $ctx->getItemsByProductIds([1]));
        self::assertCount(0, $ctx->getItemsByProductIds([999]));
    }

    public function testCartItemAccessors(): void
    {
        $item = new CartItem(1, 'Widget', 199.5, 3, [5, 6]);
        self::assertSame(1, $item->getProductId());
        self::assertSame('Widget', $item->getName());
        self::assertSame(199.5, $item->getPrice());
        self::assertSame(3, $item->getQuantity());
        self::assertSame([5, 6], $item->getCategoryIds());
        self::assertSame(199.5 * 3, $item->getLineTotal());
    }

    public function testCartItemRejectsNegativeQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CartItem(1, 'X', 10.0, -1, []);
    }

    public function testCartItemRejectsNegativePrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CartItem(1, 'X', -1.0, 1, []);
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Domain/CartContextTest.php`
Expected：FAIL（class not found）

- [ ] **Step 3: 實作 `src/Domain/CartItem.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

use InvalidArgumentException;

final class CartItem
{
    private int $productId;
    private string $name;
    private float $price;
    private int $quantity;
    /** @var int[] */
    private array $categoryIds;

    public function __construct(int $productId, string $name, float $price, int $quantity, array $categoryIds)
    {
        if ($price < 0) {
            throw new InvalidArgumentException('CartItem price cannot be negative.');
        }
        if ($quantity < 0) {
            throw new InvalidArgumentException('CartItem quantity cannot be negative.');
        }
        $this->productId   = $productId;
        $this->name        = $name;
        $this->price       = $price;
        $this->quantity    = $quantity;
        $this->categoryIds = array_values(array_map('intval', $categoryIds));
    }

    public function getProductId(): int      { return $this->productId; }
    public function getName(): string        { return $this->name; }
    public function getPrice(): float        { return $this->price; }
    public function getQuantity(): int       { return $this->quantity; }
    public function getCategoryIds(): array  { return $this->categoryIds; }

    public function getLineTotal(): float
    {
        return $this->price * $this->quantity;
    }

    public function isInCategories(array $categoryIds): bool
    {
        foreach ($categoryIds as $id) {
            if (in_array((int) $id, $this->categoryIds, true)) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 4: 實作 `src/Domain/CartContext.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

final class CartContext
{
    /** @var CartItem[] */
    private array $items;

    public function __construct(array $items)
    {
        foreach ($items as $item) {
            if (!$item instanceof CartItem) {
                throw new \InvalidArgumentException('CartContext only accepts CartItem instances.');
            }
        }
        $this->items = array_values($items);
    }

    /** @return CartItem[] */
    public function getItems(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function getTotalQuantity(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->getQuantity();
        }
        return $total;
    }

    public function getSubtotal(): float
    {
        $subtotal = 0.0;
        foreach ($this->items as $item) {
            $subtotal += $item->getLineTotal();
        }
        return $subtotal;
    }

    /** @return CartItem[] */
    public function getItemsByProductIds(array $productIds): array
    {
        $ids = array_map('intval', $productIds);
        return array_values(array_filter(
            $this->items,
            static fn (CartItem $item): bool => in_array($item->getProductId(), $ids, true)
        ));
    }

    /** @return CartItem[] */
    public function getItemsInCategories(array $categoryIds): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CartItem $item): bool => $item->isInCategories($categoryIds)
        ));
    }
}
```

- [ ] **Step 5: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Domain/CartContextTest.php`
Expected：PASS（7 tests）

- [ ] **Step 6: Commit**

```bash
git add src/Domain/CartItem.php src/Domain/CartContext.php tests/Unit/Domain/CartContextTest.php
git commit -m "feat: add CartItem and CartContext value objects with tests"
```

---

### Task 12: Domain — DiscountResult

**Files:**
- Create: `tests/Unit/Domain/DiscountResultTest.php`
- Create: `src/Domain/DiscountResult.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Domain/DiscountResultTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\DiscountResult;

final class DiscountResultTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $result = new DiscountResult(
            7,
            'simple',
            'product',
            100.0,
            [1, 2],
            '9 折',
            ['note' => 'x']
        );

        self::assertSame(7, $result->getRuleId());
        self::assertSame('simple', $result->getRuleType());
        self::assertSame('product', $result->getScope());
        self::assertSame(100.0, $result->getAmount());
        self::assertSame([1, 2], $result->getAffectedProductIds());
        self::assertSame('9 折', $result->getLabel());
        self::assertSame(['note' => 'x'], $result->getMeta());
        self::assertTrue($result->hasDiscount());
    }

    public function testRejectsInvalidScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DiscountResult(1, 'simple', 'invalid-scope', 10.0, [], null, []);
    }

    public function testRejectsNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DiscountResult(1, 'simple', 'product', -1.0, [], null, []);
    }

    public function testHasDiscountIsFalseForZero(): void
    {
        $r = new DiscountResult(1, 'simple', 'product', 0.0, [], null, []);
        self::assertFalse($r->hasDiscount());
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Domain/DiscountResultTest.php`
Expected：FAIL

- [ ] **Step 3: 實作 `src/Domain/DiscountResult.php`**

PHP 7.4 不支援 named arguments，但測試裡用到了。為了相容，測試寫法保留但檔案也可在 PHP 8+ 執行。實際 class 用普通位置參數：

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

use InvalidArgumentException;

final class DiscountResult
{
    public const SCOPE_PRODUCT  = 'product';
    public const SCOPE_CART     = 'cart';
    public const SCOPE_SHIPPING = 'shipping';

    private const VALID_SCOPES = [
        self::SCOPE_PRODUCT,
        self::SCOPE_CART,
        self::SCOPE_SHIPPING,
    ];

    private int $ruleId;
    private string $ruleType;
    private string $scope;
    private float $amount;
    /** @var int[] */
    private array $affectedProductIds;
    private ?string $label;
    private array $meta;

    public function __construct(
        int $ruleId,
        string $ruleType,
        string $scope,
        float $amount,
        array $affectedProductIds,
        ?string $label,
        array $meta
    ) {
        if (!in_array($scope, self::VALID_SCOPES, true)) {
            throw new InvalidArgumentException(sprintf('Invalid scope: %s', $scope));
        }
        if ($amount < 0) {
            throw new InvalidArgumentException('DiscountResult amount cannot be negative.');
        }
        $this->ruleId             = $ruleId;
        $this->ruleType           = $ruleType;
        $this->scope              = $scope;
        $this->amount             = $amount;
        $this->affectedProductIds = array_values(array_map('intval', $affectedProductIds));
        $this->label              = $label;
        $this->meta               = $meta;
    }

    public function getRuleId(): int                   { return $this->ruleId; }
    public function getRuleType(): string              { return $this->ruleType; }
    public function getScope(): string                 { return $this->scope; }
    public function getAmount(): float                 { return $this->amount; }
    public function getAffectedProductIds(): array     { return $this->affectedProductIds; }
    public function getLabel(): ?string                { return $this->label; }
    public function getMeta(): array                   { return $this->meta; }

    public function hasDiscount(): bool
    {
        return $this->amount > 0;
    }
}
```

- [ ] **Step 4: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Domain/DiscountResultTest.php`
Expected：PASS（4 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Domain/DiscountResult.php tests/Unit/Domain/DiscountResultTest.php
git commit -m "feat: add DiscountResult value object with tests"
```

---

### Task 13: Strategy — Interface

**Files:**
- Create: `src/Strategy/DiscountStrategyInterface.php`

- [ ] **Step 1: 建立 `src/Strategy/DiscountStrategyInterface.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

interface DiscountStrategyInterface
{
    /**
     * Which rule type this strategy handles (e.g. "simple", "bulk").
     */
    public function type(): string;

    /**
     * Compute the discount for the given rule and cart context.
     * Return null if the rule does not apply or yields zero discount.
     */
    public function apply(Rule $rule, CartContext $context): ?DiscountResult;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Strategy/DiscountStrategyInterface.php
git commit -m "feat: add DiscountStrategyInterface"
```

---

### Task 14: Strategy — Registry

**Files:**
- Create: `tests/Unit/Strategy/StrategyRegistryTest.php`
- Create: `src/Strategy/StrategyRegistry.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Strategy/StrategyRegistryTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\DiscountStrategyInterface;
use PowerDiscount\Strategy\StrategyRegistry;

final class StrategyRegistryTest extends TestCase
{
    public function testRegisterAndResolve(): void
    {
        $registry = new StrategyRegistry();
        $stub = $this->makeStub('simple');

        $registry->register($stub);

        self::assertSame($stub, $registry->resolve('simple'));
        self::assertNull($registry->resolve('bulk'));
    }

    public function testAllReturnsAllRegistered(): void
    {
        $registry = new StrategyRegistry();
        $registry->register($this->makeStub('simple'));
        $registry->register($this->makeStub('bulk'));

        self::assertCount(2, $registry->all());
    }

    public function testRegisterOverridesExistingType(): void
    {
        $registry = new StrategyRegistry();
        $first  = $this->makeStub('simple');
        $second = $this->makeStub('simple');

        $registry->register($first);
        $registry->register($second);

        self::assertSame($second, $registry->resolve('simple'));
        self::assertCount(1, $registry->all());
    }

    private function makeStub(string $type): DiscountStrategyInterface
    {
        return new class($type) implements DiscountStrategyInterface {
            private string $type;
            public function __construct(string $type) { $this->type = $type; }
            public function type(): string { return $this->type; }
            public function apply(Rule $rule, CartContext $context): ?DiscountResult { return null; }
        };
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Strategy/StrategyRegistryTest.php`
Expected：FAIL

- [ ] **Step 3: 實作 `src/Strategy/StrategyRegistry.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

final class StrategyRegistry
{
    /** @var array<string, DiscountStrategyInterface> */
    private array $strategies = [];

    public function register(DiscountStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->type()] = $strategy;
    }

    public function resolve(string $type): ?DiscountStrategyInterface
    {
        return $this->strategies[$type] ?? null;
    }

    /** @return DiscountStrategyInterface[] */
    public function all(): array
    {
        return array_values($this->strategies);
    }
}
```

- [ ] **Step 4: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Strategy/StrategyRegistryTest.php`
Expected：PASS（3 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Strategy/StrategyRegistry.php tests/Unit/Strategy/StrategyRegistryTest.php
git commit -m "feat: add StrategyRegistry with tests"
```

---

### Task 15: SimpleStrategy

**Files:**
- Create: `tests/Unit/Strategy/SimpleStrategyTest.php`
- Create: `src/Strategy/SimpleStrategy.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Strategy/SimpleStrategyTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\SimpleStrategy;

final class SimpleStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('simple', (new SimpleStrategy())->type());
    }

    public function testPercentageDiscount(): void
    {
        $rule = $this->makeRule(['method' => 'percentage', 'value' => 10]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);

        $result = (new SimpleStrategy())->apply($rule, $ctx);

        self::assertNotNull($result);
        self::assertSame('product', $result->getScope());
        self::assertSame(20.0, $result->getAmount()); // 100 * 0.10 * 2
        self::assertSame([1], $result->getAffectedProductIds());
    }

    public function testFlatDiscountCappedAtPrice(): void
    {
        $rule = $this->makeRule(['method' => 'flat', 'value' => 150]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $result = (new SimpleStrategy())->apply($rule, $ctx);

        self::assertNotNull($result);
        self::assertSame(100.0, $result->getAmount()); // capped
    }

    public function testFixedPriceReducesToTarget(): void
    {
        $rule = $this->makeRule(['method' => 'fixed_price', 'value' => 80]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);

        $result = (new SimpleStrategy())->apply($rule, $ctx);

        self::assertNotNull($result);
        self::assertSame(60.0, $result->getAmount()); // (100-80) * 3
    }

    public function testFixedPriceHigherThanCurrentYieldsNoDiscount(): void
    {
        $rule = $this->makeRule(['method' => 'fixed_price', 'value' => 200]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);

        $result = (new SimpleStrategy())->apply($rule, $ctx);

        self::assertNull($result);
    }

    public function testMultipleItemsAggregated(): void
    {
        $rule = $this->makeRule(['method' => 'percentage', 'value' => 50]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 200.0, 2, []),
        ]);

        $result = (new SimpleStrategy())->apply($rule, $ctx);

        self::assertNotNull($result);
        self::assertSame(50.0 + 200.0, $result->getAmount());
        self::assertSame([1, 2], $result->getAffectedProductIds());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->makeRule(['method' => 'percentage', 'value' => 10]);
        self::assertNull((new SimpleStrategy())->apply($rule, new CartContext([])));
    }

    public function testInvalidMethodReturnsNull(): void
    {
        $rule = $this->makeRule(['method' => 'nonsense', 'value' => 10]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);
        self::assertNull((new SimpleStrategy())->apply($rule, $ctx));
    }

    public function testZeroValueReturnsNull(): void
    {
        $rule = $this->makeRule(['method' => 'percentage', 'value' => 0]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);
        self::assertNull((new SimpleStrategy())->apply($rule, $ctx));
    }

    private function makeRule(array $config): Rule
    {
        return new Rule([
            'id' => 1,
            'title' => 'Test',
            'type' => 'simple',
            'config' => $config,
        ]);
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Strategy/SimpleStrategyTest.php`
Expected：FAIL

- [ ] **Step 3: 實作 `src/Strategy/SimpleStrategy.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class SimpleStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'simple';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $method = (string) ($config['method'] ?? '');
        $value  = (float) ($config['value'] ?? 0);

        if (!in_array($method, ['percentage', 'flat', 'fixed_price'], true)) {
            return null;
        }
        if ($value <= 0) {
            return null;
        }

        $totalDiscount = 0.0;
        $affected = [];

        foreach ($context->getItems() as $item) {
            $perItem = $this->perItemDiscount($method, $value, $item);
            if ($perItem > 0) {
                $totalDiscount += $perItem * $item->getQuantity();
                $affected[] = $item->getProductId();
            }
        }

        if ($totalDiscount <= 0) {
            return null;
        }

        return new DiscountResult(
            $rule->getId(),
            $rule->getType(),
            DiscountResult::SCOPE_PRODUCT,
            $totalDiscount,
            $affected,
            $rule->getLabel(),
            []
        );
    }

    private function perItemDiscount(string $method, float $value, CartItem $item): float
    {
        $price = $item->getPrice();
        switch ($method) {
            case 'percentage':
                return $price * ($value / 100);
            case 'flat':
                return min($price, $value);
            case 'fixed_price':
                return $price > $value ? $price - $value : 0.0;
        }
        return 0.0;
    }
}
```

- [ ] **Step 4: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Strategy/SimpleStrategyTest.php`
Expected：PASS（9 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Strategy/SimpleStrategy.php tests/Unit/Strategy/SimpleStrategyTest.php
git commit -m "feat: add SimpleStrategy with tests"
```

---

### Task 16: BulkStrategy

**Files:**
- Create: `tests/Unit/Strategy/BulkStrategyTest.php`
- Create: `src/Strategy/BulkStrategy.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Strategy/BulkStrategyTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\BulkStrategy;

final class BulkStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('bulk', (new BulkStrategy())->type());
    }

    public function testCumulativePercentageBelowFirstRangeYieldsNothing(): void
    {
        $rule = $this->rule([
            'count_scope' => 'cumulative',
            'ranges' => [
                ['from' => 5, 'to' => 9, 'method' => 'percentage', 'value' => 10],
            ],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 4, [])]);

        self::assertNull((new BulkStrategy())->apply($rule, $ctx));
    }

    public function testCumulativePercentageAppliesToAllMatched(): void
    {
        $rule = $this->rule([
            'count_scope' => 'cumulative',
            'ranges' => [
                ['from' => 1, 'to' => 4,    'method' => 'percentage', 'value' => 0],
                ['from' => 5, 'to' => 9,    'method' => 'percentage', 'value' => 10],
                ['from' => 10, 'to' => null, 'method' => 'percentage', 'value' => 20],
            ],
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 3, []),
            new CartItem(2, 'B', 200.0, 2, []),
        ]);
        // total qty = 5 → 10% off everything
        $result = (new BulkStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        // 100*3*0.1 + 200*2*0.1 = 30 + 40 = 70
        self::assertSame(70.0, $result->getAmount());
    }

    public function testCumulativeFlatPerItem(): void
    {
        $rule = $this->rule([
            'count_scope' => 'cumulative',
            'ranges' => [
                ['from' => 10, 'to' => null, 'method' => 'flat', 'value' => 5],
            ],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 10, [])]);

        $result = (new BulkStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(50.0, $result->getAmount()); // 5 * 10
    }

    public function testOpenEndedUpperBound(): void
    {
        $rule = $this->rule([
            'count_scope' => 'cumulative',
            'ranges' => [
                ['from' => 10, 'to' => null, 'method' => 'percentage', 'value' => 20],
            ],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 100, [])]);
        $result = (new BulkStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(100.0 * 100 * 0.2, $result->getAmount());
    }

    public function testPerItemScopeUsesPerLineQuantity(): void
    {
        $rule = $this->rule([
            'count_scope' => 'per_item',
            'ranges' => [
                ['from' => 3, 'to' => null, 'method' => 'percentage', 'value' => 10],
            ],
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 3, []), // qualifies
            new CartItem(2, 'B', 100.0, 2, []), // doesn't
        ]);

        $result = (new BulkStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(30.0, $result->getAmount()); // only A gets 10% of 100*3
        self::assertSame([1], $result->getAffectedProductIds());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule([
            'count_scope' => 'cumulative',
            'ranges' => [['from' => 1, 'to' => null, 'method' => 'percentage', 'value' => 10]],
        ]);
        self::assertNull((new BulkStrategy())->apply($rule, new CartContext([])));
    }

    public function testMissingRangesReturnsNull(): void
    {
        $rule = $this->rule(['count_scope' => 'cumulative', 'ranges' => []]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 5, [])]);
        self::assertNull((new BulkStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'bulk', 'config' => $config]);
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Strategy/BulkStrategyTest.php`
Expected：FAIL

- [ ] **Step 3: 實作 `src/Strategy/BulkStrategy.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class BulkStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'bulk';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $scope  = (string) ($config['count_scope'] ?? 'cumulative');
        $ranges = (array) ($config['ranges'] ?? []);

        if ($ranges === []) {
            return null;
        }

        $totalDiscount = 0.0;
        $affected = [];

        if ($scope === 'per_item') {
            foreach ($context->getItems() as $item) {
                $range = $this->findRange($ranges, $item->getQuantity());
                if ($range === null) {
                    continue;
                }
                $d = $this->calculateForItem($range, $item, $item->getQuantity());
                if ($d > 0) {
                    $totalDiscount += $d;
                    $affected[] = $item->getProductId();
                }
            }
        } else {
            // cumulative
            $totalQty = $context->getTotalQuantity();
            $range = $this->findRange($ranges, $totalQty);
            if ($range !== null) {
                foreach ($context->getItems() as $item) {
                    $d = $this->calculateForItem($range, $item, $item->getQuantity());
                    if ($d > 0) {
                        $totalDiscount += $d;
                        $affected[] = $item->getProductId();
                    }
                }
            }
        }

        if ($totalDiscount <= 0) {
            return null;
        }

        return new DiscountResult(
            $rule->getId(),
            $rule->getType(),
            DiscountResult::SCOPE_PRODUCT,
            $totalDiscount,
            $affected,
            $rule->getLabel(),
            []
        );
    }

    /** @return array{from:int,to:?int,method:string,value:float}|null */
    private function findRange(array $ranges, int $qty): ?array
    {
        foreach ($ranges as $r) {
            $from = (int) ($r['from'] ?? 0);
            $to   = isset($r['to']) && $r['to'] !== null ? (int) $r['to'] : null;
            if ($qty >= $from && ($to === null || $qty <= $to)) {
                $method = (string) ($r['method'] ?? '');
                $value  = (float) ($r['value'] ?? 0);
                if ($value <= 0 || !in_array($method, ['percentage', 'flat'], true)) {
                    return null;
                }
                return ['from' => $from, 'to' => $to, 'method' => $method, 'value' => $value];
            }
        }
        return null;
    }

    private function calculateForItem(array $range, CartItem $item, int $qtyCount): float
    {
        $price = $item->getPrice();
        if ($range['method'] === 'percentage') {
            return $price * ($range['value'] / 100) * $qtyCount;
        }
        // flat per unit, capped at price
        return min($price, $range['value']) * $qtyCount;
    }
}
```

- [ ] **Step 4: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Strategy/BulkStrategyTest.php`
Expected：PASS（8 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Strategy/BulkStrategy.php tests/Unit/Strategy/BulkStrategyTest.php
git commit -m "feat: add BulkStrategy with tests"
```

---

### Task 17: CartStrategy

**Files:**
- Create: `tests/Unit/Strategy/CartStrategyTest.php`
- Create: `src/Strategy/CartStrategy.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Strategy/CartStrategyTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\CartStrategy;

final class CartStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('cart', (new CartStrategy())->type());
    }

    public function testPercentageOfSubtotal(): void
    {
        $rule = $this->rule(['method' => 'percentage', 'value' => 10]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 500.0, 1, []),
            new CartItem(2, 'B', 500.0, 1, []),
        ]);

        $result = (new CartStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame('cart', $result->getScope());
        self::assertSame(100.0, $result->getAmount());
    }

    public function testFlatTotal(): void
    {
        $rule = $this->rule(['method' => 'flat_total', 'value' => 100]);
        $ctx = new CartContext([new CartItem(1, 'A', 1000.0, 1, [])]);
        $result = (new CartStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testFlatTotalCappedAtSubtotal(): void
    {
        $rule = $this->rule(['method' => 'flat_total', 'value' => 5000]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);
        $result = (new CartStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testFlatPerItemAggregatesAcrossLines(): void
    {
        $rule = $this->rule(['method' => 'flat_per_item', 'value' => 10]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 2, []),
            new CartItem(2, 'B', 100.0, 3, []),
        ]);
        // 10 per unit * 5 units = 50
        $result = (new CartStrategy())->apply($rule, $ctx);
        self::assertSame(50.0, $result->getAmount());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule(['method' => 'percentage', 'value' => 10]);
        self::assertNull((new CartStrategy())->apply($rule, new CartContext([])));
    }

    public function testZeroValueReturnsNull(): void
    {
        $rule = $this->rule(['method' => 'percentage', 'value' => 0]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);
        self::assertNull((new CartStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'cart', 'config' => $config]);
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Strategy/CartStrategyTest.php`
Expected：FAIL

- [ ] **Step 3: 實作 `src/Strategy/CartStrategy.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class CartStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'cart';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $method = (string) ($config['method'] ?? '');
        $value  = (float) ($config['value'] ?? 0);
        if ($value <= 0) {
            return null;
        }

        $subtotal = $context->getSubtotal();
        if ($subtotal <= 0) {
            return null;
        }

        $amount = 0.0;
        switch ($method) {
            case 'percentage':
                $amount = $subtotal * ($value / 100);
                break;
            case 'flat_total':
                $amount = min($value, $subtotal);
                break;
            case 'flat_per_item':
                $amount = $value * $context->getTotalQuantity();
                $amount = min($amount, $subtotal);
                break;
            default:
                return null;
        }

        if ($amount <= 0) {
            return null;
        }

        $affected = array_map(
            static fn ($item): int => $item->getProductId(),
            $context->getItems()
        );

        return new DiscountResult(
            $rule->getId(),
            $rule->getType(),
            DiscountResult::SCOPE_CART,
            $amount,
            $affected,
            $rule->getLabel(),
            ['method' => $method]
        );
    }
}
```

- [ ] **Step 4: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Strategy/CartStrategyTest.php`
Expected：PASS（7 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Strategy/CartStrategy.php tests/Unit/Strategy/CartStrategyTest.php
git commit -m "feat: add CartStrategy with tests"
```

---

### Task 18: SetStrategy（含 Taiwan flat_off）

**Files:**
- Create: `tests/Unit/Strategy/SetStrategyTest.php`
- Create: `src/Strategy/SetStrategy.php`

- [ ] **Step 1: 寫失敗測試 `tests/Unit/Strategy/SetStrategyTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\SetStrategy;

final class SetStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('set', (new SetStrategy())->type());
    }

    public function testSetPriceAnyTwoForNinety(): void
    {
        // 任選 2 件 $90
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90, 'repeat' => true]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 60.0, 1, []),
            new CartItem(2, 'B', 60.0, 1, []),
        ]);
        // Original bundle total: 60 + 60 = 120. Set price 90. Discount = 30.
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testSetPriceRepeatsWhenPossible(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90, 'repeat' => true]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 60.0, 4, []),
        ]);
        // 4 items = 2 bundles. Each bundle: original 120, set 90, discount 30. Total = 60.
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(60.0, $result->getAmount());
    }

    public function testSetPriceNoRepeatOnlyOneBundle(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 60.0, 4, []),
        ]);
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testSetPercentageThreeFor90Percent(): void
    {
        // 任選 3 件 9 折
        $rule = $this->rule(['bundle_size' => 3, 'method' => 'set_percentage', 'value' => 10, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
            new CartItem(3, 'C', 100.0, 1, []),
        ]);
        // bundle total 300 * 10% = 30
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testSetFlatOffFourForHundredOff(): void
    {
        // 任選 4 件現折 $100  (WDR 做不到)
        $rule = $this->rule(['bundle_size' => 4, 'method' => 'set_flat_off', 'value' => 100, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 200.0, 4, []),
        ]);
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testSetFlatOffRepeat(): void
    {
        $rule = $this->rule(['bundle_size' => 4, 'method' => 'set_flat_off', 'value' => 100, 'repeat' => true]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 200.0, 8, []),
        ]);
        // 2 bundles × $100 = $200
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(200.0, $result->getAmount());
    }

    public function testInsufficientItemsReturnsNull(): void
    {
        $rule = $this->rule(['bundle_size' => 3, 'method' => 'set_price', 'value' => 90]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        self::assertNull((new SetStrategy())->apply($rule, $ctx));
    }

    public function testSetPriceHigherThanBundleYieldsNull(): void
    {
        // Set price is MORE expensive than natural bundle price: no discount
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 500]);
        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 1, []),
            new CartItem(2, 'B', 100.0, 1, []),
        ]);
        self::assertNull((new SetStrategy())->apply($rule, $ctx));
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 90]);
        self::assertNull((new SetStrategy())->apply($rule, new CartContext([])));
    }

    public function testPicksMostExpensiveItemsForBundle(): void
    {
        // To maximise customer savings, the bundle pulls the MOST EXPENSIVE units first.
        $rule = $this->rule(['bundle_size' => 2, 'method' => 'set_price', 'value' => 100, 'repeat' => false]);
        $ctx = new CartContext([
            new CartItem(1, 'Cheap', 50.0, 1, []),
            new CartItem(2, 'Mid', 100.0, 1, []),
            new CartItem(3, 'Premium', 200.0, 1, []),
        ]);
        // Pick Premium + Mid → 300 - 100 = 200 discount
        $result = (new SetStrategy())->apply($rule, $ctx);
        self::assertSame(200.0, $result->getAmount());
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'set', 'config' => $config]);
    }
}
```

- [ ] **Step 2: 跑測試看失敗**

Run: `vendor/bin/phpunit tests/Unit/Strategy/SetStrategyTest.php`
Expected：FAIL

- [ ] **Step 3: 實作 `src/Strategy/SetStrategy.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class SetStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'set';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $bundleSize = (int) ($config['bundle_size'] ?? 0);
        $method = (string) ($config['method'] ?? '');
        $value = (float) ($config['value'] ?? 0);
        $repeat = (bool) ($config['repeat'] ?? false);

        if ($bundleSize <= 0 || $value < 0) {
            return null;
        }
        if (!in_array($method, ['set_price', 'set_percentage', 'set_flat_off'], true)) {
            return null;
        }

        // Expand into a flat list of (product_id, unit_price), sorted by price desc
        // so bundles pull the most expensive units first (maximises savings).
        $units = [];
        foreach ($context->getItems() as $item) {
            for ($i = 0; $i < $item->getQuantity(); $i++) {
                $units[] = [
                    'product_id' => $item->getProductId(),
                    'price'      => $item->getPrice(),
                ];
            }
        }
        if (count($units) < $bundleSize) {
            return null;
        }
        usort($units, static fn (array $a, array $b): int => $b['price'] <=> $a['price']);

        $bundleCount = intdiv(count($units), $bundleSize);
        if (!$repeat) {
            $bundleCount = min(1, $bundleCount);
        }
        if ($bundleCount <= 0) {
            return null;
        }

        $totalDiscount = 0.0;
        $affected = [];
        for ($b = 0; $b < $bundleCount; $b++) {
            $bundleUnits = array_slice($units, $b * $bundleSize, $bundleSize);
            $bundleTotal = 0.0;
            foreach ($bundleUnits as $u) {
                $bundleTotal += $u['price'];
                $affected[$u['product_id']] = true;
            }
            $bundleDiscount = $this->bundleDiscount($method, $value, $bundleTotal);
            if ($bundleDiscount > 0) {
                $totalDiscount += $bundleDiscount;
            }
        }

        if ($totalDiscount <= 0) {
            return null;
        }

        return new DiscountResult(
            $rule->getId(),
            $rule->getType(),
            DiscountResult::SCOPE_PRODUCT,
            $totalDiscount,
            array_keys($affected),
            $rule->getLabel(),
            ['method' => $method, 'bundle_size' => $bundleSize]
        );
    }

    private function bundleDiscount(string $method, float $value, float $bundleTotal): float
    {
        switch ($method) {
            case 'set_price':
                // Discount = max(0, current total - fixed set price)
                return $bundleTotal > $value ? $bundleTotal - $value : 0.0;
            case 'set_percentage':
                // value is percent off (e.g. 10 = 10% off bundle)
                return $bundleTotal * ($value / 100);
            case 'set_flat_off':
                return min($value, $bundleTotal);
        }
        return 0.0;
    }
}
```

- [ ] **Step 4: 再跑測試**

Run: `vendor/bin/phpunit tests/Unit/Strategy/SetStrategyTest.php`
Expected：PASS（10 tests）

- [ ] **Step 5: Commit**

```bash
git add src/Strategy/SetStrategy.php tests/Unit/Strategy/SetStrategyTest.php
git commit -m "feat: add SetStrategy with tests (including Taiwan set_flat_off)"
```

---

### Task 19: 端對端 Registry + 4 Strategies 整合測試

**Files:**
- Create: `tests/Unit/Strategy/RegistryIntegrationTest.php`

- [ ] **Step 1: 寫測試 `tests/Unit/Strategy/RegistryIntegrationTest.php`**

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\BulkStrategy;
use PowerDiscount\Strategy\CartStrategy;
use PowerDiscount\Strategy\SetStrategy;
use PowerDiscount\Strategy\SimpleStrategy;
use PowerDiscount\Strategy\StrategyRegistry;

final class RegistryIntegrationTest extends TestCase
{
    public function testAllFourStrategiesResolveAndApply(): void
    {
        $registry = new StrategyRegistry();
        $registry->register(new SimpleStrategy());
        $registry->register(new BulkStrategy());
        $registry->register(new CartStrategy());
        $registry->register(new SetStrategy());

        self::assertCount(4, $registry->all());

        $ctx = new CartContext([
            new CartItem(1, 'A', 100.0, 2, []),
            new CartItem(2, 'B', 200.0, 3, []),
        ]);

        // simple 10%
        $simple = $registry->resolve('simple')->apply(
            new Rule(['id' => 1, 'type' => 'simple', 'title' => 's', 'config' => ['method' => 'percentage', 'value' => 10]]),
            $ctx
        );
        self::assertNotNull($simple);

        // bulk cumulative: qty=5 → 10% off
        $bulk = $registry->resolve('bulk')->apply(
            new Rule([
                'id' => 2, 'type' => 'bulk', 'title' => 'b',
                'config' => [
                    'count_scope' => 'cumulative',
                    'ranges' => [
                        ['from' => 5, 'to' => null, 'method' => 'percentage', 'value' => 10],
                    ],
                ],
            ]),
            $ctx
        );
        self::assertNotNull($bulk);

        // cart flat_total
        $cart = $registry->resolve('cart')->apply(
            new Rule(['id' => 3, 'type' => 'cart', 'title' => 'c', 'config' => ['method' => 'flat_total', 'value' => 100]]),
            $ctx
        );
        self::assertNotNull($cart);
        self::assertSame('cart', $cart->getScope());

        // set: bundle 2 @ 100 with repeat
        $set = $registry->resolve('set')->apply(
            new Rule([
                'id' => 4, 'type' => 'set', 'title' => 't',
                'config' => ['bundle_size' => 2, 'method' => 'set_price', 'value' => 300, 'repeat' => true],
            ]),
            $ctx
        );
        self::assertNotNull($set);
    }
}
```

- [ ] **Step 2: 跑全部測試**

Run: `vendor/bin/phpunit`
Expected：所有測試 PASS，總數 ≈ 45 tests。

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Strategy/RegistryIntegrationTest.php
git commit -m "test: add registry + 4 core strategies integration test"
```

---

### Task 20: Phase 1 收尾 — README 與文件

**Files:**
- Create: `README.md`

- [ ] **Step 1: 建立 `README.md`**

````markdown
# Power Discount

WooCommerce discount rules engine — Taiwan-first.

## Status

**Phase 1 (Foundation + Core Strategies)** — in progress.

- Schema v1 for `wp_pd_rules` and `wp_pd_order_discounts`
- Domain value objects (`Rule`, `CartContext`, `CartItem`, `DiscountResult`)
- 4 core strategies: Simple / Bulk / Cart / Set
- Full PHPUnit coverage for domain + strategies

Not yet wired to WooCommerce cart hooks (Phase 2).

## Requirements

- PHP 7.4+
- WordPress 6.0+
- WooCommerce 7.0+ (HPOS compatible)

## Development

```bash
composer install
vendor/bin/phpunit
```

## Architecture

See `docs/superpowers/specs/2026-04-14-power-discount-design.md`.

## License

GPL-2.0-or-later
````

- [ ] **Step 2: 跑完整測試確認全綠**

Run: `vendor/bin/phpunit`
Expected：全數 PASS

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: add README for phase 1"
```

- [ ] **Step 4: 驗證 Phase 1 完成**

手動檢查清單：

- [ ] `composer install` 成功
- [ ] `vendor/bin/phpunit` 全部 PASS
- [ ] 沒有 linter 錯誤（`php -l` 每個 src 檔）：`find src -name '*.php' -exec php -l {} \;`
- [ ] git log 顯示 ~20 個小 commit，按 task 切分

---

## Phase 1 Exit Criteria

- ✅ 所有 src 檔 `php -l` 通過
- ✅ PHPUnit 全綠，至少 40+ tests
- ✅ Activator / Migrator 寫好但**尚未被 WC 執行**（Phase 2 會在真 WC 環境測）
- ✅ 4 個核心 Strategy 各自可獨立執行並產出正確 `DiscountResult`
- ✅ StrategyRegistry 可註冊、解析、列舉 strategies
- ✅ Domain 層 0 相依 WC / WP functions

---

## Known Gaps → Phase 2

- Repository 的實作還沒接 `wpdb`（只有 DB schema）
- Conditions、Filters 尚未實作
- Calculator（主流程）未實作
- WC cart hook 未接
- Admin UI 未做

Phase 2 開始時應先跑 `vendor/bin/phpunit` 確認 Phase 1 綠燈。
