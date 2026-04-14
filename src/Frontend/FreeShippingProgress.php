<?php
declare(strict_types=1);

namespace PowerDiscount\Frontend;

final class FreeShippingProgress
{
    public bool $hasFreeShippingRule;
    public bool $achieved;
    public ?float $threshold;
    public ?float $remaining;

    public function __construct(bool $hasFreeShippingRule, bool $achieved, ?float $threshold, ?float $remaining)
    {
        $this->hasFreeShippingRule = $hasFreeShippingRule;
        $this->achieved = $achieved;
        $this->threshold = $threshold;
        $this->remaining = $remaining;
    }
}
