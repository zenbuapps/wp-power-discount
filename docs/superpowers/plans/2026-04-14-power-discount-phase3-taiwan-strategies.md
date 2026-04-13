# Power Discount — Phase 3: Taiwan Strategies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新增 4 個 Taiwan 電商特有的折扣 Strategy — `BuyXGetY`、`NthItem`（第 N 件 X 折）、`CrossCategory`（紅配綠）、`FreeShipping` — 並全部註冊到 Engine。

**Architecture:** 沿用 Phase 1 的 Strategy + Registry。每個 Strategy 實作 `DiscountStrategyInterface`，透過 `StrategyRegistry` 註冊並由 `Calculator` 呼叫。所有策略為純 PHP，不依賴 WC。

**Tech Stack:** PHP 7.4+、PHPUnit 9.6

**Phase 定位：**

- Phase 1 ✅ Foundation + Domain + 4 core strategies
- Phase 2 ✅ Repository + Engine + WC Integration（含所有 critical fix）
- **Phase 3（本文）** Taiwan Strategies × 4
- Phase 4 剩餘 11 conditions + 4 filters + Admin UI + REST API + Frontend

---

## File Structure

本 phase 新增：

```
src/Strategy/
├── BuyXGetYStrategy.php        # 買 X 送 Y（target: same | specific | cheapest_in_cart）
├── NthItemStrategy.php         # 第 N 件 X 折
├── CrossCategoryStrategy.php   # 紅配綠（多類別組合折扣）
└── FreeShippingStrategy.php    # 免運 / 折抵運費

tests/Unit/Strategy/
├── BuyXGetYStrategyTest.php
├── NthItemStrategyTest.php
├── CrossCategoryStrategyTest.php
└── FreeShippingStrategyTest.php
```

修改：`src/Plugin.php` — 在 `buildStrategyRegistry()` 註冊 4 個新 Strategy。

## Scope Decisions

為了控制 Phase 3 的範圍與複雜度，明確的包含/排除：

**BuyXGetY 的 `reward.target` 只實作**：
- `same` — 送同一商品
- `specific` — 送指定商品 ID 清單中的某個
- `cheapest_in_cart` — 送購物車中最便宜的商品

排除 `cheapest_from_filter`（要先跑 filter，Phase 4 再做）。

**CrossCategory 的 `apply_to`**：
- `bundle` — 折扣套在整組（所有 group 的商品）

排除 `specific_group` 的 target_group_index 邏輯（Phase 4 再擴充）。

**FreeShipping 的 `method`**：
- `remove_shipping` — 條件達成即免運
- `percentage_off_shipping` — 折扣運費 N%

---

## Ground Rules

- PHP 7.4 相容（無 enum / readonly / named args）
- 每個 Strategy 有 `<?php declare(strict_types=1);`
- TDD：紅 → 綠 → commit
- 每個 Strategy 及其 test 獨立 commit
- 使用 `git -c user.email=luke@local -c user.name=Luke commit -m "..."`
- 所有 Strategy 的 config 存在 `$rule->getConfig()` JSON；Strategy 負責 validate + parse

---

## Tasks

### Task 1: BuyXGetYStrategy (TDD)

**Files:**
- Create: `tests/Unit/Strategy/BuyXGetYStrategyTest.php`
- Create: `src/Strategy/BuyXGetYStrategy.php`

### Spec reminder

Config schema:
```json
{
  "trigger": {"source": "filter | specific", "qty": 2, "product_ids": [1,2]},
  "reward": {
    "target": "same | specific | cheapest_in_cart",
    "qty": 1,
    "method": "free | percentage | flat",
    "value": 100,
    "product_ids": [5]
  },
  "recursive": true
}
```

**Semantics:**
- `trigger.qty` = 要買到的數量。`trigger.source`: `filter` 表示「cart 內已經被 rule-level filter 過的任何商品都算數」，`specific` 表示「只有 `trigger.product_ids` 裡的商品算」。
- `reward.qty` = 送幾個。
- `reward.target`:
  - `same` → 贈品與 trigger 同商品（從 trigger 那批商品中挑最貴 `reward.qty` 個）
  - `specific` → 贈品必須是 `reward.product_ids` 裡面的商品，且這些商品**已經在購物車中**，選其中最貴 `reward.qty` 個
  - `cheapest_in_cart` → 從整個購物車選最便宜 `reward.qty` 個（可以任何商品）
- `reward.method`:
  - `free` → 贈品金額 = 贈品單價 × reward.qty（100% off）
  - `percentage` → 贈品折扣 = 單價 × (value / 100) × reward.qty（value 是折扣百分比，例如 50 表示半價）
  - `flat` → 贈品折扣 = min(單價, value) × reward.qty
- `recursive`:
  - `false` → 最多套一組
  - `true` → 以 (trigger.qty + reward.qty) 為一個「回合」，cart 內能重複組成幾回合就套幾次

**Edge cases:**
- trigger qty 不足 → null
- reward 找不到可送的商品 → null
- 同一件商品可能同時是 trigger 與 reward，不要重複計算（扣掉已作為 trigger 的 unit）

### Test — `tests/Unit/Strategy/BuyXGetYStrategyTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\BuyXGetYStrategy;

