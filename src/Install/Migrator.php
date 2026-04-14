<?php
declare(strict_types=1);

namespace PowerDiscount\Install;

final class Migrator
{
    private const SCHEMA_VERSION = '2';
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
            schedule_meta LONGTEXT NULL DEFAULT NULL,
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
