# Power Discount — 加價購 Add-on Purchase 功能設計

**日期**：2026-04-16
**版本目標**：v1.1.0（加價購為新增 feature，不破壞 1.0.x 折扣引擎）
**狀態**：Design draft — 待實作

---

## 1. 需求摘要

在 PowerDiscount 外掛中新增「加價購」子系統，讓商家設定「當顧客瀏覽 / 購買某些目標商品時，在商品頁面上出現可勾選的加價購商品」。與現有的「折扣規則」系統並列，但資料與引擎完全獨立。

### 核心流程
1. 商家在 PowerDiscount → 加價購 頁面啟用功能（opt-in）
2. 新增加價購規則：指定一批加價購商品（每個配一個特價）+ 一批投放目標商品
3. 顧客在目標商品的 single product 頁面看到加價購專區
4. 顧客勾選想要的加價購後按「加入購物車」
5. 加價購商品以規則設定的特價進入購物車
6. （可選）加價購商品不被其他折扣規則處理

---

## 2. 啟用機制

### 2.1 Option key
```
power_discount_addon_enabled   (bool, default false)
```

### 2.2 首次進入流程

左側選單新增 `加價購` 子選單（slug `power-discount-addons`）。

```
點選 加價購
  │
  ├── option = false  →  顯示啟用畫面 (AddonActivationPage)
  │                         ├── 說明文字 + 功能介紹
  │                         ├── 大按鈕：「啟用加價購功能」
  │                         └── POST 後設 option=true，跑 schema v3 migration
  │
  └── option = true   →  顯示規則清單 (AddonRulesListPage)
```

### 2.3 停用

規則清單頁面右上角「設定」→ 提供「停用加價購功能」按鈕。  
停用只是把 option 設回 false，**不刪除資料表與規則資料**。使用者再次啟用時原資料還在。

**停用後的行為：**
- 前台 single product 頁面不顯示加價購專區
- 商品編輯頁不顯示加價購 metabox
- 購物車中已存在的加價購 line item 不受影響（持續顯示）

---

## 3. 資料模型

### 3.1 新資料表 `pd_addon_rules`

Schema 版本從 v2 升到 **v3**。

```sql
CREATE TABLE {$wpdb->prefix}pd_addon_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,                 -- 1=啟用 / 0=停用
    priority INT NOT NULL DEFAULT 10,                     -- 目標商品頁顯示順序
    addon_items LONGTEXT NOT NULL,                        -- JSON: [{product_id, special_price}, ...]
    target_product_ids LONGTEXT NOT NULL,                 -- JSON: [12, 34, 56]
    exclude_from_discounts TINYINT(1) NOT NULL DEFAULT 0, -- 1=此規則的 addon 不套用其他折扣規則
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_status (status),
    KEY idx_priority (status, priority)
) {$charset_collate};
```

**JSON 結構**：

```json
{
  "addon_items": [
    { "product_id": 101, "special_price": 90 },
    { "product_id": 102, "special_price": 150 }
  ],
  "target_product_ids": [12, 34, 56]
}
```

**為什麼把 `addon_items` 和 `target_product_ids` 存 JSON 而不是關聯表**：
- 與現有 `pd_rules` 設計風格一致（`filters`、`conditions`、`config` 都是 JSON）
- 讀取頻率遠高於寫入，不需要在關聯表上做 JOIN 查詢
- 單一 rule 的商品數量通常不大（< 50），JSON 解析成本可接受
- 未來要做反向查詢（某個 product 屬於哪些 rule）可以用 `LIKE '%"product_id":101%'` 或全部撈下來 in-memory filter

### 3.2 領域物件

```
src/Domain/AddonRule.php       — 純值物件，類似 Rule
src/Domain/AddonItem.php       — {product_id, special_price} 組合
```

`AddonRule` 欄位：
- `id: int`
- `title: string`
- `status: int`
- `priority: int`
- `addonItems: AddonItem[]`
- `targetProductIds: int[]`
- `excludeFromDiscounts: bool`

方法：
- `isEnabled(): bool`
- `matchesTarget(int $productId): bool`
- `getSpecialPriceFor(int $addonProductId): ?float`
- `containsAddon(int $productId): bool`

