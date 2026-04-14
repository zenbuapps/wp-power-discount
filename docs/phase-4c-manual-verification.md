# Phase 4c Manual Verification

## Prereqs

Activate `power-discount` on a real WP+WC site. Phase 4a/4b verification already passed.

## Free Shipping Bar

Create rule via the admin UI (or SQL):
- Type: `free_shipping`
- Status: enabled
- `config_json`: `{"method":"remove_shipping"}`
- `conditions_json`: `{"logic":"and","items":[{"type":"cart_subtotal","operator":">=","value":1000}]}`

Verify:
- [ ] Visit cart with subtotal NT$200 → bar shows "Add NT$800 more to qualify for free shipping" with progress bar at 20%
- [ ] Increase to NT$1000 → bar shows "🎉 You qualify for free shipping!"
- [ ] Disable rule → bar disappears
- [ ] Same checks on the checkout page

## Price Table Shortcode

Create a `bulk` rule that targets a specific product:
- `config_json`: `{"count_scope":"cumulative","ranges":[{"from":1,"to":4,"method":"percentage","value":0},{"from":5,"to":9,"method":"percentage","value":10},{"from":10,"to":null,"method":"percentage","value":20}]}`
- `filters_json`: `{"items":[{"type":"products","method":"in","ids":[PRODUCT_ID]}]}`

On the product page (or any page) add the shortcode `[power_discount_table id=PRODUCT_ID]`.

Verify:
- [ ] Table shows three rows: "1 – 4 / 0%" (skipped because value=0), "5 – 9 / 10%", "10+ / 20%"
- [ ] Wrong product ID → empty output
- [ ] Rule disabled → empty output

## Reports Page

Place a few orders that trigger discounts (any rule).

Visit `WooCommerce → PD Reports`.

Verify:
- [ ] Three summary cards show totals
- [ ] Per-rule table sorted by total discount DESC
- [ ] Most recent rule title is shown if a rule was renamed mid-period

## Known Gaps → Phase 4d (optional)

- React rule builder (currently raw JSON textarea)
- Date range filter on reports page
- Export CSV
- Live preview in rule editor
