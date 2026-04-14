# Power Discount — Phase 4a: Conditions + Filters + ShippingHooks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 補齊 spec 中所有 Conditions 與 Filters，並讓 `FreeShippingStrategy` 的 shipping sentinel 透過新的 `ShippingHooks` 真正影響 WC 運費。完成後 MVP 的所有「規則邏輯」就都有了，接下來 Phase 4b/4c 只剩 UI 層。

**Architecture:** 沿用 Phase 2 的 `ConditionInterface` / `FilterInterface` + Registry。Conditions 需要 WC runtime 資訊的（payment_method、user_role、first_order 等），用注入 `Closure` 的方式保持 unit-testable。`ShippingHooks` 新增一層 Integration，掛 `woocommerce_package_rates` filter，在 Calculator 跑過後消費 `shippingResults()`。

**Tech Stack:** PHP 7.4+、PHPUnit 9.6

**Phase 定位：**
- Phase 1 ✅ Foundation + Domain + 4 core strategies
- Phase 2 ✅ Repository + Engine + WC Integration
- Phase 3 ✅ 4 Taiwan strategies
- **Phase 4a（本文）** 11 conditions + 4 filters + ShippingHooks
- Phase 4b Admin UI + REST API
- Phase 4c Frontend + Reports

---

## File Structure

新增：

```
src/Condition/
├── CartQuantityCondition.php
├── CartLineItemsCondition.php
├── UserRoleCondition.php
├── UserLoggedInCondition.php
├── DayOfWeekCondition.php
├── TimeOfDayCondition.php
├── PaymentMethodCondition.php
├── ShippingMethodCondition.php
├── FirstOrderCondition.php
├── TotalSpentCondition.php
└── BirthdayMonthCondition.php

src/Filter/
├── ProductsFilter.php
├── TagsFilter.php
├── AttributesFilter.php
└── OnSaleFilter.php

src/Integration/
└── ShippingHooks.php        # 消費 shippingResults() 改 package rates

src/Domain/
└── CartItem.php             # 擴充 tagIds, attributes, onSale fields

tests/Unit/
├── Condition/...  (11 new test files)
├── Filter/...     (4 new test files)
```

修改：
- `src/Domain/CartItem.php` — 加入 `tagIds`、`attributes` (key=>values[])、`onSale` 屬性，constructor 變動
- `src/Domain/CartContext.php` — 可能要加 `getTagIds()` helper（視情況）
- `src/Integration/CartContextBuilder.php` — 從 WC_Product 讀 tag_ids / attributes / is_on_sale 填進 CartItem
- `src/Plugin.php` — 註冊 11 conditions + 4 filters + ShippingHooks，並把 WC runtime 資料的 Closure 注入給 conditions
- 既有 `CartItem`/`CartContext` 測試需要更新為新的 constructor 簽名（PHP 7.4 backwards-compatible 優先：新欄位都加預設值 `[]` / `false`）

---

## Key Design: CartItem 擴充

Phase 1 的 `CartItem` constructor 是：

```php
public function __construct(int $productId, string $name, float $price, int $quantity, array $categoryIds)
```

Phase 4a 要加 `tagIds`、`attributes`、`onSale`。為了不破壞既有測試：

```php
public function __construct(
    int $productId,
    string $name,
    float $price,
    int $quantity,
    array $categoryIds,
    array $tagIds = [],
    array $attributes = [],
    bool $onSale = false
)
```

所有既有呼叫（Phase 1–3 的 test case）都只傳前 5 個參數，新欄位取預設值。

新增 getters：`getTagIds(): array`、`getAttributes(): array`、`isOnSale(): bool`。

`CartItem::isInTags(array $tagIds): bool` — 類似 `isInCategories`。
`CartItem::hasAttribute(string $attribute, array $values): bool` — 檢查指定 attribute 的任一值。

---

## Key Design: Injected Closures for WC-dependent Conditions

有些 conditions 必須讀 WC runtime：

| Condition | Needs | Closure Type |
|---|---|---|
| UserRole | 目前使用者角色 | `(): string[]` |
| UserLoggedIn | 是否登入 | `(): bool` |
| DayOfWeek | 當前時間 | `(): int` (timestamp) |
| TimeOfDay | 當前時間 | `(): int` (timestamp) |
| PaymentMethod | 結帳選的金流 | `(): ?string` |
| ShippingMethod | 結帳選的運送方式 | `(): ?string` |
| FirstOrder | 使用者歷史訂單數 | `(int $userId): int` |
| TotalSpent | 使用者累積消費 | `(int $userId): float` |
| BirthdayMonth | 使用者生日月份 user meta | `(int $userId): ?int` (月份 1-12 或 null) |

每個 class constructor 接受對應的 Closure（可為 null，則用真實 WC 函式的預設）。單元測試傳 stub closure。`Plugin::boot` 建立時注入真實 WC-backed closure。

---

## Ground Rules (同前)

- `<?php declare(strict_types=1);`
- PHP 7.4 相容
- TDD：紅 → 綠 → commit
- 每個 task 獨立 commit
- `git -c user.email=luke@local -c user.name=Luke commit -m "..."`

---

## Tasks

Tasks are bundled aggressively because most conditions/filters are small.

### Task 1: CartItem 擴充 + Backward-compat 測試

**Files:**
- Modify: `src/Domain/CartItem.php`
- Modify: `tests/Unit/Domain/CartContextTest.php` (add one new test for extended fields)

- [ ] **Step 1:** Update `src/Domain/CartItem.php`