### 3.3 Repository

```
src/Repository/AddonRuleRepository.php
```

方法：
- `insert(AddonRule): int`
- `update(AddonRule): int`
- `delete(int $id): int`
- `findById(int $id): ?AddonRule`
- `findAll(): AddonRule[]`
- `findActiveForTarget(int $productId): AddonRule[]` — 前台顯示時呼叫
- `findContainingAddon(int $addonProductId): AddonRule[]` — product metabox 反向查詢
- `findContainingTarget(int $targetProductId): AddonRule[]` — product metabox 反向查詢

### 3.4 Migrator 升級

`src/Install/Migrator.php`：

```php
private const SCHEMA_VERSION = '3';
```

在現有的兩個 `dbDelta($sql)` 之後加上 `pd_addon_rules` 的 CREATE TABLE。dbDelta 只會對還不存在的表建立，不影響既有 v1/v2 的升級。

**不需要因啟用功能才建表** — 建表成本低、啟用 option 才是實際的功能開關。這樣比較單純。

---

## 4. 檔案結構

### 4.1 新增檔案

```
src/
├── Domain/
│   ├── AddonRule.php                            (新)
│   └── AddonItem.php                            (新)
├── Repository/
│   └── AddonRuleRepository.php                  (新)
├── Admin/
│   ├── AddonMenu.php                            (新) — 註冊 加價購 子選單 + 分派
│   ├── AddonActivationPage.php                  (新) — opt-in 啟用頁
│   ├── AddonRulesListPage.php                   (新) — 規則清單
│   ├── AddonRulesListTable.php                  (新) — WP_List_Table
│   ├── AddonRuleEditPage.php                    (新) — 新增/編輯頁
│   ├── AddonRuleFormMapper.php                  (新) — POST → AddonRule
│   ├── AddonProductMetabox.php                  (新) — 個別商品編輯頁的 metabox
│   ├── AddonAjaxController.php                  (新) — 規則重排、商品搜尋
│   └── views/
│       ├── addon-activation.php                 (新)
│       ├── addon-list.php                       (新)
│       ├── addon-edit.php                       (新)
│       └── addon-metabox.php                    (新)
├── Integration/
│   ├── AddonFrontend.php                        (新) — 前台 product page widget
│   ├── AddonCartHandler.php                     (新) — 加入購物車 + 特價 override
│   └── AddonExclusionFilter.php                 (新) — 把被排除的 addon item 從折扣引擎的 CartContext 移除
└── Plugin.php                                    (改) — 註冊以上元件

assets/
├── admin/
│   ├── addon-admin.js                           (新) — 編輯頁 repeater、metabox
│   └── addon-admin.css                          (新)
└── frontend/
    ├── addon.js                                 (新) — 前台勾選、打開 modal
    └── addon.css                                 (新) — widget + modal 樣式

languages/
└── zh_TW.php                                    (改) — 加價購相關字串
```

### 4.2 改動現有檔案（最小）

```
src/Install/Migrator.php                        (schema v2 → v3)
src/Plugin.php                                  (boot 時註冊 AddonMenu 與前後台元件)
power-discount.php                              (Version bump 1.0.3 → 1.1.0)
readme.txt                                      (Stable tag + Changelog)
```

**現有折扣引擎（`Calculator`、`Rule`、`pd_rules`）完全不動**。

---

## 5. 管理介面

### 5.1 選單層級

```
PowerDiscount
├── 折扣規則          (既有)
├── 加價購            (新)
└── 報表              (既有)
```

### 5.2 啟用頁面 (`addon-activation.php`)

```
┌────────────────────────────────────────────┐
│ 加價購                                       │
│                                              │
│  ┌──────────────────────────────────────┐   │
│  │  🛍️  加價購                           │   │
│  │                                        │   │
│  │  讓顧客在購買特定商品時，以特價加購    │   │
│  │  其他商品。例如買咖啡豆送濾紙 $30。    │   │
│  │                                        │   │
│  │  功能特色：                            │   │
│  │  ✓ 商品頁面自動顯示加價購專區          │   │
│  │  ✓ 雙向設定（規則頁 & 商品編輯頁）     │   │
│  │  ✓ 可排除加價購商品的其他折扣          │   │
│  │                                        │   │
│  │       [  啟用加價購功能  ]             │   │
│  └──────────────────────────────────────┘   │
└────────────────────────────────────────────┘
```

