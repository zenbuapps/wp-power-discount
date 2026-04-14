<?php
declare(strict_types=1);

namespace PowerDiscount\Frontend;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\Rule;

final class GiftProgressHelper
{
    /**
     * @param Rule[] $allRules
     */
    public function compute(CartContext $context, array $allRules): GiftProgress
    {
        $giftRules = array_filter(
            $allRules,
            static function (Rule $r): bool {
                if ($r->getType() !== 'gift_with_purchase' || !$r->isEnabled()) {
                    return false;
                }
                $config = $r->getConfig();
                $threshold = (float) ($config['threshold'] ?? 0);
                $hasIds = !empty($config['gift_product_ids']);
                return $threshold > 0 && $hasIds;
            }
        );

        if ($giftRules === []) {
            return new GiftProgress(false, false, null, null, []);
        }

        $subtotal = $context->getSubtotal();
        $achieved = false;
        $achievedGiftIds = [];
        $bestUnachievedThreshold = null;
        $bestUnachievedGiftIds = [];

        foreach ($giftRules as $rule) {
            $config = $rule->getConfig();
            $threshold = (float) ($config['threshold'] ?? 0);
            $giftIds = array_values(array_filter(
                array_map('intval', (array) ($config['gift_product_ids'] ?? [])),
                static function (int $id): bool { return $id > 0; }
            ));
            if ($threshold <= 0 || $giftIds === []) {
                continue;
            }

            if ($subtotal >= $threshold) {
                $achieved = true;
                foreach ($giftIds as $id) {
                    $achievedGiftIds[$id] = true;
                }
                continue;
            }

            if ($bestUnachievedThreshold === null || $threshold < $bestUnachievedThreshold) {
                $bestUnachievedThreshold = $threshold;
                $bestUnachievedGiftIds = $giftIds;
            }
        }

        if ($achieved && $bestUnachievedThreshold === null) {
            return new GiftProgress(true, true, null, null, array_keys($achievedGiftIds));
        }
        if ($achieved) {
            // Some unlocked + a higher tier still pending. Show the next tier.
            return new GiftProgress(
                true,
                false,
                $bestUnachievedThreshold,
                $bestUnachievedThreshold - $subtotal,
                $bestUnachievedGiftIds
            );
        }
        if ($bestUnachievedThreshold === null) {
            return new GiftProgress(true, false, null, null, []);
        }
        return new GiftProgress(
            true,
            false,
            $bestUnachievedThreshold,
            $bestUnachievedThreshold - $subtotal,
            $bestUnachievedGiftIds
        );
    }
}
