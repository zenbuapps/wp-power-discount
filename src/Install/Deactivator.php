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
