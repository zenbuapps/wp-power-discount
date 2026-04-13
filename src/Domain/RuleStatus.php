<?php
declare(strict_types=1);

namespace PowerDiscount\Domain;

final class RuleStatus
{
    public const DISABLED = 0;
    public const ENABLED = 1;

    public static function isValid(int $status): bool
    {
        return in_array($status, [self::DISABLED, self::ENABLED], true);
    }
}