### 5.3 規則清單 (`AddonRulesListTable`)

沿用 `RulesListTable` 的樣式（拖拉排序、toggle 開關、常駐 row-actions），欄位：

| 欄位 | 說明 |
|---|---|
| 優先順序 | 拖拉手柄 + 1/2/3 position pill |
| 狀態 | Toggle 開關 |
| 名稱 | 規則標題 + 編輯/複製/刪除 row actions |
| 加價購商品 | N 個商品（顯示前 3 個名稱 + 「...」） |
| 目標商品 | M 個商品（顯示前 3 個名稱 + 「...」） |
| 排除其他折扣 | ✓ / — |

重排 AJAX：`pd_reorder_addon_rules`

### 5.4 規則編輯頁 (`addon-edit.php`)

分成三個區塊（沿用 `.pd-section` 卡片）：

**區塊 1 — 基本設定**
- 規則名稱（必填）
- 狀態（啟用 / 停用）
- 排除其他折扣（checkbox）
  - 說明：勾選後，此規則中的加價購商品在購物車中不會被其他折扣規則處理，永遠保持在設定的特價

**區塊 2 — 加價購商品**
- Repeater：每列一個 addon item
- 每列欄位：
  - 商品（WC enhanced select，支援搜尋）— 沿用現有 `.wc-product-search` + WC 內建 ajax handler
  - 特價（number input，NT$）
  - 原價提示（read-only，JS 從 `wc-product-search` 的 selection change 即時 fetch 顯示）
  - 移除按鈕
- 「新增加價購商品」按鈕

**區塊 3 — 投放目標商品**
- 多選 WC product search（`class="wc-product-search"` multiple）
- 選中的商品會顯示在加價購專區中的這批 addon 商品

底部：儲存按鈕

### 5.5 商品編輯頁 metabox (`AddonProductMetabox`)

**位置**：商品編輯頁的 sidebar（`side` context），或 `advanced` 欄。優先 `side`。

**標題**：「加價購關聯」

**內容**：
```
┌─ 加價購關聯 ─────────────────┐
│                                │
│ 此商品作為「目標商品」出現在：  │
│  ☐ 咖啡豆加價購濾紙 ($30)      │
│  ☐ 咖啡豆加價購電子秤 ($500)   │
│  [ + 新增到規則... ▾ ]         │
│                                │
│ ────────────────────────       │
│                                │
│ 此商品作為「加價購商品」列在：  │
│  ☐ 咖啡器具加價購方案          │
│                                │
└────────────────────────────────┘
```

**儲存**：勾選 checkbox 會修改對應 AddonRule 的 `target_product_ids` 或 `addon_items` 並重新儲存 rule。不使用獨立的 product meta — 單一真相來源是 `pd_addon_rules` 表。

**功能開關**：只在 option 啟用時掛 `add_meta_boxes` hook。

---

## 6. 前台呈現

### 6.1 Widget 位置

掛在 `woocommerce_single_product_summary`，priority `25`：
- WC 預設：`25 = Price`、`30 = Excerpt`、`40 = Add to cart`
- 我們要放在 **Add to cart (40) 之前**、**商品選項之後**
- 實際 priority：**35**

### 6.2 Widget HTML 結構

```html
<div class="pd-addon-section">
    <h3 class="pd-addon-section-title">加價購優惠</h3>
    <div class="pd-addon-list">
        <label class="pd-addon-card" data-product-id="101" data-special-price="90">
            <input type="checkbox" name="pd_addon_ids[]" value="101">
            <div class="pd-addon-thumb">
                <img src="...cover.jpg" alt="...">
            </div>
            <div class="pd-addon-info">
                <div class="pd-addon-title">濾紙 100 張</div>
                <div class="pd-addon-price">
                    <span class="pd-addon-original">NT$150</span>
                    <span class="pd-addon-special">NT$90</span>
                </div>
                <button type="button" class="pd-addon-details-btn">查看詳細</button>
            </div>
        </label>
        <!-- 更多 card -->
    </div>
</div>
```

