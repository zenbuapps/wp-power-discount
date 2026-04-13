<?php
declare(strict_types=1);

namespace PowerDiscount\Persistence;

interface DatabaseAdapter
{
    /**
     * Find a row by primary key `id`. Returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $table, int $id): ?array;

    /**
     * Find rows matching all the given equality conditions.
     *
     * @param array<string, mixed> $where  column => value equality conditions (ANDed)
     * @param array<string, string> $orderBy  column => 'ASC'|'DESC'
     * @return array<int, array<string, mixed>>
     */
    public function findWhere(string $table, array $where = [], array $orderBy = []): array;

    /**
     * Insert a row. Returns inserted id.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int;

    /**
     * Update matching rows. Returns affected row count.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int;

    /**
     * Delete matching rows. Returns affected row count.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int;

    /**
     * Atomically increment an integer column on rows matching `$where` by 1.
     * Used for counters to avoid read-modify-write races.
     *
     * @param array<string, mixed> $where
     */
    public function incrementColumn(string $table, string $column, array $where): void;

    /**
     * Fully-qualified table name with the WP table prefix.
     */
    public function table(string $name): string;
}
