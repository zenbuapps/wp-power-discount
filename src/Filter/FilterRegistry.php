<?php
declare(strict_types=1);

namespace PowerDiscount\Filter;

final class FilterRegistry
{
    /** @var array<string, FilterInterface> */
    private array $filters = [];

    public function register(FilterInterface $filter): void
    {
        $this->filters[$filter->type()] = $filter;
    }

    public function resolve(string $type): ?FilterInterface
    {
        return $this->filters[$type] ?? null;
    }

    /** @return FilterInterface[] */
    public function all(): array
    {
        return array_values($this->filters);
    }
}
