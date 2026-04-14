<?php
declare(strict_types=1);

namespace PowerDiscount\Frontend;

final class GiftProgress
{
    public bool $hasGiftRule;
    public bool $achieved;
    public ?float $threshold;
    public ?float $remaining;
    /** @var int[] */
    public array $giftProductIds;

    /**
     * @param int[] $giftProductIds
     */
    public function __construct(
        bool $hasGiftRule,
        bool $achieved,
        ?float $threshold,
        ?float $remaining,
        array $giftProductIds
    ) {
        $this->hasGiftRule = $hasGiftRule;
        $this->achieved = $achieved;
        $this->threshold = $threshold;
        $this->remaining = $remaining;
        $this->giftProductIds = $giftProductIds;
    }
}