Replace the entire file:

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
    /** @var int[] */
    private array $tagIds;
    /** @var array<string, string[]> */
    private array $attributes;
    private bool $onSale;

    /**
     * @param int[] $categoryIds
     * @param int[] $tagIds
     * @param array<string, string[]> $attributes  attribute_name => values[]
     */
    public function __construct(
        int $productId,
        string $name,
        float $price,
        int $quantity,
        array $categoryIds,
        array $tagIds = [],
        array $attributes = [],
        bool $onSale = false
    ) {
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
        $this->tagIds      = array_values(array_map('intval', $tagIds));
        $this->attributes  = $attributes;
        $this->onSale      = $onSale;
    }

    public function getProductId(): int      { return $this->productId; }
    public function getName(): string        { return $this->name; }
    public function getPrice(): float        { return $this->price; }
    public function getQuantity(): int       { return $this->quantity; }
    public function getCategoryIds(): array  { return $this->categoryIds; }
    public function getTagIds(): array       { return $this->tagIds; }
    public function getAttributes(): array   { return $this->attributes; }
    public function isOnSale(): bool         { return $this->onSale; }

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

    public function isInTags(array $tagIds): bool
    {
        foreach ($tagIds as $id) {
            if (in_array((int) $id, $this->tagIds, true)) {
                return true;
            }
        }
        return false;
    }

    public function hasAttribute(string $attribute, array $values): bool
    {
        if (!isset($this->attributes[$attribute])) {
            return false;
        }
        $itemValues = (array) $this->attributes[$attribute];
        foreach ($values as $v) {
            if (in_array((string) $v, $itemValues, true)) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 2:** Add new test to `tests/Unit/Domain/CartContextTest.php` — find the `testCartItemAccessors` method and add new tests after it:

```php
    public function testCartItemExtendedFields(): void
    {
        $item = new CartItem(
            1, 'Widget', 100.0, 1,
            [10],
            [20, 21],
            ['color' => ['red', 'blue'], 'size' => ['L']],
            true
        );
        self::assertSame([20, 21], $item->getTagIds());
        self::assertSame(['color' => ['red', 'blue'], 'size' => ['L']], $item->getAttributes());
        self::assertTrue($item->isOnSale());
    }

    public function testCartItemDefaultsForExtendedFields(): void
    {
        $item = new CartItem(1, 'X', 10.0, 1, []);
        self::assertSame([], $item->getTagIds());
        self::assertSame([], $item->getAttributes());
        self::assertFalse($item->isOnSale());
    }

    public function testIsInTags(): void
    {
        $item = new CartItem(1, 'X', 10.0, 1, [], [5, 6]);
        self::assertTrue($item->isInTags([5]));
        self::assertTrue($item->isInTags([7, 6]));
        self::assertFalse($item->isInTags([99]));
    }

    public function testHasAttribute(): void
    {
        $item = new CartItem(1, 'X', 10.0, 1, [], [], ['color' => ['red', 'blue']]);
        self::assertTrue($item->hasAttribute('color', ['red']));
        self::assertTrue($item->hasAttribute('color', ['green', 'blue']));
        self::assertFalse($item->hasAttribute('color', ['green']));
        self::assertFalse($item->hasAttribute('size', ['L']));
    }
```

- [ ] **Step 3:** Run `vendor/bin/phpunit` — expect 166 + 4 = 170 tests, all green (existing tests still pass because new params are optional).

- [ ] **Step 4:** Commit

```bash
git add src/Domain/CartItem.php tests/Unit/Domain/CartContextTest.php
git commit -m "feat(domain): extend CartItem with tagIds, attributes, onSale (backward-compatible)"
```

---

### Task 2: CartQuantity + CartLineItems conditions (pure cart access)

**Files:**
- Create: `src/Condition/CartQuantityCondition.php`
- Create: `src/Condition/CartLineItemsCondition.php`
- Create: `tests/Unit/Condition/CartQuantityConditionTest.php`
- Create: `tests/Unit/Condition/CartLineItemsConditionTest.php`

These two mirror `CartSubtotalCondition` pattern — operator + value, pure cart access. 3 tests each.

- [ ] **Step 1:** Write `tests/Unit/Condition/CartQuantityConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\CartQuantityCondition;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;

final class CartQuantityConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('cart_quantity', (new CartQuantityCondition())->type());
    }

    public function testGreaterThanOrEqual(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 10.0, 5, [])]);
        $c = new CartQuantityCondition();
        self::assertTrue($c->evaluate(['operator' => '>=', 'value' => 5], $ctx));
        self::assertFalse($c->evaluate(['operator' => '>=', 'value' => 6], $ctx));
    }

    public function testMissingConfigReturnsFalse(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 10.0, 1, [])]);
        self::assertFalse((new CartQuantityCondition())->evaluate([], $ctx));
    }
}
```

- [ ] **Step 2:** Write `tests/Unit/Condition/CartLineItemsConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\CartLineItemsCondition;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;

final class CartLineItemsConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('cart_line_items', (new CartLineItemsCondition())->type());
    }

    public function testLineItemCount(): void
    {
        $ctx = new CartContext([
            new CartItem(1, 'A', 10.0, 5, []),
            new CartItem(2, 'B', 10.0, 3, []),
        ]);
        $c = new CartLineItemsCondition();
        // 2 distinct line items
        self::assertTrue($c->evaluate(['operator' => '=', 'value' => 2], $ctx));
        self::assertFalse($c->evaluate(['operator' => '>', 'value' => 2], $ctx));
    }

    public function testMissingConfigReturnsFalse(): void
    {
        $ctx = new CartContext([new CartItem(1, 'A', 10.0, 1, [])]);
        self::assertFalse((new CartLineItemsCondition())->evaluate([], $ctx));
    }
}
```

- [ ] **Step 3:** Run tests — expect fail.

- [ ] **Step 4:** Implement `src/Condition/CartQuantityCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use PowerDiscount\Domain\CartContext;

final class CartQuantityCondition implements ConditionInterface
{
    public function type(): string
    {
        return 'cart_quantity';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        return Comparator::compare(
            $context->getTotalQuantity(),
            (string) $config['operator'],
            (float) $config['value']
        );
    }
}
```

- [ ] **Step 5:** Implement `src/Condition/CartLineItemsCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use PowerDiscount\Domain\CartContext;

final class CartLineItemsCondition implements ConditionInterface
{
    public function type(): string
    {
        return 'cart_line_items';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        return Comparator::compare(
            count($context->getItems()),
            (string) $config['operator'],
            (float) $config['value']
        );
    }
}
```

Wait — both use a shared `Comparator::compare`. Let me make that a helper class first.

- [ ] **Step 5a:** Create `src/Condition/Comparator.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

final class Comparator
{
    public static function compare(float $left, string $operator, float $right): bool
    {
        switch ($operator) {
            case '>=': return $left >= $right;
            case '>':  return $left >  $right;
            case '=':  return abs($left - $right) < 0.00001;
            case '<=': return $left <= $right;
            case '<':  return $left <  $right;
            case '!=': return abs($left - $right) >= 0.00001;
        }
        return false;
    }
}
```

- [ ] **Step 5b:** Also refactor `src/Condition/CartSubtotalCondition.php` to use the new `Comparator`. Replace its `evaluate` with:

```php
    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        return Comparator::compare(
            $context->getSubtotal(),
            (string) $config['operator'],
            (float) $config['value']
        );
    }
```

Run `vendor/bin/phpunit` — existing CartSubtotal tests still pass.

- [ ] **Step 6:** Re-run conditions tests — expect 6 new passing (3 quantity + 3 line items).

- [ ] **Step 7:** Commit

```bash
git add src/Condition/Comparator.php src/Condition/CartQuantityCondition.php src/Condition/CartLineItemsCondition.php src/Condition/CartSubtotalCondition.php tests/Unit/Condition/
git commit -m "feat(condition): add Comparator + CartQuantity and CartLineItems conditions"
```

Total tests expected after task: 176.

---

### Task 3: UserRole + UserLoggedIn conditions (injected user resolvers)

**Files:**
- Create: `src/Condition/UserRoleCondition.php` + test
- Create: `src/Condition/UserLoggedInCondition.php` + test

Both take a `Closure` in constructor.

- [ ] **Step 1:** `tests/Unit/Condition/UserRoleConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\UserRoleCondition;
use PowerDiscount\Domain\CartContext;

final class UserRoleConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('user_role', (new UserRoleCondition(static function (): array { return []; }))->type());
    }

    public function testMatchesWhenAnyRolePresent(): void
    {
        $c = new UserRoleCondition(static function (): array { return ['customer', 'subscriber']; });
        self::assertTrue($c->evaluate(['roles' => ['customer']], new CartContext([])));
        self::assertTrue($c->evaluate(['roles' => ['admin', 'subscriber']], new CartContext([])));
        self::assertFalse($c->evaluate(['roles' => ['admin']], new CartContext([])));
    }

    public function testEmptyRolesInConfigIsFalse(): void
    {
        $c = new UserRoleCondition(static function (): array { return ['customer']; });
        self::assertFalse($c->evaluate([], new CartContext([])));
        self::assertFalse($c->evaluate(['roles' => []], new CartContext([])));
    }

    public function testGuestHasNoRoles(): void
    {
        $c = new UserRoleCondition(static function (): array { return []; });
        self::assertFalse($c->evaluate(['roles' => ['customer']], new CartContext([])));
    }
}
```

- [ ] **Step 2:** `tests/Unit/Condition/UserLoggedInConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\UserLoggedInCondition;
use PowerDiscount\Domain\CartContext;

final class UserLoggedInConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('user_logged_in', (new UserLoggedInCondition(static function (): bool { return false; }))->type());
    }

    public function testRequireLoggedIn(): void
    {
        $c = new UserLoggedInCondition(static function (): bool { return true; });
        self::assertTrue($c->evaluate(['is_logged_in' => true], new CartContext([])));
        self::assertFalse($c->evaluate(['is_logged_in' => false], new CartContext([])));
    }

    public function testRequireGuest(): void
    {
        $c = new UserLoggedInCondition(static function (): bool { return false; });
        self::assertTrue($c->evaluate(['is_logged_in' => false], new CartContext([])));
        self::assertFalse($c->evaluate(['is_logged_in' => true], new CartContext([])));
    }

    public function testMissingConfigKeyIsFalse(): void
    {
        $c = new UserLoggedInCondition(static function (): bool { return true; });
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
```

- [ ] **Step 3:** Run → fail.

- [ ] **Step 4:** Implement `src/Condition/UserRoleCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class UserRoleCondition implements ConditionInterface
{
    /** @var Closure(): string[] */
    private Closure $getCurrentRoles;

    public function __construct(Closure $getCurrentRoles)
    {
        $this->getCurrentRoles = $getCurrentRoles;
    }

    public function type(): string
    {
        return 'user_role';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        $configRoles = (array) ($config['roles'] ?? []);
        if ($configRoles === []) {
            return false;
        }
        $userRoles = ($this->getCurrentRoles)();
        foreach ($configRoles as $role) {
            if (in_array($role, $userRoles, true)) {
                return true;
            }
        }
        return false;
    }
}
```

- [ ] **Step 5:** Implement `src/Condition/UserLoggedInCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class UserLoggedInCondition implements ConditionInterface
{
    /** @var Closure(): bool */
    private Closure $isLoggedIn;

    public function __construct(Closure $isLoggedIn)
    {
        $this->isLoggedIn = $isLoggedIn;
    }

    public function type(): string
    {
        return 'user_logged_in';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['is_logged_in'])) {
            return false;
        }
        $required = (bool) $config['is_logged_in'];
        $actual = ($this->isLoggedIn)();
        return $required === $actual;
    }
}
```

- [ ] **Step 6:** Re-run → pass (7 new tests). Total: 183.

- [ ] **Step 7:** Commit

```bash
git add src/Condition/UserRoleCondition.php src/Condition/UserLoggedInCondition.php tests/Unit/Condition/UserRoleConditionTest.php tests/Unit/Condition/UserLoggedInConditionTest.php
git commit -m "feat(condition): add UserRole and UserLoggedIn conditions with injected resolvers"
```

---

### Task 4: DayOfWeek + TimeOfDay conditions (injected time resolver)

**Files:**
- Create: `src/Condition/DayOfWeekCondition.php` + test
- Create: `src/Condition/TimeOfDayCondition.php` + test

- [ ] **Step 1:** `tests/Unit/Condition/DayOfWeekConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\DayOfWeekCondition;
use PowerDiscount\Domain\CartContext;

final class DayOfWeekConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('day_of_week', (new DayOfWeekCondition())->type());
    }

    public function testMatchesCurrentDay(): void
    {
        // 2026-04-14 is a Tuesday (ISO 2)
        $now = static function (): int { return strtotime('2026-04-14 12:00:00 UTC'); };
        $c = new DayOfWeekCondition($now);
        self::assertTrue($c->evaluate(['days' => [2]], new CartContext([])));
        self::assertTrue($c->evaluate(['days' => [1, 2, 3]], new CartContext([])));
        self::assertFalse($c->evaluate(['days' => [6, 7]], new CartContext([])));
    }

    public function testEmptyDaysConfigIsFalse(): void
    {
        $c = new DayOfWeekCondition(static function (): int { return time(); });
        self::assertFalse($c->evaluate([], new CartContext([])));
        self::assertFalse($c->evaluate(['days' => []], new CartContext([])));
    }
}
```

- [ ] **Step 2:** `tests/Unit/Condition/TimeOfDayConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\TimeOfDayCondition;
use PowerDiscount\Domain\CartContext;

final class TimeOfDayConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('time_of_day', (new TimeOfDayCondition())->type());
    }

    public function testWithinWindow(): void
    {
        $now = static function (): int { return strtotime('2026-04-14 14:30:00 UTC'); };
        $c = new TimeOfDayCondition($now);
        self::assertTrue($c->evaluate(['from' => '10:00', 'to' => '18:00'], new CartContext([])));
        self::assertFalse($c->evaluate(['from' => '15:00', 'to' => '18:00'], new CartContext([])));
        self::assertFalse($c->evaluate(['from' => '10:00', 'to' => '14:00'], new CartContext([])));
    }

    public function testCrossMidnightWindow(): void
    {
        // Window 22:00–02:00 (night sale)
        $c = new TimeOfDayCondition(static function (): int { return strtotime('2026-04-14 23:00:00 UTC'); });
        self::assertTrue($c->evaluate(['from' => '22:00', 'to' => '02:00'], new CartContext([])));

        $c2 = new TimeOfDayCondition(static function (): int { return strtotime('2026-04-14 01:30:00 UTC'); });
        self::assertTrue($c2->evaluate(['from' => '22:00', 'to' => '02:00'], new CartContext([])));

        $c3 = new TimeOfDayCondition(static function (): int { return strtotime('2026-04-14 15:00:00 UTC'); });
        self::assertFalse($c3->evaluate(['from' => '22:00', 'to' => '02:00'], new CartContext([])));
    }

    public function testMissingConfigKeyIsFalse(): void
    {
        $c = new TimeOfDayCondition(static function (): int { return time(); });
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
```

- [ ] **Step 3:** Run → fail.

- [ ] **Step 4:** Implement `src/Condition/DayOfWeekCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class DayOfWeekCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $now;

    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? static function (): int { return time(); };
    }

    public function type(): string
    {
        return 'day_of_week';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        $days = (array) ($config['days'] ?? []);
        if ($days === []) {
            return false;
        }
        $days = array_map('intval', $days);
        $ts = ($this->now)();
        $today = (int) gmdate('N', $ts); // 1 = Monday, 7 = Sunday
        return in_array($today, $days, true);
    }
}
```

- [ ] **Step 5:** Implement `src/Condition/TimeOfDayCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class TimeOfDayCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $now;

    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? static function (): int { return time(); };
    }

    public function type(): string
    {
        return 'time_of_day';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['from'], $config['to'])) {
            return false;
        }
        $nowMinutes = $this->toMinutes(gmdate('H:i', ($this->now)()));
        $fromMinutes = $this->toMinutes((string) $config['from']);
        $toMinutes = $this->toMinutes((string) $config['to']);

        if ($fromMinutes === null || $toMinutes === null) {
            return false;
        }

        if ($fromMinutes <= $toMinutes) {
            return $nowMinutes >= $fromMinutes && $nowMinutes <= $toMinutes;
        }
        // Cross-midnight window
        return $nowMinutes >= $fromMinutes || $nowMinutes <= $toMinutes;
    }

    private function toMinutes(string $hhmm): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return null;
        }
        return $h * 60 + $min;
    }
}
```

- [ ] **Step 6:** Re-run → pass (7 new tests: 3 day + 4 time). Total: 190.

- [ ] **Step 7:** Commit

```bash
git add src/Condition/DayOfWeekCondition.php src/Condition/TimeOfDayCondition.php tests/Unit/Condition/DayOfWeekConditionTest.php tests/Unit/Condition/TimeOfDayConditionTest.php
git commit -m "feat(condition): add DayOfWeek and TimeOfDay conditions with cross-midnight support"
```

---

### Task 5: PaymentMethod + ShippingMethod conditions

**Files:**
- Create: `src/Condition/PaymentMethodCondition.php` + test
- Create: `src/Condition/ShippingMethodCondition.php` + test

Both follow the same pattern: inject a closure returning `?string`, compare against a list of allowed methods in config.

- [ ] **Step 1:** Tests — `tests/Unit/Condition/PaymentMethodConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\PaymentMethodCondition;
use PowerDiscount\Domain\CartContext;

final class PaymentMethodConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('payment_method', (new PaymentMethodCondition(static function (): ?string { return null; }))->type());
    }

    public function testMatches(): void
    {
        $c = new PaymentMethodCondition(static function (): ?string { return 'ecpay_linepay'; });
        self::assertTrue($c->evaluate(['methods' => ['ecpay_linepay', 'cod']], new CartContext([])));
        self::assertFalse($c->evaluate(['methods' => ['cod']], new CartContext([])));
    }

    public function testEmptyConfig(): void
    {
        $c = new PaymentMethodCondition(static function (): ?string { return 'x'; });
        self::assertFalse($c->evaluate([], new CartContext([])));
        self::assertFalse($c->evaluate(['methods' => []], new CartContext([])));
    }

    public function testNullActiveMethod(): void
    {
        $c = new PaymentMethodCondition(static function (): ?string { return null; });
        self::assertFalse($c->evaluate(['methods' => ['any']], new CartContext([])));
    }
}
```

- [ ] **Step 2:** `tests/Unit/Condition/ShippingMethodConditionTest.php`

Same structure, just with `shipping_method` type and closure name.

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\ShippingMethodCondition;
use PowerDiscount\Domain\CartContext;

final class ShippingMethodConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('shipping_method', (new ShippingMethodCondition(static function (): ?string { return null; }))->type());
    }

    public function testMatches(): void
    {
        $c = new ShippingMethodCondition(static function (): ?string { return 'seven_eleven'; });
        self::assertTrue($c->evaluate(['methods' => ['seven_eleven', 'family_mart']], new CartContext([])));
        self::assertFalse($c->evaluate(['methods' => ['home_delivery']], new CartContext([])));
    }

    public function testEmptyConfig(): void
    {
        $c = new ShippingMethodCondition(static function (): ?string { return 'x'; });
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
```

- [ ] **Step 3:** Run → fail.

- [ ] **Step 4:** Implement `src/Condition/PaymentMethodCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class PaymentMethodCondition implements ConditionInterface
{
    /** @var Closure(): ?string */
    private Closure $getActiveMethod;

    public function __construct(Closure $getActiveMethod)
    {
        $this->getActiveMethod = $getActiveMethod;
    }

    public function type(): string
    {
        return 'payment_method';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        $methods = (array) ($config['methods'] ?? []);
        if ($methods === []) {
            return false;
        }
        $active = ($this->getActiveMethod)();
        if ($active === null) {
            return false;
        }
        return in_array($active, $methods, true);
    }
}
```

- [ ] **Step 5:** Implement `src/Condition/ShippingMethodCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class ShippingMethodCondition implements ConditionInterface
{
    /** @var Closure(): ?string */
    private Closure $getActiveMethod;

    public function __construct(Closure $getActiveMethod)
    {
        $this->getActiveMethod = $getActiveMethod;
    }

    public function type(): string
    {
        return 'shipping_method';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        $methods = (array) ($config['methods'] ?? []);
        if ($methods === []) {
            return false;
        }
        $active = ($this->getActiveMethod)();
        if ($active === null) {
            return false;
        }
        return in_array($active, $methods, true);
    }
}
```

- [ ] **Step 6:** Re-run → pass (7 new: 4 payment + 3 shipping). Total: 197.

- [ ] **Step 7:** Commit

```bash
git add src/Condition/PaymentMethodCondition.php src/Condition/ShippingMethodCondition.php tests/Unit/Condition/PaymentMethodConditionTest.php tests/Unit/Condition/ShippingMethodConditionTest.php
git commit -m "feat(condition): add PaymentMethod and ShippingMethod conditions"
```

---

### Task 6: FirstOrder + TotalSpent + BirthdayMonth conditions

**Files:**
- Create: `src/Condition/FirstOrderCondition.php` + test
- Create: `src/Condition/TotalSpentCondition.php` + test
- Create: `src/Condition/BirthdayMonthCondition.php` + test

All take a closure that receives the current user ID.

- [ ] **Step 1:** `tests/Unit/Condition/FirstOrderConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\FirstOrderCondition;
use PowerDiscount\Domain\CartContext;

final class FirstOrderConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('first_order', (new FirstOrderCondition(
            static function (): int { return 0; },
            static function (int $uid): int { return 0; }
        ))->type());
    }

    public function testGuestTreatedAsFirstOrder(): void
    {
        // Guest user_id = 0 → always treat as first order (design decision in spec)
        $c = new FirstOrderCondition(
            static function (): int { return 0; },
            static function (int $uid): int { return 0; }
        );
        self::assertTrue($c->evaluate(['is_first_order' => true], new CartContext([])));
        self::assertFalse($c->evaluate(['is_first_order' => false], new CartContext([])));
    }

    public function testLoggedInUserFirstOrder(): void
    {
        $c = new FirstOrderCondition(
            static function (): int { return 42; },
            static function (int $uid): int { return $uid === 42 ? 0 : 999; }
        );
        self::assertTrue($c->evaluate(['is_first_order' => true], new CartContext([])));
    }

    public function testLoggedInUserReturningCustomer(): void
    {
        $c = new FirstOrderCondition(
            static function (): int { return 42; },
            static function (int $uid): int { return 5; }
        );
        self::assertTrue($c->evaluate(['is_first_order' => false], new CartContext([])));
        self::assertFalse($c->evaluate(['is_first_order' => true], new CartContext([])));
    }

    public function testMissingConfig(): void
    {
        $c = new FirstOrderCondition(
            static function (): int { return 0; },
            static function (int $uid): int { return 0; }
        );
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
```

- [ ] **Step 2:** `tests/Unit/Condition/TotalSpentConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\TotalSpentCondition;
use PowerDiscount\Domain\CartContext;

final class TotalSpentConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('total_spent', (new TotalSpentCondition(
            static function (): int { return 0; },
            static function (int $uid): float { return 0.0; }
        ))->type());
    }

    public function testGuestAlwaysZero(): void
    {
        $c = new TotalSpentCondition(
            static function (): int { return 0; },
            static function (int $uid): float { return 1000.0; }
        );
        // Guests always treated as 0 total spent
        self::assertFalse($c->evaluate(['operator' => '>=', 'value' => 100], new CartContext([])));
        self::assertTrue($c->evaluate(['operator' => '=', 'value' => 0], new CartContext([])));
    }

    public function testLoggedInTotalSpent(): void
    {
        $c = new TotalSpentCondition(
            static function (): int { return 42; },
            static function (int $uid): float { return 5000.0; }
        );
        self::assertTrue($c->evaluate(['operator' => '>=', 'value' => 1000], new CartContext([])));
        self::assertFalse($c->evaluate(['operator' => '>=', 'value' => 10000], new CartContext([])));
    }

    public function testMissingConfig(): void
    {
        $c = new TotalSpentCondition(
            static function (): int { return 0; },
            static function (int $uid): float { return 0.0; }
        );
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
```

- [ ] **Step 3:** `tests/Unit/Condition/BirthdayMonthConditionTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Condition;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Condition\BirthdayMonthCondition;
use PowerDiscount\Domain\CartContext;

final class BirthdayMonthConditionTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('birthday_month', (new BirthdayMonthCondition(
            static function (): int { return 0; },
            static function (int $uid): ?int { return null; },
            static function (): int { return 0; }
        ))->type());
    }

    public function testMatchesCurrentMonth(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return 4; }, // April
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertTrue($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testDoesNotMatchDifferentMonth(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return 7; }, // July
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertFalse($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testNoBirthdaySet(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return null; },
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertFalse($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testGuestFalse(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 0; },
            static function (int $uid): ?int { return 4; },
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        self::assertFalse($c->evaluate(['match_current_month' => true], new CartContext([])));
    }

    public function testMissingConfig(): void
    {
        $c = new BirthdayMonthCondition(
            static function (): int { return 42; },
            static function (int $uid): ?int { return 4; },
            static function (): int { return strtotime('2026-04-15 UTC'); }
        );
        // Without match_current_month=true, condition is off
        self::assertFalse($c->evaluate([], new CartContext([])));
    }
}
```

- [ ] **Step 4:** Run → fail.

- [ ] **Step 5:** `src/Condition/FirstOrderCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class FirstOrderCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $getCurrentUserId;
    /** @var Closure(int): int */
    private Closure $getUserOrderCount;

    public function __construct(Closure $getCurrentUserId, Closure $getUserOrderCount)
    {
        $this->getCurrentUserId = $getCurrentUserId;
        $this->getUserOrderCount = $getUserOrderCount;
    }

    public function type(): string
    {
        return 'first_order';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['is_first_order'])) {
            return false;
        }
        $required = (bool) $config['is_first_order'];
        $userId = ($this->getCurrentUserId)();

        if ($userId <= 0) {
            // Guest treated as first order = true
            return $required === true;
        }

        $count = ($this->getUserOrderCount)($userId);
        $isFirst = $count === 0;
        return $required === $isFirst;
    }
}
```

- [ ] **Step 6:** `src/Condition/TotalSpentCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class TotalSpentCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $getCurrentUserId;
    /** @var Closure(int): float */
    private Closure $getUserTotalSpent;

    public function __construct(Closure $getCurrentUserId, Closure $getUserTotalSpent)
    {
        $this->getCurrentUserId = $getCurrentUserId;
        $this->getUserTotalSpent = $getUserTotalSpent;
    }

    public function type(): string
    {
        return 'total_spent';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (!isset($config['operator'], $config['value'])) {
            return false;
        }
        $userId = ($this->getCurrentUserId)();
        $spent = $userId > 0 ? ($this->getUserTotalSpent)($userId) : 0.0;
        return Comparator::compare($spent, (string) $config['operator'], (float) $config['value']);
    }
}
```

- [ ] **Step 7:** `src/Condition/BirthdayMonthCondition.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

