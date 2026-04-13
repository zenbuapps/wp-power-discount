<?php
declare(strict_types=1);

namespace PowerDiscount\Strategy;

final class StrategyRegistry
{
    /** @var array<string, DiscountStrategyInterface> */
    private array $strategies = [];

    public function register(DiscountStrategyInterface $strategy): void
    {
        $this->strategies[$strategy->type()] = $strategy;
    }

    public function resolve(string $type): ?DiscountStrategyInterface
    {
        return $this->strategies[$type] ?? null;
    }

    /** @return DiscountStrategyInterface[] */
    public function all(): array
    {
        return array_values($this->strategies);
    }
}