### 6.3 視覺樣式

- 卡片：白底、灰邊框、圓角 8px、padding 12px
- 左邊縮圖 80×80，右邊文字區彈性寬
- 原價刪除線、特價紅色粗體
- 勾選狀態：外框變藍 2px + 淡藍底
- 列表整體在行動裝置上單欄排列，桌機雙欄

### 6.4 「查看詳細」Modal

點擊 `.pd-addon-details-btn`（或卡片主體一半區域）彈出 modal：

```
┌──────────────────────────────────────┐
│ [ × ]                                │
│                                      │
│  ┌─────────┐  濾紙 100 張             │
│  │         │  NT$~~150~~ NT$90       │
│  │  IMG    │                         │
│  │         │  精選無漂白濾紙，口感乾淨 │
│  └─────────┘  不留雜味。              │
│                                      │
│  ─────────────────────────────────   │
│  完整介紹（可滾動）                   │
│                                      │
│  • 尺寸：V60 規格                     │
│  • 材質：天然木漿                     │
│  • 數量：100 張／包                   │
│  • ...更多內容...                     │
│                                      │
│  [更多可滾動內容]                     │
│                                      │
│ ═══════════════════════════════════  │
│          [ 選擇加購 ]                 │
└──────────────────────────────────────┘
```

**排版**：
- 最大寬度 640px，垂直方向最高 90vh
- 上方左側：商品封面（固定寬 200px）
- 上方右側：標題 + 特價 + excerpt（`post_excerpt` 或 `short_description`）
- 中間：full description（`post_content`）可滾動
- 底部：固定在 modal 底部的「選擇加購」按鈕（sticky）

**互動**：
- 點「選擇加購」→ 勾選該 addon 的 checkbox + 關閉 modal
- 如果此 addon 已經是勾選狀態 → 按鈕改為「取消加購」
- 點 modal 背景 overlay 關閉
- 按 ESC 鍵關閉
- Focus trap（無障礙）

**技術選擇**：
- 原生 `<dialog>` element（簡單、無依賴）
- 商品資料透過 `data-*` 屬性或 REST endpoint 取得
  - 傾向用 REST：`GET /wp-json/power-discount/v1/addon-product/{id}` 回 `{title, image, excerpt, content}`，避免一開始就把所有商品的 HTML 塞進頁面
  - 或者 server render 時直接把 HTML 內嵌在隱藏的 `<template class="pd-addon-detail" data-product-id="101">`，點擊時 JS 讀取 innerHTML 放進 modal — **這個更簡單**，先用這個

### 6.5 加入購物車

**表單改寫**：
- 原本 `<form class="cart">` 的 `<button name="add-to-cart">` 提交一筆主商品
- 加價購 checkbox 都掛在這個 form 內，`name="pd_addon_ids[]"`
- 提交後 WC 會走標準的 `add_to_cart` 流程，同時主商品和 addon 都加進購物車

**Hook**：`woocommerce_add_to_cart`（在主商品加入購物車之後觸發）
- 讀取 `$_POST['pd_addon_ids']`
- 對每個 addon ID：
  1. 查詢 `findContainingAddon(addon_id)` 取得對應的 AddonRule
  2. 取得特價
  3. 呼叫 `WC()->cart->add_to_cart($addon_id, 1, 0, [], $cartItemData)`
  4. `$cartItemData` 包含：
     ```php
     [
       '_pd_addon_from'             => $mainProductId,
       '_pd_addon_rule_id'          => $ruleId,
       '_pd_addon_special_price'    => $specialPrice,
       '_pd_addon_excluded_from_discounts' => $rule->excludeFromDiscounts ? 1 : 0,
     ]
     ```

**價格覆寫**：`woocommerce_before_calculate_totals` priority 5（比 CartHooks 的 20 更早）
- 掃描所有 cart item，如果有 `_pd_addon_special_price` 就 `$item['data']->set_price($specialPrice)`
- 這個機制和 `GiftAutoInjector` 相同

