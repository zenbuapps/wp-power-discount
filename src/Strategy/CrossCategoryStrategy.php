<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

use PowerDiscount\Domain\CartContext;
use PowerDiscount\Domain\DiscountResult;
use PowerDiscount\Domain\Rule;

final class CrossCategoryStrategy implements DiscountStrategyInterface
{
    public function type(): string
    {
        return 'cross_category';
    }

    public function apply(Rule $rule, CartContext $context): ?DiscountResult
    {
        if ($context->isEmpty()) {
            return null;
        }

        $config = $rule->getConfig();
        $groups = (array) ($config['groups'] ?? []);
        $reward = (array) ($config['reward'] ?? []);
        $repeat = (bool) ($config['repeat'] ?? false);

        if (count($groups) < 2) {
            return null; // cross-category requires multiple groups
        }

        $method = (string) ($reward['method'] ?? '');
        $value = (float) ($reward['value'] ?? 0);
        if (!in_array($method, ['percentage', 'flat', 'fixed_bundle_price'], true)) {
            return null;
        }

        // Build a per-group pool of units (flattened by quantity), sorted by price desc.
        $groupPools = [];
        foreach ($groups as $i => $group) {
            $minQty = (int) ($group['min_qty'] ?? 1);
            if ($minQty <= 0) {
                return null;
            }
            $filter = (array) ($group['filter'] ?? []);
            $categoryIds = array_map('intval', (array) ($filter['value'] ?? []));

            $units = [];
            foreach ($context->getItems() as $item) {
                $hit = false;
                foreach ($item->getCategoryIds() as $cat) {
                    if (in_array($cat, $categoryIds, true)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                for ($q = 0; $q < $item->getQuantity(); $q++) {
                    $units[] = ['product_id' => $item->getProductId(), 'price' => $item->getPrice()];
                }
            }
            if (count($units) < $minQty) {
                return null;
            }
            usort($units, static function (array $a, array $b): int {
                return $b['price'] <=> $a['price'];
            });
            $groupPools[$i] = ['min_qty' => $minQty, 'units' => $units];
        }

        $bundles = $this->computeBundleCount($groupPools, $repeat);
        if ($bundles <= 0) {
            return null;
        }

        $totalDiscount = 0.0;
        $affected = [];

        for ($b = 0; $b < $bundles; $b++) {
            $bundleTotal = 0.0;
            foreach ($groupPools as $groupIdx => &$pool) {
                $take = array_splice($pool['units'], 0, $pool['min_qty']);
                foreach ($take as $unit) {
                    $bundleTotal += $unit['price'];
                    $affected[$unit['product_id']] = true;
                }
            }
            unset($pool);

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
            ['method' => $method, 'bundles' => $bundles]
        );
    }

    /**
     * @param array<int, array{min_qty:int,units:array<int, array{product_id:int,price:float}>}> $pools
     */
    private function computeBundleCount(array $pools, bool $repeat): int
    {
        $minPossible = PHP_INT_MAX;
        foreach ($pools as $pool) {
            $possible = intdiv(count($pool['units']), $pool['min_qty']);
            if ($possible < $minPossible) {
                $minPossible = $possible;
            }
        }
        if ($minPossible <= 0) {
            return 0;
        }
        return $repeat ? $minPossible : 1;
    }

    private function bundleDiscount(string $method, float $value, float $bundleTotal): float
    {
        switch ($method) {
            case 'percentage':
                return $bundleTotal * ($value / 100);
            case 'flat':
                return min($bundleTotal, $value);
            case 'fixed_bundle_price':
                return $bundleTotal > $value ? $bundleTotal - $value : 0.0;
        }
        return 0.0;
    }
}