use Closure;
use PowerDiscount\Domain\CartContext;

final class BirthdayMonthCondition implements ConditionInterface
{
    /** @var Closure(): int */
    private Closure $getCurrentUserId;
    /** @var Closure(int): ?int */
    private Closure $getUserBirthdayMonth;
    /** @var Closure(): int */
    private Closure $now;

    public function __construct(Closure $getCurrentUserId, Closure $getUserBirthdayMonth, Closure $now)
    {
        $this->getCurrentUserId = $getCurrentUserId;
        $this->getUserBirthdayMonth = $getUserBirthdayMonth;
        $this->now = $now;
    }

    public function type(): string
    {
        return 'birthday_month';
    }

    public function evaluate(array $config, CartContext $context): bool
    {
        if (empty($config['match_current_month'])) {
            return false;
        }
        $userId = ($this->getCurrentUserId)();
        if ($userId <= 0) {
            return false;
        }
        $birthdayMonth = ($this->getUserBirthdayMonth)($userId);
        if ($birthdayMonth === null) {
            return false;
        }
        $currentMonth = (int) gmdate('n', ($this->now)());
        return $birthdayMonth === $currentMonth;
    }
}
```

- [ ] **Step 8:** Re-run → pass (14 new: 5 first + 4 total + 5 birthday). Total: 211.

- [ ] **Step 9:** Commit

```bash
git add src/Condition/FirstOrderCondition.php src/Condition/TotalSpentCondition.php src/Condition/BirthdayMonthCondition.php tests/Unit/Condition/
git commit -m "feat(condition): add FirstOrder, TotalSpent, BirthdayMonth with user resolvers"
```

---

### Task 7: Filter Products + Tags + Attributes + OnSale

**Files:**
- Create: `src/Filter/ProductsFilter.php` + test
- Create: `src/Filter/TagsFilter.php` + test
- Create: `src/Filter/AttributesFilter.php` + test
- Create: `src/Filter/OnSaleFilter.php` + test

All 4 are small and follow `CategoriesFilter` pattern.

- [ ] **Step 1:** `tests/Unit/Filter/ProductsFilterTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Filter\ProductsFilter;

