# Phase 2 Manual Verification

Unit tests cannot exercise the full WooCommerce integration. The following checklist must be run on a real WP + WC site before Phase 2 is declared done on that deploy.

## Setup

1. Activate `power-discount` plugin. Schema v1 must create two tables (`wp_pd_rules`, `wp_pd_order_discounts`).
2. Manually insert a test rule via SQL:

```sql
INSERT INTO wp_pd_rules (title, type, status, priority, exclusive, config, filters, conditions, created_at, updated_at)
VALUES (
  'Coffee 10% off',
  'simple',
  1,
  10,
  0,
  '{"method":"percentage","value":10}',
  '{"items":[{"type":"categories","method":"in","ids":[{CATEGORY_ID}]}]}',
  '{"logic":"and","items":[{"type":"cart_subtotal","operator":">=","value":500}]}',
  NOW(),
  NOW()
);
```

Replace `{CATEGORY_ID}` with a real product category id.

## Checklist

- [ ] Add product in the target category to cart, subtotal < 500 → no discount.
- [ ] Add more items → cart reaches ≥500 → 10% off only applies to category products.
- [ ] Non-category products in cart are unaffected.
- [ ] Proceed to checkout and place order → inspect `wp_pd_order_discounts`: should have one row with `rule_id`, `discount_amount`, `scope='product'`.
- [ ] Disable rule (`status = 0`) → discount no longer appears.
- [ ] Re-enable + set `ends_at` to yesterday → discount no longer appears (expired).
- [ ] Add a second rule with `priority = 5` and `exclusive = 1` → verify it takes over and the first rule is skipped.

## Known Gaps (tracked for Phase 3)

- BulkStrategy `per_category` scope is not implemented (returns null)
- Only 2 conditions available (cart_subtotal, date_range)
- Only 2 filters available (all_products, categories)
- BOGO / NthItem / CrossCategory / FreeShipping strategies not available yet
- No admin UI — rules must be managed via SQL