**購物車顯示**：
- 加價購 line item 在購物車顯示一個「加購」標籤（類似贈品的標記）
- 顯示特價 + 原價刪除線
- 數量預設 1，且不能從購物車增減 — 只能整筆移除（透過 `woocommerce_cart_item_quantity` filter 把它變成純文字）

### 6.6 與折扣引擎互動

**預設行為**：加價購商品進購物車後，就是一個普通的 line item，**折扣引擎會照常處理它**。所以如果另有一條「全站 95 折」折扣規則，這個 NT$90 的加價購商品會變成 NT$85.5。

**排除邏輯**：當 AddonRule 的 `excludeFromDiscounts = true` 時：
- Cart item meta 帶 `_pd_addon_excluded_from_discounts = 1`
- `CartContextBuilder`（折扣引擎建立 CartContext 的元件）過濾掉這些 item
- 結果：折扣引擎看不到這些 item，所有折扣策略都不會處理它們
- 這個過濾是在 `CartContextBuilder::build()` 裡加一層，不需要改動 Calculator / Strategy / Condition / Filter

**實作點**：
- 新增 `AddonExclusionFilter` 類別
- `CartContextBuilder::build()` 呼叫它一次，拿到要保留的 items array
- 或者直接在 `CartContextBuilder` 裡加 6 行 in-place 過濾

---

## 7. 管理後台 Ajax 端點

| Action | 說明 |
|---|---|
| `pd_toggle_addon_rule_status` | Toggle 開關狀態 |
| `pd_reorder_addon_rules` | 拖拉排序 |
| `pd_search_products_for_addon` | 商品搜尋（其實可以直接用 WC 內建的 `woocommerce_json_search_products_and_variations`） |
| `pd_get_product_regular_price` | 取得商品原價（編輯頁顯示對照用）|
| `pd_toggle_addon_metabox_rule` | 在商品編輯頁 metabox 的 checkbox 變動時即時更新 rule |

所有 ajax 都用 `check_ajax_referer('power_discount_admin', 'nonce')` 檢查。

---

## 8. REST API（給前台 modal 用，替代方案）

若採用 server-rendered `<template>` 方式就不需要 REST。先不實作。

---

## 9. Menu 註冊

修改 `src/Admin/AdminMenu.php`（既有 class），增加第三個 submenu：

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

`AddonMenu::route()` 依 option 啟用狀態決定顯示 `AddonActivationPage` 或 `AddonRulesListPage`，並進一步依 `$_GET['action']` 分派 `edit` / `new`。

---

## 10. 資料流摘要

### 10.1 新增 addon rule
```
使用者填表 → POST admin-post.php pd_save_addon_rule
  → AddonRuleEditPage::handleSave()
  → AddonRuleFormMapper::fromFormData($post)
  → AddonRuleRepository::insert($rule)
  → redirect back
```

### 10.2 顧客瀏覽目標商品頁
```
WooCommerce 走到 woocommerce_single_product_summary action
  → AddonFrontend::renderWidget(priority 35)
  → 讀目前商品 ID
  → AddonRuleRepository::findActiveForTarget($productId)
  → 對每條 rule：輸出 addon card 清單
```

### 10.3 顧客點「加入購物車」
```
WC 處理主商品 add_to_cart
  → 觸發 woocommerce_add_to_cart hook
  → AddonCartHandler::onAddToCart()
    → 讀 $_POST['pd_addon_ids']
    → 對每個 addon id：查 rule 拿特價 → 加入購物車（帶 meta）
  → woocommerce_before_calculate_totals (priority 5)
  → AddonCartHandler::applySpecialPrices()
  → woocommerce_before_calculate_totals (priority 10)
  → CartContextBuilder::build() 過濾掉 excluded items
  → 折扣引擎計算剩下的 items
```

### 10.4 商品編輯頁改 metabox
```
使用者勾選 checkbox
  → ajax pd_toggle_addon_metabox_rule (rule_id, target_product_id, attach=1/0)
  → AddonAjaxController 取出 rule → 修改 target_product_ids → 儲存
  → 回 success
```

