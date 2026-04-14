# Power Discount

WooCommerce discount rules engine — Taiwan-first.

## Status

**Phase 4c (Frontend + Reports)** — complete. **MVP feature-complete.**

Frontend:
- `[power_discount_table id=PRODUCT_ID]` shortcode renders bulk-tier price tables for a given product
- Free shipping progress bar appears on cart and checkout pages, computed via `FreeShippingProgressHelper` from active `free_shipping` rules with `cart_subtotal` thresholds

Admin:
- `WooCommerce → PD Reports` shows total discount given, orders affected, and per-rule performance table sorted by total amount

All 8 strategies, 13 conditions, 6 filters, full Admin CRUD, WC integration (cart/order/shipping hooks), and reports are now in place.

Pending (post-MVP):
- React rule builder (Phase 4d, optional polish)
- BulkStrategy `per_category` scope
- BuyXGetY `cheapest_from_filter` reward target

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