final class BuyXGetYStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('buy_x_get_y', (new BuyXGetYStrategy())->type());
    }

    public function testBuyOneGetOneSameFree(): void
    {
        // Buy 1 of product 1, get 1 of product 1 free
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        // 2 units in cart: 1 is trigger, 1 is reward. Reward = 1 × 100 × 100% = 100
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testBuyTwoGetOneSameHalfOff(): void
    {
        // Buy 2, get 1 at 50% off — same product
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 2],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'percentage', 'value' => 50],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 200.0, 3, [])]);
        // 3 units: 2 trigger + 1 reward. Discount = 200 * 50% * 1 = 100
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testRecursive(): void
    {
        // Buy 1 Get 1 Free, recursive
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => true,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 4, [])]);
        // 4 units = 2 rounds × (1 trigger + 1 reward). Total free = 2 × 100 = 200
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(200.0, $result->getAmount());
    }

    public function testNonRecursiveCaps(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 10, [])]);
        // Non-recursive: at most 1 free
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testInsufficientTriggerQty(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 3],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);
        // Only 3 units → 3 trigger, 0 reward available → null
        self::assertNull((new BuyXGetYStrategy())->apply($rule, $ctx));
    }

    public function testSpecificTriggerSource(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'specific', 'qty' => 1, 'product_ids' => [10]],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(10, 'Target', 100.0, 2, []),
            new CartItem(20, 'Other',  200.0, 5, []),
        ]);
        // Only product 10 qualifies. 2 units: 1 trigger + 1 reward = 100 off.
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testSpecificRewardTarget(): void
    {
        // Buy any 1 → get product 99 free (product 99 must already be in cart)
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => [
                'target' => 'specific', 'qty' => 1, 'method' => 'free', 'value' => 0,
                'product_ids' => [99],
            ],
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Any', 300.0, 1, []),
            new CartItem(99, 'Free gift', 50.0, 1, []),
        ]);
        // Discount = 50
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(50.0, $result->getAmount());
    }

    public function testCheapestInCart(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 2],
            'reward'  => ['target' => 'cheapest_in_cart', 'qty' => 1, 'method' => 'free', 'value' => 0],
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Cheap', 50.0, 1, []),
            new CartItem(2, 'Expensive', 500.0, 2, []),
        ]);
        // 3 units total. 2 are trigger (pick highest 2 — product 2 two units: 1000). 1 reward = cheapest remaining.
        // After taking 2 trigger units (best = 2 × 500 = 1000), cheapest remaining is product 1 at 50.
        // Reward 1 × 50 × 100% = 50 off
        $result = (new BuyXGetYStrategy())->apply($rule, $ctx);
        self::assertSame(50.0, $result->getAmount());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 1],
            'reward'  => ['target' => 'same', 'qty' => 1, 'method' => 'free', 'value' => 0],
        ]);
        self::assertNull((new BuyXGetYStrategy())->apply($rule, new CartContext([])));
    }

    public function testInvalidConfigReturnsNull(): void
    {
        $rule = $this->rule([
            'trigger' => ['source' => 'filter', 'qty' => 0],
            'reward'  => ['target' => 'same', 'qty' => 0, 'method' => 'free'],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 5, [])]);
        self::assertNull((new BuyXGetYStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'buy_x_get_y', 'config' => $config]);
    }
}
```

### Implementation — `src/Strategy/BuyXGetYStrategy.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class BuyXGetYStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'buy_x_get_y';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $trigger = (array) ($config['trigger'] ?? []);
        $reward = (array) ($config['reward'] ?? []);
        $recursive = (bool) ($config['recursive'] ?? false);

        $triggerQty = (int) ($trigger['qty'] ?? 0);
        $rewardQty = (int) ($reward['qty'] ?? 0);
        if ($triggerQty <= 0 || $rewardQty <= 0) {
            return null;
        }

        $triggerSource = (string) ($trigger['source'] ?? 'filter');
        $triggerProductIds = array_map('intval', (array) ($trigger['product_ids'] ?? []));
        $rewardTarget = (string) ($reward['target'] ?? 'same');
        $rewardProductIds = array_map('intval', (array) ($reward['product_ids'] ?? []));
        $rewardMethod = (string) ($reward['method'] ?? 'free');
        $rewardValue = (float) ($reward['value'] ?? 0);

        // Flatten cart into units of (product_id, price), sorted by price desc.
        $allUnits = $this->flattenUnits($context);
        if ($allUnits === []) {
            return null;
        }

        // Identify trigger-eligible units.
        $triggerEligible = $this->filterEligibleTrigger($allUnits, $triggerSource, $triggerProductIds);
        if (count($triggerEligible) < $triggerQty) {
            return null;
        }

        $rounds = $recursive ? PHP_INT_MAX : 1;
        $totalDiscount = 0.0;
        $affected = [];

        // $remaining is a running pool of "units still available". We pull
        // trigger units out, then reward units, and repeat for recursive mode.
        $remaining = $allUnits;
        usort($remaining, static function (array $a, array $b): int {
            return $b['price'] <=> $a['price'];
        });

        for ($round = 0; $round < $rounds; $round++) {
            // Take triggerQty most-expensive trigger-eligible units.
            $takenTriggerKeys = [];
            $triggerProductIdsTaken = [];
            $takenCount = 0;
            foreach ($remaining as $key => $unit) {
                if ($takenCount >= $triggerQty) {
                    break;
                }
                if (!$this->isTriggerEligible($unit, $triggerSource, $triggerProductIds)) {
                    continue;
                }
                $takenTriggerKeys[] = $key;
                $triggerProductIdsTaken[$unit['product_id']] = true;
                $takenCount++;
            }
            if ($takenCount < $triggerQty) {
                break;
            }
            // Remove trigger units from remaining.
            foreach ($takenTriggerKeys as $k) {
                unset($remaining[$k]);
            }
            $remaining = array_values($remaining);

            // Pick reward units (selection depends on reward.target).
            $rewardUnits = $this->pickRewardUnits(
                $remaining,
                $rewardTarget,
                $rewardProductIds,
                array_keys($triggerProductIdsTaken),
                $rewardQty
            );
            if (count($rewardUnits) < $rewardQty) {
                break;
            }

            // Compute discount on reward units.
            foreach ($rewardUnits as $ru) {
                $totalDiscount += $this->rewardDiscount($ru['price'], $rewardMethod, $rewardValue);
                $affected[$ru['product_id']] = true;
                // Remove that reward from remaining to prevent re-use.
                foreach ($remaining as $rk => $runit) {
                    if ($runit === $ru) {
                        unset($remaining[$rk]);
                        break;
                    }
                }
            }
            $remaining = array_values($remaining);
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
            ['reward_target' => $rewardTarget, 'reward_method' => $rewardMethod]
        );
    }

    /**
     * @return array<int, array{product_id:int,price:float}>
     */
    private function flattenUnits(CartContext $context): array
    {
        $units = [];
        foreach ($context->getItems() as $item) {
            for ($i = 0; $i < $item->getQuantity(); $i++) {
                $units[] = ['product_id' => $item->getProductId(), 'price' => $item->getPrice()];
            }
        }
        return $units;
    }

    /**
     * @param array<int, array{product_id:int,price:float}> $units
     * @return array<int, array{product_id:int,price:float}>
     */
    private function filterEligibleTrigger(array $units, string $source, array $productIds): array
    {
        return array_values(array_filter(
            $units,
            function (array $u) use ($source, $productIds): bool {
                return $this->isTriggerEligible($u, $source, $productIds);
            }
        ));
    }

    /**
     * @param array{product_id:int,price:float} $unit
     */
    private function isTriggerEligible(array $unit, string $source, array $productIds): bool
    {
        if ($source === 'specific') {
            return in_array($unit['product_id'], $productIds, true);
        }
        return true; // 'filter' source: rule's own filter already narrowed the context
    }

    /**
     * @param array<int, array{product_id:int,price:float}> $remaining
     * @param array<int, int> $triggerProductIdsTaken  product_ids of units consumed as triggers this round
     * @return array<int, array{product_id:int,price:float}>
     */
    private function pickRewardUnits(
        array $remaining,
        string $target,
        array $rewardProductIds,
        array $triggerProductIdsTaken,
        int $rewardQty
    ): array {
        if ($remaining === []) {
            return [];
        }

        if ($target === 'specific') {
            $candidates = array_values(array_filter(
                $remaining,
                static function (array $u) use ($rewardProductIds): bool {
                    return in_array($u['product_id'], $rewardProductIds, true);
                }
            ));
            // Highest-priced reward first (max customer savings when free).
            usort($candidates, static function (array $a, array $b): int {
                return $b['price'] <=> $a['price'];
            });
            return array_slice($candidates, 0, $rewardQty);
        }

        if ($target === 'cheapest_in_cart') {
            $candidates = $remaining;
            usort($candidates, static function (array $a, array $b): int {
                return $a['price'] <=> $b['price'];
            });
            return array_slice($candidates, 0, $rewardQty);
        }

        // 'same': reward must share a product_id with one of the trigger units just consumed.
        $triggerIdSet = array_flip(array_map('intval', $triggerProductIdsTaken));
        $candidates = array_values(array_filter(
            $remaining,
            static function (array $u) use ($triggerIdSet): bool {
                return isset($triggerIdSet[$u['product_id']]);
            }
        ));
        usort($candidates, static function (array $a, array $b): int {
            return $b['price'] <=> $a['price'];
        });
        return array_slice($candidates, 0, $rewardQty);
    }

    private function rewardDiscount(float $unitPrice, string $method, float $value): float
    {
        switch ($method) {
            case 'free':
                return $unitPrice;
            case 'percentage':
                return $unitPrice * ($value / 100);
            case 'flat':
                return min($unitPrice, $value);
        }
        return 0.0;
    }
}
```

**Note:** The `same` target tracks trigger-unit product_ids as the trigger loop pulls units, then the reward selector filters `$remaining` to only those product_ids. This avoids any reliance on positional keys in `$allUnits` (which would break after `usort`).

### Verification

Run `vendor/bin/phpunit tests/Unit/Strategy/BuyXGetYStrategyTest.php` — expect 11 passes.

Run full suite — total goes from 126 to **137**.

Commit:
```bash
git add src/Strategy/BuyXGetYStrategy.php tests/Unit/Strategy/BuyXGetYStrategyTest.php
git commit -m "feat: add BuyXGetYStrategy with tests (same/specific/cheapest_in_cart targets)"
```

---

### Task 2: NthItemStrategy (TDD) — 第 N 件 X 折

**Files:**
- Create: `tests/Unit/Strategy/NthItemStrategyTest.php`
- Create: `src/Strategy/NthItemStrategy.php`

### Config

```json
{
  "tiers": [
    {"nth": 1, "method": "percentage", "value": 0},
    {"nth": 2, "method": "percentage", "value": 40},
    {"nth": 3, "method": "percentage", "value": 50}
  ],
  "sort_by": "price_desc | price_asc",
  "recursive": true
}
```

**Semantics:**
- Flatten cart into units, sort by `sort_by`.
- Iterate sorted units; the i-th (1-indexed) unit gets the tier whose `nth` matches `((i-1) mod K) + 1` if `recursive` is `true` (K = max nth defined).
- If `recursive` is `false`, units beyond the max `nth` receive the last tier.
- Tier methods: `percentage` (value = % off), `flat` (value = $ off, capped at unit price), `free` (100% off — optional, treat percentage 100).

### Test — `tests/Unit/Strategy/NthItemStrategyTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\NthItemStrategy;