---

## 11. 測試清單

### 11.1 單元測試（PHPUnit）

```
tests/Unit/Domain/AddonRuleTest.php
  ✓ 建構與 getters
  ✓ matchesTarget: 包含 / 不包含 / 空清單
  ✓ getSpecialPriceFor: 已知 id / 未知 id
  ✓ containsAddon: 布林
  ✓ excludeFromDiscounts 欄位

tests/Unit/Admin/AddonRuleFormMapperTest.php
  ✓ 正常 POST 轉成 AddonRule
  ✓ 缺 title → throw 中文錯誤
  ✓ 空 addon_items → throw
  ✓ 空 target_product_ids → throw
  ✓ special_price <= 0 → throw
  ✓ 非數字 special_price → throw
  ✓ product_id 0 或非正整數過濾

tests/Unit/Repository/AddonRuleRepositoryTest.php (with InMemoryDatabaseAdapter)
  ✓ insert / update / findById
  ✓ findActiveForTarget 過濾 status
  ✓ findContainingAddon / findContainingTarget
  ✓ findAll 排序
```

### 11.2 整合 / 手動測試

- 啟用 / 停用 addon 功能切換正確
- 新增 / 編輯 / 刪除 addon rule
- 拖拉排序
- 商品編輯頁 metabox 顯示正確、勾選即時生效
- 前台商品頁 widget 顯示位置正確
- 勾選 addon + 加入購物車後，主商品和 addon 都進購物車，特價正確
- 勾選 + 排除折扣：addon 在購物車中不被既有折扣規則處理
- Modal 開關、內容正確、選擇加購按鈕 toggle 狀態正確
- 停用 addon 功能後：前台不顯示、後台 metabox 隱藏、既有 cart item 不受影響

---

## 12. Out of scope（目前版本不做）

- 加價購的庫存互鎖（買 addon 需扣主商品庫存的特殊邏輯）
- 加價購商品的變體商品支援（Variable products）— 先只支援 simple products
- 多語系商品（WPML / Polylang）的翻譯對應
- 加價購規則的排程（沒有 starts_at / ends_at）
- 加價購的統計報表（Reports 頁面不處理 addon）
- 大量匯入 / 匯出 addon rules

---

## 13. Migration 與向後相容

- 既有使用者升級到 1.1.0 時：
  - Schema 從 v2 → v3，建立 `pd_addon_rules` 表（dbDelta 安全操作）
  - `power_discount_addon_enabled` option 預設 false
  - 完全不影響既有折扣規則運作
- 降版回 1.0.x 時：
  - 新表不會被清除
  - `power_discount_addon_enabled` 保留
  - 折扣引擎照常運作，只是看不到加價購功能

---

## 14. 版本計劃

- **v1.1.0**：實作本 spec 完整內容
- 發佈流程同既有模式：tag 推上去 → build zip → gh release create → 所有裝 1.0.2+ 的站台自動收到更新通知

---

## 15. 實作順序建議（給後續的 writing-plans 階段）

1. **Phase A**：Domain + Repository + Migrator（純資料層，可先寫滿測試）
2. **Phase B**：Admin 選單 + 啟用頁 + 規則清單（空殼）
3. **Phase C**：規則編輯頁 + FormMapper + Ajax 儲存
4. **Phase D**：商品編輯頁 metabox
5. **Phase E**：前台 widget（商品頁顯示 + 勾選 UI）
6. **Phase F**：購物車整合 + 特價 override + 排除折扣
7. **Phase G**：Modal 詳細資訊
8. **Phase H**：拋光 / 樣式 / 翻譯 / 測試

每個 phase 都是獨立可測的增量，適合 subagent-driven 執行。

---

## 16. 待決事項（未來可補）

- [ ] 模板覆寫：主題能不能 override `addon-card.php` 的預設 HTML？→ 第一版先不做，寫死在 PHP 裡
- [ ] 加價購的 hook：要不要開 `pd_addon_applied` action 給其他外掛監聽？→ 先不做
- [ ] 特價要不要允許百分比（例如「原價的 70%」）？→ 先只支援固定金額
