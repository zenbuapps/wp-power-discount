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
