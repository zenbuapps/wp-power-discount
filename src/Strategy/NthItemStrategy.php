<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class NthItemStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'nth_item';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $tiers = (array) ($config['tiers'] ?? []);
        if ($tiers === []) {
            return null;
        }

        $sortBy = (string) ($config['sort_by'] ?? 'price_desc');
        $recursive = (bool) ($config['recursive'] ?? false);

        // Index tiers by nth for O(1) lookup, and find max nth.
        $tiersByNth = [];
        $maxNth = 0;
        foreach ($tiers as $tier) {
            $nth = (int) ($tier['nth'] ?? 0);
            if ($nth <= 0) {
                continue;
            }
            $tiersByNth[$nth] = [
                'method' => (string) ($tier['method'] ?? 'percentage'),
                'value'  => (float) ($tier['value'] ?? 0),
            ];
            if ($nth > $maxNth) {
                $maxNth = $nth;
            }
        }
        if ($maxNth === 0) {
            return null;
        }

        // Tiers must be contiguous 1..maxNth. Reject sparse configs to avoid
        // silent over-discounting on missing positions.
        for ($n = 1; $n <= $maxNth; $n++) {
            if (!isset($tiersByNth[$n])) {
                return null;
            }
        }

        // Flatten to units and sort.
        $units = [];
        foreach ($context->getItems() as $item) {
            for ($i = 0; $i < $item->getQuantity(); $i++) {
                $units[] = ['product_id' => $item->getProductId(), 'price' => $item->getPrice()];
            }
        }

        usort($units, static function (array $a, array $b) use ($sortBy): int {
            if ($sortBy === 'price_asc') {
                return $a['price'] <=> $b['price'];
            }
            return $b['price'] <=> $a['price'];
        });

        $totalDiscount = 0.0;
        $affected = [];

        foreach ($units as $idx => $unit) {
            $position = $idx + 1; // 1-indexed
            if ($recursive) {
                $tierIdx = (($position - 1) % $maxNth) + 1;
            } else {
                $tierIdx = min($position, $maxNth);
            }
            $tier = $tiersByNth[$tierIdx] ?? $tiersByNth[$maxNth] ?? null;
            if ($tier === null) {
                continue;
            }
            $discount = $this->unitDiscount($unit['price'], $tier['method'], $tier['value']);
            if ($discount > 0) {
                $totalDiscount += $discount;
                $affected[$unit['product_id']] = true;
            }
        }

        if ($totalDiscount <= 0) {
            return null;
        }

        return new DiscountResult(
            $rule->getId(),
            $rule->getType(),
            DiscountResult::SCOPE_PRODUCT,
            $totalDiscount,
            array_keys($affected),
            $rule->getLabel(),
            ['sort_by' => $sortBy, 'recursive' => $recursive]
        );
    }

    private function unitDiscount(float $price, string $method, float $value): float
    {
        switch ($method) {
            case 'percentage':
                if ($value <= 0) {
                    return 0.0;
                }
                return $price * min(100.0, $value) / 100;
            case 'flat':
                return min($price, max(0.0, $value));
            case 'free':
                return $price;
        }
        return 0.0;
    }
}
