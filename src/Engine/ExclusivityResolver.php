<?php
declare(strict_types=1);

namespace PowerDiscount\Engine;

use PowerDiscount\Domain\Rule;

final class ExclusivityResolver
{
    public function shouldStopAfter(Rule $rule): bool
    {
        return $rule->isExclusive();
    }
}