final class ProductsFilterTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('products', (new ProductsFilter())->type());
    }

    public function testInList(): void
    {
        $f = new ProductsFilter();
        self::assertTrue($f->matches(['method' => 'in', 'ids' => [1, 2]], new CartItem(1, 'A', 10.0, 1, [])));
        self::assertFalse($f->matches(['method' => 'in', 'ids' => [1, 2]], new CartItem(3, 'C', 10.0, 1, [])));
    }

    public function testNotInList(): void
    {
        $f = new ProductsFilter();
        self::assertTrue($f->matches(['method' => 'not_in', 'ids' => [99]], new CartItem(1, 'A', 10.0, 1, [])));
        self::assertFalse($f->matches(['method' => 'not_in', 'ids' => [99]], new CartItem(99, 'X', 10.0, 1, [])));
    }

    public function testEmptyIdsInNeverMatches(): void
    {
        $f = new ProductsFilter();
        self::assertFalse($f->matches(['method' => 'in', 'ids' => []], new CartItem(1, 'A', 10.0, 1, [])));
    }
}
```

- [ ] **Step 2:** `tests/Unit/Filter/TagsFilterTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Filter\TagsFilter;

final class TagsFilterTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('tags', (new TagsFilter())->type());
    }

    public function testInList(): void
    {
        $f = new TagsFilter();
        $item = new CartItem(1, 'A', 10.0, 1, [], [5, 6]);
        self::assertTrue($f->matches(['method' => 'in', 'ids' => [5]], $item));
        self::assertFalse($f->matches(['method' => 'in', 'ids' => [99]], $item));
    }

    public function testNotInList(): void
    {
        $f = new TagsFilter();
        $item = new CartItem(1, 'A', 10.0, 1, [], [5]);
        self::assertTrue($f->matches(['method' => 'not_in', 'ids' => [99]], $item));
        self::assertFalse($f->matches(['method' => 'not_in', 'ids' => [5]], $item));
    }
}
```

- [ ] **Step 3:** `tests/Unit/Filter/AttributesFilterTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Filter\AttributesFilter;