final class NthItemStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('nth_item', (new NthItemStrategy())->type());
    }

    public function testSecondItemHalfOffOneProduct(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 200.0, 2, [])]);
        // 1st: 0% off; 2nd: 50% off → 100
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testThreeTiers(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 40],
                ['nth' => 3, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);
        // 1st 0%, 2nd 40%, 3rd 50% → 0 + 40 + 50 = 90
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(90.0, $result->getAmount());
    }

    public function testBeyondMaxTierUsesLastWhenNotRecursive(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 4, [])]);
        // units 1 2 3 4 → 0% 50% 50% 50% = 0 + 50 + 50 + 50 = 150
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(150.0, $result->getAmount());
    }

    public function testRecursive(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 50],
            ],
            'sort_by' => 'price_desc',
            'recursive' => true,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 4, [])]);
        // Cycle 2: units 1 2 3 4 → 0% 50% 0% 50% = 0 + 50 + 0 + 50 = 100
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testSortByPriceAsc(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'percentage', 'value' => 100],
            ],
            'sort_by' => 'price_asc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Cheap', 50.0, 1, []),
            new CartItem(2, 'Expensive', 500.0, 1, []),
        ]);
        // ASC: cheapest (50) = tier 1 (0%), expensive (500) = tier 2 (100%) → 500 off
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(500.0, $result->getAmount());
    }

    public function testFlatMethod(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
                ['nth' => 2, 'method' => 'flat', 'value' => 30],
            ],
            'sort_by' => 'price_desc',
            'recursive' => false,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        // 1st 0, 2nd flat $30 = 30 off
        $result = (new NthItemStrategy())->apply($rule, $ctx);
        self::assertSame(30.0, $result->getAmount());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule([
            'tiers' => [['nth' => 1, 'method' => 'percentage', 'value' => 10]],
            'sort_by' => 'price_desc',
        ]);
        self::assertNull((new NthItemStrategy())->apply($rule, new CartContext([])));
    }

    public function testEmptyTiersReturnsNull(): void
    {
        $rule = $this->rule(['tiers' => [], 'sort_by' => 'price_desc']);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 2, [])]);
        self::assertNull((new NthItemStrategy())->apply($rule, $ctx));
    }

    public function testZeroDiscountOverallReturnsNull(): void
    {
        $rule = $this->rule([
            'tiers' => [
                ['nth' => 1, 'method' => 'percentage', 'value' => 0],
            ],
            'sort_by' => 'price_desc',
            'recursive' => true,
        ]);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 3, [])]);
        // Everything at 0% off → null
        self::assertNull((new NthItemStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'nth_item', 'config' => $config]);
    }
}
```

### Implementation — `src/Strategy/NthItemStrategy.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class NthItemStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'nth_item';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $tiers = (array) ($config['tiers'] ?? []);
        if ($tiers === []) {
            return null;
        }

        $sortBy = (string) ($config['sort_by'] ?? 'price_desc');
        $recursive = (bool) ($config['recursive'] ?? false);

        // Index tiers by nth for O(1) lookup, and find max nth.
        $tiersByNth = [];
        $maxNth = 0;
        foreach ($tiers as $tier) {
            $nth = (int) ($tier['nth'] ?? 0);
            if ($nth <= 0) {
                continue;
            }
            $tiersByNth[$nth] = [
                'method' => (string) ($tier['method'] ?? 'percentage'),
                'value'  => (float) ($tier['value'] ?? 0),
            ];
            if ($nth > $maxNth) {
                $maxNth = $nth;
            }
        }
        if ($maxNth === 0) {
            return null;
        }

        // Flatten to units and sort.
        $units = [];
        foreach ($context->getItems() as $item) {
            for ($i = 0; $i < $item->getQuantity(); $i++) {
                $units[] = ['product_id' => $item->getProductId(), 'price' => $item->getPrice()];
            }
        }

        usort($units, static function (array $a, array $b) use ($sortBy): int {
            if ($sortBy === 'price_asc') {
                return $a['price'] <=> $b['price'];
            }
            return $b['price'] <=> $a['price'];
        });

        $totalDiscount = 0.0;
        $affected = [];

        foreach ($units as $idx => $unit) {
            $position = $idx + 1; // 1-indexed
            if ($recursive) {
                $tierIdx = (($position - 1) % $maxNth) + 1;
            } else {
                $tierIdx = min($position, $maxNth);
            }
            $tier = $tiersByNth[$tierIdx] ?? $tiersByNth[$maxNth] ?? null;
            if ($tier === null) {
                continue;
            }
            $discount = $this->unitDiscount($unit['price'], $tier['method'], $tier['value']);
            if ($discount > 0) {
                $totalDiscount += $discount;
                $affected[$unit['product_id']] = true;
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
            ['sort_by' => $sortBy, 'recursive' => $recursive]
        );
    }

    private function unitDiscount(float $price, string $method, float $value): float
    {
        switch ($method) {
            case 'percentage':
                if ($value <= 0) {
                    return 0.0;
                }
                return $price * min(100.0, $value) / 100;
            case 'flat':
                return min($price, max(0.0, $value));
            case 'free':
                return $price;
        }
        return 0.0;
    }
}
```

Run test → 9 passes. Full suite → 146.

Commit:
```bash
git add src/Strategy/NthItemStrategy.php tests/Unit/Strategy/NthItemStrategyTest.php
git commit -m "feat: add NthItemStrategy with tests (Taiwan 第 N 件 X 折)"
```

---

### Task 3: CrossCategoryStrategy (TDD) — 紅配綠

**Files:**
- Create: `tests/Unit/Strategy/CrossCategoryStrategyTest.php`
- Create: `src/Strategy/CrossCategoryStrategy.php`

### Config

```json
{
  "groups": [
    {"name": "上衣", "filter": {"type":"categories","value":[12]}, "min_qty": 1},
    {"name": "褲子", "filter": {"type":"categories","value":[13]}, "min_qty": 1}
  ],
  "reward": {"method": "percentage|flat|fixed_bundle_price", "value": 20},
  "repeat": true
}
```

**Semantics:**
- All groups must be satisfied (each group's `filter` matches at least `min_qty` items in the cart).
- Forming one "bundle" means taking `min_qty` items from each group; the cheapest combination (to maximize customer savings we pick the most expensive units in each group).
- Bundle total = sum of all selected unit prices across all groups.
- `reward.method`:
  - `percentage` → bundle discount = bundle total × (value / 100)
  - `flat` → bundle discount = min(bundle total, value)
  - `fixed_bundle_price` → bundle discount = max(0, bundle total - value)
- `repeat`: if true, try to form as many bundles as possible.

**Scope simplification**: the group `filter` here is embedded in the strategy config. For Phase 3 we only support the inline `{type:"categories", value:[...]}` shape — no delegation to FilterRegistry. This is acceptable because Phase 4 will refactor conditions/filters to a unified form.

### Test — `tests/Unit/Strategy/CrossCategoryStrategyTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\CrossCategoryStrategy;

