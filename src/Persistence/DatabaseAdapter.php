<?php
declare(strict_types=1);

namespace PowerDiscount\Persistence;

interface DatabaseAdapter
{
    /**
     * Run a prepared SELECT and return an array of associative rows.
     *
     * @param string $sql SQL with %s / %d placeholders.
     * @param array<int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function selectAll(string $sql, array $params = []): array;

    /**
     * Run a prepared SELECT and return the first row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array;

    /**
     * Insert a row into a table. Returns the inserted id.
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int;

    /**
     * Update rows in a table. Returns affected row count.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int;

    /**
     * Delete rows in a table. Returns affected row count.
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int;

    /**
     * Fully-qualified table name with the WP table prefix.
     */
    public function table(string $name): string;
}