final class AttributesFilterTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('attributes', (new AttributesFilter())->type());
    }

    public function testMatchesAttribute(): void
    {
        $f = new AttributesFilter();
        $item = new CartItem(1, 'A', 10.0, 1, [], [], ['color' => ['red', 'blue']]);
        self::assertTrue($f->matches(['attribute' => 'color', 'values' => ['red'], 'method' => 'in'], $item));
        self::assertTrue($f->matches(['attribute' => 'color', 'values' => ['green', 'blue'], 'method' => 'in'], $item));
        self::assertFalse($f->matches(['attribute' => 'color', 'values' => ['green'], 'method' => 'in'], $item));
    }

    public function testAttributeMissingFromItem(): void
    {
        $f = new AttributesFilter();
        $item = new CartItem(1, 'A', 10.0, 1, []);
        self::assertFalse($f->matches(['attribute' => 'color', 'values' => ['red'], 'method' => 'in'], $item));
    }

    public function testNotInAttribute(): void
    {
        $f = new AttributesFilter();
        $item = new CartItem(1, 'A', 10.0, 1, [], [], ['size' => ['L']]);
        self::assertTrue($f->matches(['attribute' => 'size', 'values' => ['XL'], 'method' => 'not_in'], $item));
        self::assertFalse($f->matches(['attribute' => 'size', 'values' => ['L'], 'method' => 'not_in'], $item));
    }

    public function testMissingConfig(): void
    {
        $f = new AttributesFilter();
        $item = new CartItem(1, 'A', 10.0, 1, [], [], ['color' => ['red']]);
        self::assertFalse($f->matches([], $item));
    }
}
```

- [ ] **Step 4:** `tests/Unit/Filter/OnSaleFilterTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Filter\OnSaleFilter;

