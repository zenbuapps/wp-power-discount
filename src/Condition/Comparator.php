<?php
declare(strict_types=1);

namespace PowerDiscount\Condition;

final class Comparator
{
    public static function compare(float $left, string $operator, float $right): bool
    {
        switch ($operator) {
            case '>=': return $left >= $right;
            case '>':  return $left >  $right;
            case '=':  return abs($left - $right) < 0.005;
            case '<=': return $left <= $right;
            case '<':  return $left <  $right;
            case '!=': return abs($left - $right) >= 0.005;
        }
        return false;
    }
}
