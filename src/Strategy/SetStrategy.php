<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class SetStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'set';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $bundleSize = (int) ($config['bundle_size'] ?? 0);
        $method = (string) ($config['method'] ?? '');
        $value = (float) ($config['value'] ?? 0);
        $repeat = (bool) ($config['repeat'] ?? false);

        if ($bundleSize <= 0 || $value < 0) {
            return null;
        }
        if (!in_array($method, ['set_price', 'set_percentage', 'set_flat_off'], true)) {
            return null;
        }

        // Expand into a flat list of (product_id, unit_price), sorted by price desc
        // so bundles pull the most expensive units first (maximises savings).
        $units = [];
        foreach ($context->getItems() as $item) {
            for ($i = 0; $i < $item->getQuantity(); $i++) {
                $units[] = [
                    'product_id' => $item->getProductId(),
                    'price'      => $item->getPrice(),
                ];
            }
        }
        if (count($units) < $bundleSize) {
            return null;
        }
        usort($units, static function (array $a, array $b): int {
            return $b['price'] <=> $a['price'];
        });

        $bundleCount = intdiv(count($units), $bundleSize);
        if (!$repeat) {
            $bundleCount = min(1, $bundleCount);
        }
        if ($bundleCount <= 0) {
            return null;
        }

        $totalDiscount = 0.0;
        $affected = [];
        for ($b = 0; $b < $bundleCount; $b++) {
            $bundleUnits = array_slice($units, $b * $bundleSize, $bundleSize);
            $bundleTotal = 0.0;
            foreach ($bundleUnits as $u) {
                $bundleTotal += $u['price'];
                $affected[$u['product_id']] = true;
            }
            $bundleDiscount = $this->bundleDiscount($method, $value, $bundleTotal);
            if ($bundleDiscount > 0) {
                $totalDiscount += $bundleDiscount;
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
            ['method' => $method, 'bundle_size' => $bundleSize]
        );
    }

    private function bundleDiscount(string $method, float $value, float $bundleTotal): float
    {
        switch ($method) {
            case 'set_price':
                // Discount = max(0, current total - fixed set price)
                return $bundleTotal > $value ? $bundleTotal - $value : 0.0;
            case 'set_percentage':
                // value is percent off (e.g. 10 = 10% off bundle)
                return $bundleTotal * ($value / 100);
            case 'set_flat_off':
                return min($value, $bundleTotal);
        }
        return 0.0;
    }
}