final class OnSaleFilterTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('on_sale', (new OnSaleFilter())->type());
    }

    public function testMatchesItemOnSale(): void
    {
        $f = new OnSaleFilter();
        $onSale = new CartItem(1, 'A', 50.0, 1, [], [], [], true);
        $notOnSale = new CartItem(2, 'B', 100.0, 1, [], [], [], false);

        self::assertTrue($f->matches([], $onSale));
        self::assertFalse($f->matches([], $notOnSale));
    }
}
```

- [ ] **Step 5:** Run → fail.

- [ ] **Step 6:** Implementations.

`src/Filter/ProductsFilter.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

use PowerDiscount\Domain\CartItem;

final class ProductsFilter implements FilterInterface
{
    public function type(): string
    {
        return 'products';
    }

    public function matches(array $config, CartItem $item): bool
    {
        $method = strtolower((string) ($config['method'] ?? 'in'));
        $ids = array_map('intval', (array) ($config['ids'] ?? []));
        $hit = in_array($item->getProductId(), $ids, true);
        return $method === 'not_in' ? !$hit : $hit;
    }
}
```

`src/Filter/TagsFilter.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

use PowerDiscount\Domain\CartItem;

final class TagsFilter implements FilterInterface
{
    public function type(): string
    {
        return 'tags';
    }

    public function matches(array $config, CartItem $item): bool
    {
        $method = strtolower((string) ($config['method'] ?? 'in'));
        $ids = array_map('intval', (array) ($config['ids'] ?? []));
        $hit = $item->isInTags($ids);
        return $method === 'not_in' ? !$hit : $hit;
    }
}
```

`src/Filter/AttributesFilter.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

use PowerDiscount\Domain\CartItem;

final class AttributesFilter implements FilterInterface
{
    public function type(): string
    {
        return 'attributes';
    }

    public function matches(array $config, CartItem $item): bool
    {
        if (!isset($config['attribute'], $config['values'])) {
            return false;
        }
        $method = strtolower((string) ($config['method'] ?? 'in'));
        $attribute = (string) $config['attribute'];
        $values = (array) $config['values'];

        $hit = $item->hasAttribute($attribute, $values);
        return $method === 'not_in' ? !$hit : $hit;
    }
}
```

`src/Filter/OnSaleFilter.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

use PowerDiscount\Domain\CartItem;

final class OnSaleFilter implements FilterInterface
{
    public function type(): string
    {
        return 'on_sale';
    }

    public function matches(array $config, CartItem $item): bool
    {
        return $item->isOnSale();
    }
}
```

- [ ] **Step 7:** Re-run → pass (12 new: 3 products + 3 tags + 5 attributes + 2 on_sale). Total: 223.

- [ ] **Step 8:** Commit

```bash
git add src/Filter/ProductsFilter.php src/Filter/TagsFilter.php src/Filter/AttributesFilter.php src/Filter/OnSaleFilter.php tests/Unit/Filter/
git commit -m "feat(filter): add Products, Tags, Attributes, OnSale filters"
```

---

### Task 8: ShippingHooks real implementation

**Files:**
- Create: `src/Integration/ShippingHooks.php`

`ShippingHooks` hooks into `woocommerce_package_rates` (WC filter). For each shipping rate, check if any active rule's `FreeShippingStrategy` result is present in `shippingResults()`:

- `remove_shipping` → set all rate costs to 0
- `percentage_off_shipping` → reduce each rate cost by N%

Because rates are computed per package per request, we call Calculator directly in this hook (cannot rely on cached CartHooks results — shipping rate calc can happen before cart totals).

No unit tests (WC runtime-dependent). `php -l` must pass.

- [ ] **Step 1:** Create `src/Integration/ShippingHooks.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Integration;