final class CrossCategoryStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('cross_category', (new CrossCategoryStrategy())->type());
    }

    public function testTwoGroupsPercentage(): void
    {
        $rule = $this->rule([
            'groups' => [
                ['name' => 'Top', 'filter' => ['type' => 'categories', 'value' => [12]], 'min_qty' => 1],
                ['name' => 'Bot', 'filter' => ['type' => 'categories', 'value' => [13]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'percentage', 'value' => 20],
            'repeat' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Shirt', 500.0, 1, [12]),
            new CartItem(2, 'Pants', 800.0, 1, [13]),
        ]);
        // bundle total = 1300 × 20% = 260
        $result = (new CrossCategoryStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(260.0, $result->getAmount());
    }

    public function testFixedBundlePrice(): void
    {
        $rule = $this->rule([
            'groups' => [
                ['name' => 'Coffee', 'filter' => ['type' => 'categories', 'value' => [100]], 'min_qty' => 1],
                ['name' => 'Filter', 'filter' => ['type' => 'categories', 'value' => [101]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'fixed_bundle_price', 'value' => 399],
            'repeat' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'Beans', 450.0, 1, [100]),
            new CartItem(2, 'Paper', 50.0, 1, [101]),
        ]);
        // bundle 500 - 399 = 101
        $result = (new CrossCategoryStrategy())->apply($rule, $ctx);
        self::assertSame(101.0, $result->getAmount());
    }

    public function testFlatOff(): void
    {
        $rule = $this->rule([
            'groups' => [
                ['name' => 'A', 'filter' => ['type' => 'categories', 'value' => [1]], 'min_qty' => 1],
                ['name' => 'B', 'filter' => ['type' => 'categories', 'value' => [2]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'flat', 'value' => 100],
            'repeat' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'A1', 200.0, 1, [1]),
            new CartItem(2, 'B1', 200.0, 1, [2]),
        ]);
        $result = (new CrossCategoryStrategy())->apply($rule, $ctx);
        self::assertSame(100.0, $result->getAmount());
    }

    public function testInsufficientGroupReturnsNull(): void
    {
        $rule = $this->rule([
            'groups' => [
                ['name' => 'A', 'filter' => ['type' => 'categories', 'value' => [1]], 'min_qty' => 1],
                ['name' => 'B', 'filter' => ['type' => 'categories', 'value' => [2]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'percentage', 'value' => 20],
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'A1', 200.0, 5, [1]),
            // No B
        ]);
        self::assertNull((new CrossCategoryStrategy())->apply($rule, $ctx));
    }

    public function testRepeatFormsMultipleBundles(): void
    {
        $rule = $this->rule([
            'groups' => [
                ['name' => 'A', 'filter' => ['type' => 'categories', 'value' => [1]], 'min_qty' => 1],
                ['name' => 'B', 'filter' => ['type' => 'categories', 'value' => [2]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'percentage', 'value' => 10],
            'repeat' => true,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'A1', 100.0, 3, [1]),
            new CartItem(2, 'B1', 100.0, 3, [2]),
        ]);
        // 3 bundles × 200 × 10% = 60
        $result = (new CrossCategoryStrategy())->apply($rule, $ctx);
        self::assertSame(60.0, $result->getAmount());
    }

    public function testHigherMinQty(): void
    {
        $rule = $this->rule([
            'groups' => [
                ['name' => 'A', 'filter' => ['type' => 'categories', 'value' => [1]], 'min_qty' => 2],
                ['name' => 'B', 'filter' => ['type' => 'categories', 'value' => [2]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'percentage', 'value' => 10],
            'repeat' => false,
        ]);
        $ctx = new CartContext([
            new CartItem(1, 'A1', 100.0, 2, [1]),
            new CartItem(2, 'B1', 300.0, 1, [2]),
        ]);
        // bundle = 100 + 100 + 300 = 500, 10% = 50
        $result = (new CrossCategoryStrategy())->apply($rule, $ctx);
        self::assertSame(50.0, $result->getAmount());
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule([
            'groups' => [
                ['name' => 'A', 'filter' => ['type' => 'categories', 'value' => [1]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'percentage', 'value' => 10],
        ]);
        self::assertNull((new CrossCategoryStrategy())->apply($rule, new CartContext([])));
    }

    public function testSingleGroupReturnsNull(): void
    {
        // Cross-category implies multiple groups.
        $rule = $this->rule([
            'groups' => [
                ['name' => 'A', 'filter' => ['type' => 'categories', 'value' => [1]], 'min_qty' => 1],
            ],
            'reward' => ['method' => 'percentage', 'value' => 10],
        ]);
        $ctx = new CartContext([new CartItem(1, 'A1', 100.0, 1, [1])]);
        self::assertNull((new CrossCategoryStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'cross_category', 'config' => $config]);
    }
}
```

### Implementation — `src/Strategy/CrossCategoryStrategy.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class CrossCategoryStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'cross_category';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $groups = (array) ($config['groups'] ?? []);
        $reward = (array) ($config['reward'] ?? []);
        $repeat = (bool) ($config['repeat'] ?? false);

        if (count($groups) < 2) {
            return null; // cross-category requires multiple groups
        }

        $method = (string) ($reward['method'] ?? '');
        $value = (float) ($reward['value'] ?? 0);
        if (!in_array($method, ['percentage', 'flat', 'fixed_bundle_price'], true)) {
            return null;
        }

        // Build a per-group pool of units (flattened by quantity), sorted by price desc.
        $groupPools = [];
        foreach ($groups as $i => $group) {
            $minQty = (int) ($group['min_qty'] ?? 1);
            if ($minQty <= 0) {
                return null;
            }
            $filter = (array) ($group['filter'] ?? []);
            $categoryIds = array_map('intval', (array) ($filter['value'] ?? []));

            $units = [];
            foreach ($context->getItems() as $item) {
                $hit = false;
                foreach ($item->getCategoryIds() as $cat) {
                    if (in_array($cat, $categoryIds, true)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                for ($q = 0; $q < $item->getQuantity(); $q++) {
                    $units[] = ['product_id' => $item->getProductId(), 'price' => $item->getPrice()];
                }
            }
            if (count($units) < $minQty) {
                return null;
            }
            usort($units, static function (array $a, array $b): int {
                return $b['price'] <=> $a['price'];
            });
            $groupPools[$i] = ['min_qty' => $minQty, 'units' => $units];
        }

        $bundles = $this->computeBundleCount($groupPools, $repeat);
        if ($bundles <= 0) {
            return null;
        }

        $totalDiscount = 0.0;
        $affected = [];

        for ($b = 0; $b < $bundles; $b++) {
            $bundleTotal = 0.0;
            foreach ($groupPools as $groupIdx => &$pool) {
                $take = array_splice($pool['units'], 0, $pool['min_qty']);
                foreach ($take as $unit) {
                    $bundleTotal += $unit['price'];
                    $affected[$unit['product_id']] = true;
                }
            }
            unset($pool);

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
            ['method' => $method, 'bundles' => $bundles]
        );
    }

    /**
     * @param array<int, array{min_qty:int,units:array<int, array{product_id:int,price:float}>}> $pools
     */
    private function computeBundleCount(array $pools, bool $repeat): int
    {
        $minPossible = PHP_INT_MAX;
        foreach ($pools as $pool) {
            $possible = intdiv(count($pool['units']), $pool['min_qty']);
            if ($possible < $minPossible) {
                $minPossible = $possible;
            }
        }
        if ($minPossible <= 0) {
            return 0;
        }
        return $repeat ? $minPossible : 1;
    }

    private function bundleDiscount(string $method, float $value, float $bundleTotal): float
    {
        switch ($method) {
            case 'percentage':
                return $bundleTotal * ($value / 100);
            case 'flat':
                return min($bundleTotal, $value);
            case 'fixed_bundle_price':
                return $bundleTotal > $value ? $bundleTotal - $value : 0.0;
        }
        return 0.0;
    }
}
```

Run test → 9 passes. Full suite → 155.

Commit:
```bash
git add src/Strategy/CrossCategoryStrategy.php tests/Unit/Strategy/CrossCategoryStrategyTest.php
git commit -m "feat: add CrossCategoryStrategy with tests (Taiwan 紅配綠)"
```

---

### Task 4: FreeShippingStrategy (TDD)

**Files:**
- Create: `tests/Unit/Strategy/FreeShippingStrategyTest.php`
- Create: `src/Strategy/FreeShippingStrategy.php`

### Config

```json
{
  "method": "remove_shipping | percentage_off_shipping",
  "value": 50
}
```

**Semantics:**

Phase 3's FreeShippingStrategy is **logic-only**: it returns a `DiscountResult` with `scope = shipping` and `amount = 0` (for `remove_shipping`) or `amount = <percentage_off>` (for `percentage_off_shipping`). The **actual shipping manipulation** happens in a Phase 4 `ShippingHooks` integration class — Phase 3 only emits the intent via the result's `meta` payload.

For Phase 3 we compute a placeholder discount amount of `0.0` for `remove_shipping` (scope=shipping, hasDiscount=false would return null). Instead we emit `amount = 1.0` as a sentinel with `meta.free_shipping = true`. Alternatively: return null when no context to operate on.

**Pragmatic approach for Phase 3**: since Phase 3 doesn't yet have a ShippingHooks consumer, FreeShippingStrategy's behaviour is:

1. Validate config.
2. If cart is empty, return null.
3. Otherwise return a `DiscountResult` with:
   - `scope = 'shipping'`
   - `amount = 1.0` (sentinel — placeholder until ShippingHooks lands in Phase 4)
   - `meta = {'method': ..., 'value': ...}`

Tests will assert on config validation + scope + meta, not on amount semantics. Phase 4 will replace the sentinel with real shipping-line subtraction math.

### Test — `tests/Unit/Strategy/FreeShippingStrategyTest.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\CartItem;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;
use PowerDiscount\Strategy\FreeShippingStrategy;

final class FreeShippingStrategyTest extends TestCase
{
    public function testType(): void
    {
        self::assertSame('free_shipping', (new FreeShippingStrategy())->type());
    }

    public function testRemoveShippingEmitsShippingScope(): void
    {
        $rule = $this->rule(['method' => 'remove_shipping']);
        $ctx = new CartContext([new CartItem(1, 'A', 500.0, 1, [])]);

        $result = (new FreeShippingStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        self::assertSame(DiscountResult::SCOPE_SHIPPING, $result->getScope());
        $meta = $result->getMeta();
        self::assertSame('remove_shipping', $meta['method'] ?? null);
    }

    public function testPercentageOffShippingEmitsMeta(): void
    {
        $rule = $this->rule(['method' => 'percentage_off_shipping', 'value' => 50]);
        $ctx = new CartContext([new CartItem(1, 'A', 500.0, 1, [])]);

        $result = (new FreeShippingStrategy())->apply($rule, $ctx);
        self::assertNotNull($result);
        $meta = $result->getMeta();
        self::assertSame('percentage_off_shipping', $meta['method'] ?? null);
        self::assertSame(50.0, $meta['value'] ?? null);
    }

    public function testEmptyCartReturnsNull(): void
    {
        $rule = $this->rule(['method' => 'remove_shipping']);
        self::assertNull((new FreeShippingStrategy())->apply($rule, new CartContext([])));
    }

    public function testInvalidMethodReturnsNull(): void
    {
        $rule = $this->rule(['method' => 'bogus']);
        $ctx = new CartContext([new CartItem(1, 'A', 100.0, 1, [])]);
        self::assertNull((new FreeShippingStrategy())->apply($rule, $ctx));
    }

    private function rule(array $config): Rule
    {
        return new Rule(['id' => 1, 'title' => 't', 'type' => 'free_shipping', 'config' => $config]);
    }
}
```

### Implementation — `src/Strategy/FreeShippingStrategy.php`

```php
<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class FreeShippingStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'free_shipping';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $method = (string) ($config['method'] ?? '');
        $value = (float) ($config['value'] ?? 0);

        if (!in_array($method, ['remove_shipping', 'percentage_off_shipping'], true)) {
            return null;
        }

        // Sentinel amount: the real shipping-line subtraction lives in Phase 4 ShippingHooks.
        // Amount > 0 is required for DiscountResult::hasDiscount() to pass aggregation.
        $sentinel = 1.0;

        return new DiscountResult(
            $rule->getId(),
            $rule->getType(),
            DiscountResult::SCOPE_SHIPPING,
            $sentinel,
            [],
            $rule->getLabel(),
            [
                'method' => $method,
                'value'  => $value,
            ]
        );
    }
}
```

Run test → 5 passes. Full suite → 160.

Commit:
```bash
git add src/Strategy/FreeShippingStrategy.php tests/Unit/Strategy/FreeShippingStrategyTest.php
git commit -m "feat: add FreeShippingStrategy with tests (sentinel scope until Phase 4 ShippingHooks)"
```

---

### Task 5: Register Taiwan strategies in Plugin::boot + README + manual verification doc

**Files:**
- Modify: `src/Plugin.php`
- Modify: `README.md`
- Create: `docs/phase-3-manual-verification.md`

### 5a. Modify `src/Plugin.php::buildStrategyRegistry`

Add imports at the top of the file:

```php
use PowerDiscount\Strategy\BuyXGetYStrategy;
use PowerDiscount\Strategy\CrossCategoryStrategy;
use PowerDiscount\Strategy\FreeShippingStrategy;
use PowerDiscount\Strategy\NthItemStrategy;
```

Update the `buildStrategyRegistry` method — after the existing 4 `$registry->register(...)` calls and before the `apply_filters`:

```php
        $registry->register(new BuyXGetYStrategy());
        $registry->register(new NthItemStrategy());
        $registry->register(new CrossCategoryStrategy());
        $registry->register(new FreeShippingStrategy());
```

### 5b. Update `README.md` status section

Replace the `## Status` section with:

```markdown
## Status

**Phase 3 (Taiwan Strategies)** — complete.

- All 8 strategies now registered: `simple`, `bulk`, `cart`, `set`, `buy_x_get_y`, `nth_item`, `cross_category`, `free_shipping`
- Taiwan-first features:
  - **Buy X Get Y** (same / specific / cheapest_in_cart targets)
  - **第 N 件 X 折** (NthItemStrategy with recursive cycles)
  - **紅配綠** (CrossCategoryStrategy with multi-group bundles)
  - **免運** (FreeShipping, shipping-scope sentinel — real shipping manipulation lands in Phase 4)

Still pending: remaining 11 conditions + 4 filters (Phase 4), Admin UI (Phase 4), Frontend (Phase 4), real ShippingHooks (Phase 4).
```

### 5c. Create `docs/phase-3-manual-verification.md`

````markdown
# Phase 3 Manual Verification

Strategies are unit-tested exhaustively, but end-to-end WC integration with the new rule types should be verified on a staging site before shipping to production clients.

## Setup

Activate `power-discount`. Schema v1 tables must exist.

Insert one rule per Taiwan strategy type using SQL like the examples below. Replace `{CAT}`, `{CAT_TOP}`, `{CAT_BOTTOM}`, `{PRODUCT_ID}` with real IDs.

## BuyXGetY — Buy 2 Get 1 Cheapest Free

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, config, filters, conditions, created_at, updated_at)
VALUES (
  '買 2 送 1 最便宜',
  'buy_x_get_y',
  1,
  10,
  '{"trigger":{"source":"filter","qty":2},"reward":{"target":"cheapest_in_cart","qty":1,"method":"free","value":0},"recursive":true}',
  '{"items":[{"type":"categories","method":"in","ids":[{CAT}]}]}',
  '{}',
  NOW(),
  NOW()
);
```

Verify:
- [ ] Add 3 qualifying items to cart → cheapest should get 100% discount
- [ ] Add 6 qualifying items → 2 bundles, 2 cheapest free
- [ ] Single item → no discount

## NthItem — 第二件 6 折

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, config, filters, conditions, created_at, updated_at)
VALUES (
  '第二件 6 折',
  'nth_item',
  1,
  10,
  '{"tiers":[{"nth":1,"method":"percentage","value":0},{"nth":2,"method":"percentage","value":40}],"sort_by":"price_desc","recursive":true}',
  '{"items":[{"type":"categories","method":"in","ids":[{CAT}]}]}',
  '{}',
  NOW(),
  NOW()
);
```

Verify:
- [ ] 2 items → first full price, second 60% of price shown
- [ ] 4 items → items 1,3 full price; items 2,4 at 60% (recursive)

## CrossCategory — 紅配綠上衣+褲子 8 折

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, config, filters, conditions, created_at, updated_at)
VALUES (
  '紅配綠',
  'cross_category',
  1,
  10,
  '{"groups":[{"name":"上衣","filter":{"type":"categories","value":[{CAT_TOP}]},"min_qty":1},{"name":"褲子","filter":{"type":"categories","value":[{CAT_BOTTOM}]},"min_qty":1}],"reward":{"method":"percentage","value":20},"repeat":true}',
  '{}',
  '{}',
  NOW(),
  NOW()
);
```

Verify:
- [ ] 1 top + 1 pants → bundle total × 20% discount
- [ ] 3 tops + 3 pants → 3 bundles × 20% each
- [ ] 2 tops + 0 pants → no discount (group B unfulfilled)

## FreeShipping

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, config, filters, conditions, created_at, updated_at)
VALUES (
  '滿 $1000 免運',
  'free_shipping',
  1,
  10,
  '{"method":"remove_shipping"}',
  '{}',
  '{"logic":"and","items":[{"type":"cart_subtotal","operator":">=","value":1000}]}',
  NOW(),
  NOW()
);
```

Verify:
- [ ] Rule hits above $1000 → `wp_pd_order_discounts` shows `scope='shipping'` entry
- [ ] Actual shipping is NOT removed yet — Phase 4 ShippingHooks will consume the sentinel result
- [ ] Amount logged is `1.0` (sentinel)

## Known Gaps → Phase 4

- FreeShipping sentinel not yet consumed by a real ShippingHooks hook
- CrossCategory inline `filter.value` uses a minimal format that doesn't go through FilterRegistry; Phase 4 will unify
- BuyXGetY doesn't yet support `cheapest_from_filter` reward target
- Still only 2 conditions + 2 filters available system-wide
````

### 5d. Verify

- `php -l src/Plugin.php`
- `vendor/bin/phpunit` — expect 160 tests total
- `git log --oneline -5` — 5 new commits

Commit:
```bash
git add src/Plugin.php README.md docs/phase-3-manual-verification.md
git commit -m "feat: register Taiwan strategies in Plugin::boot + Phase 3 docs"
```

---

## Phase 3 Exit Criteria

- ✅ `vendor/bin/phpunit` ≥ 160 tests green
- ✅ All `.php` files lint clean
- ✅ 8 strategies total in `StrategyRegistry`
- ✅ All 4 Taiwan strategies unit-tested
- ✅ README updated
- ✅ Manual verification doc committed

## Known Gaps → Phase 4

- FreeShipping sentinel result not yet acted on (no ShippingHooks consumer)
- CrossCategory filter shape is inline, not delegated to FilterRegistry
- BuyXGetY `cheapest_from_filter` reward target skipped
- 11 conditions + 4 filters remaining
- No Admin UI — rules still SQL-only
- No frontend price table / badge / shipping bar
