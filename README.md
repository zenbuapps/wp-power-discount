# Power Discount

WooCommerce discount rules engine — Taiwan-first.

## Status

**Phase 2 (Engine + WC Integration)** — complete.

- Repository with DatabaseAdapter abstraction (fully unit-tested via InMemoryDatabaseAdapter)
- Condition system + 2 conditions: `cart_subtotal`, `date_range`
- Filter system + 2 filters: `all_products`, `categories`
- Engine: Calculator, Aggregator, ExclusivityResolver
- WooCommerce hooks: `woocommerce_before_calculate_totals`, `woocommerce_cart_calculate_fees`, `woocommerce_checkout_order_processed`
- Order discount logging to `wp_pd_order_discounts`

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