use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Engine\Aggregator;
use PowerDiscount\Engine\Calculator;
use PowerDiscount\Repository\RuleRepository;

final class ShippingHooks
{
    private RuleRepository $rules;
    private Calculator $calculator;
    private Aggregator $aggregator;
    private CartContextBuilder $builder;

    public function __construct(
        RuleRepository $rules,
        Calculator $calculator,
        Aggregator $aggregator,
        CartContextBuilder $builder
    ) {
        $this->rules = $rules;
        $this->calculator = $calculator;
        $this->aggregator = $aggregator;
        $this->builder = $builder;
    }

    public function register(): void
    {
        add_filter('woocommerce_package_rates', [$this, 'filterRates'], 20, 2);
    }

    /**
     * @param array<string, mixed> $rates
     * @param array<string, mixed> $package
     * @return array<string, mixed>
     */
    public function filterRates(array $rates, array $package): array
    {
        if (!function_exists('WC') || WC()->cart === null) {
            return $rates;
        }

        $context = $this->builder->fromWcCart(WC()->cart);
        $activeRules = $this->rules->getActiveRules();
        $results = $this->calculator->run($activeRules, $context);
        $summary = $this->aggregator->aggregate($results);

        $shippingResults = $summary->shippingResults();
        if ($shippingResults === []) {
            return $rates;
        }

        foreach ($shippingResults as $shippingResult) {
            $this->applyShippingResult($rates, $shippingResult);
        }

        return $rates;
    }

    /**
     * @param array<string, mixed> $rates  (passed by reference through parent return)
     */
    private function applyShippingResult(array &$rates, DiscountResult $result): void
    {
        $meta = $result->getMeta();
        $method = (string) ($meta['method'] ?? '');
        $value = (float) ($meta['value'] ?? 0);

        foreach ($rates as $key => $rate) {
            if (!is_object($rate)) {
                continue;
            }
            if (!method_exists($rate, 'get_cost') || !method_exists($rate, 'set_cost')) {
                continue;
            }
            $currentCost = (float) $rate->get_cost();

            if ($method === 'remove_shipping') {
                $rate->set_cost(0.0);
            } elseif ($method === 'percentage_off_shipping') {
                $discount = $currentCost * ($value / 100);
                $rate->set_cost(max(0.0, $currentCost - $discount));
            }
        }
    }
}
```

- [ ] **Step 2:** `php -l src/Integration/ShippingHooks.php`

- [ ] **Step 3:** Full suite still **223 tests**.

- [ ] **Step 4:** Commit

```bash
git add src/Integration/ShippingHooks.php
git commit -m "feat(integration): add ShippingHooks consuming shippingResults to modify WC package rates"
```

---

### Task 9: Plugin::boot wire-up + CartContextBuilder tags/attributes + README + manual verification

**Files:**
- Modify: `src/Plugin.php`
- Modify: `src/Integration/CartContextBuilder.php`
- Modify: `README.md`
- Create: `docs/phase-4a-manual-verification.md`

- [ ] **Step 1:** Extend `src/Integration/CartContextBuilder.php` to populate tag_ids, attributes, onSale.

Find the `fromWcCart` loop where `$categoryIds = []` is initialised. After setting `$categoryIds`, add:

```php
            $tagIds = [];
            $attributes = [];
            $onSale = false;
            if (method_exists($categorySource, 'get_tag_ids')) {
                $tagIds = array_map('intval', (array) $categorySource->get_tag_ids());
            }
            if (method_exists($categorySource, 'get_attributes')) {
                $attrsRaw = $categorySource->get_attributes();
                if (is_array($attrsRaw)) {
                    foreach ($attrsRaw as $attrKey => $attrValue) {
                        // WC attribute objects expose get_options(); variations store plain strings.
                        if (is_object($attrValue) && method_exists($attrValue, 'get_options')) {
                            $attributes[(string) $attrKey] = array_map('strval', (array) $attrValue->get_options());
                        } elseif (is_string($attrValue)) {
                            $attributes[(string) $attrKey] = [$attrValue];
                        }
                    }
                }
            }
            if (method_exists($product, 'is_on_sale')) {
                $onSale = (bool) $product->is_on_sale();
            }
```

Then update the `$items[] = new CartItem(...)` call to include the new args:

```php
            $items[] = new CartItem($productId, $name, $price, $quantity, $categoryIds, $tagIds, $attributes, $onSale);
```

- [ ] **Step 2:** Update `src/Plugin.php::buildConditionRegistry` to register the 11 new conditions with injected closures. Replace the method body (but keep the method signature and apply_filters wrapping):

```php
    private function buildConditionRegistry(): ConditionRegistry
    {
        $registry = new ConditionRegistry();
        $registry->register(new CartSubtotalCondition());
        $registry->register(new CartQuantityCondition());
        $registry->register(new CartLineItemsCondition());
        $registry->register(new DateRangeCondition());
        $registry->register(new DayOfWeekCondition());
        $registry->register(new TimeOfDayCondition());

        $registry->register(new UserRoleCondition(static function (): array {
            if (!function_exists('wp_get_current_user')) {
                return [];
            }
            $user = wp_get_current_user();
            return isset($user->roles) && is_array($user->roles) ? array_map('strval', $user->roles) : [];
        }));

        $registry->register(new UserLoggedInCondition(static function (): bool {
            return function_exists('is_user_logged_in') && is_user_logged_in();
        }));

        $registry->register(new PaymentMethodCondition(static function (): ?string {
            if (!function_exists('WC') || WC()->session === null) {
                return null;
            }
            $chosen = WC()->session->get('chosen_payment_method');
            return is_string($chosen) && $chosen !== '' ? $chosen : null;
        }));

        $registry->register(new ShippingMethodCondition(static function (): ?string {
            if (!function_exists('WC') || WC()->session === null) {
                return null;
            }
            $chosen = WC()->session->get('chosen_shipping_methods');
            if (!is_array($chosen) || $chosen === []) {
                return null;
            }
            $first = reset($chosen);
            return is_string($first) && $first !== '' ? $first : null;
        }));

        $currentUserId = static function (): int {
            return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        };

        $registry->register(new FirstOrderCondition(
            $currentUserId,
            static function (int $uid): int {
                if ($uid <= 0 || !function_exists('wc_get_customer_order_count')) {
                    return 0;
                }
                return (int) wc_get_customer_order_count($uid);
            }
        ));

        $registry->register(new TotalSpentCondition(
            $currentUserId,
            static function (int $uid): float {
                if ($uid <= 0 || !function_exists('wc_get_customer_total_spent')) {
                    return 0.0;
                }
                return (float) wc_get_customer_total_spent($uid);
            }
        ));

        $registry->register(new BirthdayMonthCondition(
            $currentUserId,
            static function (int $uid): ?int {
                if ($uid <= 0 || !function_exists('get_user_meta')) {
                    return null;
                }
                $raw = get_user_meta($uid, 'billing_birthday', true);
                if (!is_string($raw) || $raw === '') {
                    return null;
                }
                if (preg_match('/^(\d{4}-)?(\d{2})-\d{2}$/', $raw, $m)) {
                    $month = (int) $m[2];
                    return ($month >= 1 && $month <= 12) ? $month : null;
                }
                return null;
            },
            static function (): int { return time(); }
        ));

        $registry = apply_filters('power_discount_conditions', $registry);
        if (!$registry instanceof ConditionRegistry) {
            if (function_exists('error_log')) {
                error_log('Power Discount: power_discount_conditions filter returned non-registry type; falling back.');
            }
            return new ConditionRegistry();
        }
        return $registry;
    }
```

Add the imports at the top of Plugin.php:

```php
use PowerDiscount\Condition\BirthdayMonthCondition;
use PowerDiscount\Condition\CartLineItemsCondition;
use PowerDiscount\Condition\CartQuantityCondition;
use PowerDiscount\Condition\DayOfWeekCondition;
use PowerDiscount\Condition\FirstOrderCondition;
use PowerDiscount\Condition\PaymentMethodCondition;
use PowerDiscount\Condition\ShippingMethodCondition;
use PowerDiscount\Condition\TimeOfDayCondition;
use PowerDiscount\Condition\TotalSpentCondition;
use PowerDiscount\Condition\UserLoggedInCondition;
use PowerDiscount\Condition\UserRoleCondition;
```

- [ ] **Step 3:** Update `src/Plugin.php::buildFilterRegistry` to register the 4 new filters:

```php
    private function buildFilterRegistry(): FilterRegistry
    {
        $registry = new FilterRegistry();
        $registry->register(new AllProductsFilter());
        $registry->register(new ProductsFilter());
        $registry->register(new CategoriesFilter());
        $registry->register(new TagsFilter());
        $registry->register(new AttributesFilter());
        $registry->register(new OnSaleFilter());

        $registry = apply_filters('power_discount_filters', $registry);
        if (!$registry instanceof FilterRegistry) {
            if (function_exists('error_log')) {
                error_log('Power Discount: power_discount_filters filter returned non-registry type; falling back.');
            }
            return new FilterRegistry();
        }
        return $registry;
    }
```

Add filter imports:

```php
use PowerDiscount\Filter\AttributesFilter;
use PowerDiscount\Filter\OnSaleFilter;
use PowerDiscount\Filter\ProductsFilter;
use PowerDiscount\Filter\TagsFilter;
```

- [ ] **Step 4:** Update `Plugin::boot` to register `ShippingHooks`. After the existing `CartHooks` and `OrderDiscountLogger` registration:

```php
        (new ShippingHooks($rulesRepo, $calculator, $aggregator, $builder))->register();
```

Add import:

```php
use PowerDiscount\Integration\ShippingHooks;
```

- [ ] **Step 5:** Update `README.md` Status:

```markdown
## Status

**Phase 4a (Conditions + Filters + ShippingHooks)** — complete.

- 13 conditions available: `cart_subtotal`, `cart_quantity`, `cart_line_items`, `date_range`, `day_of_week`, `time_of_day`, `user_role`, `user_logged_in`, `payment_method`, `shipping_method`, `first_order`, `total_spent`, `birthday_month`
- 6 filters available: `all_products`, `products`, `categories`, `tags`, `attributes`, `on_sale`
- ShippingHooks consumes `shippingResults()` to modify WC package rates in real time
- CartContextBuilder populates tagIds, attributes, onSale from WC products

Still pending (Phase 4b/4c): Admin UI (React + WP_List_Table), REST API, Frontend (price table, shipping bar, saved label), Reports.
```

- [ ] **Step 6:** Create `docs/phase-4a-manual-verification.md`:

```markdown
# Phase 4a Manual Verification

## Setup

Activate `power-discount` on a real WP+WC staging site. Ensure at least one product has categories, tags, and variant attributes.

## Conditions

Create a rule requiring LINE Pay payment method + ≥ NT$500 subtotal:

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, config, filters, conditions, created_at, updated_at)
VALUES (
  'LINE Pay -3%',
  'cart',
  1, 10,
  '{"method":"percentage","value":3}',
  '{}',
  '{"logic":"and","items":[{"type":"cart_subtotal","operator":">=","value":500},{"type":"payment_method","methods":["ecpay_linepay"]}]}',
  NOW(), NOW()
);
```

Verify:
- [ ] Cart subtotal < NT$500 → no discount even if LINE Pay selected
- [ ] Cart ≥ NT$500 with COD → no discount
- [ ] Cart ≥ NT$500 with LINE Pay → 3% cart discount applied

## Filters

Rule using tag filter:

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, config, filters, conditions, created_at, updated_at)
VALUES (
  'New arrivals 10%',
  'simple',
  1, 10,
  '{"method":"percentage","value":10}',
  '{"items":[{"type":"tags","method":"in","ids":[NEW_ARRIVAL_TAG_ID]}]}',
  '{}',
  NOW(), NOW()
);
```

Verify:
- [ ] Items tagged "new arrival" get 10% off
- [ ] Other items unaffected

## Free Shipping (ShippingHooks)

Rule removing shipping when subtotal ≥ NT$1000:

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, config, filters, conditions, created_at, updated_at)
VALUES (
  '滿千免運',
  'free_shipping',
  1, 10,
  '{"method":"remove_shipping"}',
  '{}',
  '{"logic":"and","items":[{"type":"cart_subtotal","operator":">=","value":1000}]}',
  NOW(), NOW()
);
```

Verify:
- [ ] Cart below NT$1000 → normal shipping cost shown
- [ ] Cart at NT$1000+ → all shipping options show NT$0
- [ ] Place order → order shipping total is 0; `wp_pd_order_discounts` has a `scope='shipping'` entry

## Known Gaps → Phase 4b/4c

- Admin UI not yet built (all rules via SQL)
- No REST API
- No frontend price table / shipping bar / saved label
- Reports page not built
```

- [ ] **Step 7:** `php -l` all modified files.

- [ ] **Step 8:** `vendor/bin/phpunit` — expect 223 tests still green.

- [ ] **Step 9:** Commit

```bash
git add src/Plugin.php src/Integration/CartContextBuilder.php README.md docs/phase-4a-manual-verification.md
git commit -m "feat: register 11 new conditions, 4 new filters, ShippingHooks; CartContextBuilder populates tags/attributes/onSale"
```

---

## Phase 4a Exit Criteria

- ✅ `vendor/bin/phpunit` ≥ 223 tests green
- ✅ All `.php` files lint clean
- ✅ 13 conditions total, 6 filters total, registered in Plugin::boot
- ✅ ShippingHooks hooked to `woocommerce_package_rates`
- ✅ CartContextBuilder populates extended CartItem fields
- ✅ README updated
- ✅ Manual verification doc committed

## Known Gaps → Phase 4b/4c

- BulkStrategy `per_category` still not implemented (deferred to post-MVP)
- BuyXGetY `cheapest_from_filter` still not implemented (deferred)
- No Admin UI (Phase 4b)
- No REST API (Phase 4b)
- No Frontend (Phase 4c)
- No Reports page (Phase 4c)
