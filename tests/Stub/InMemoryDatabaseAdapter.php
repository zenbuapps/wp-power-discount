<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Stub;

use PowerDiscount\Persistence\DatabaseAdapter;

/**
 * In-memory stand-in for DatabaseAdapter. Supports the full typed query
 * interface without requiring a real database.
 */
final class InMemoryDatabaseAdapter implements DatabaseAdapter
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, int> */
    private array $autoIncrement = [];

    public string $prefix = 'wp_';

    public function findById(string $table, int $id): ?array
    {
        return $this->tables[$table][$id] ?? null;
    }

    public function findWhere(string $table, array $where = [], array $orderBy = []): array
    {
        $rows = array_values($this->tables[$table] ?? []);
        $rows = array_values(array_filter(
            $rows,
            static function (array $row) use ($where): bool {
                foreach ($where as $col => $value) {
                    if (!array_key_exists($col, $row)) {
                        return false;
                    }
                    if ($row[$col] != $value) { // intentional loose compare for 0/'0'
                        return false;
                    }
                }
                return true;
            }
        ));

        if ($orderBy !== []) {
            usort($rows, static function (array $a, array $b) use ($orderBy): int {
                foreach ($orderBy as $col => $direction) {
                    $av = $a[$col] ?? null;
                    $bv = $b[$col] ?? null;
                    if ($av == $bv) {
                        continue;
                    }
                    $cmp = ($av < $bv) ? -1 : 1;
                    return strtoupper((string) $direction) === 'DESC' ? -$cmp : $cmp;
                }
                return 0;
            });
        }

        return $rows;
    }

    public function insert(string $table, array $data): int
    {
        $this->tables[$table] = $this->tables[$table] ?? [];
        $this->autoIncrement[$table] = ($this->autoIncrement[$table] ?? 0) + 1;
        $id = $this->autoIncrement[$table];
        $row = array_merge(['id' => $id], $data);
        $this->tables[$table][$id] = $row;
        return $id;
    }

    public function update(string $table, array $data, array $where): int
    {
        if (!isset($this->tables[$table])) {
            return 0;
        }
        $affected = 0;
        foreach ($this->tables[$table] as $id => $row) {
            foreach ($where as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] != $v) {
                    continue 2;
                }
            }
            $this->tables[$table][$id] = array_merge($row, $data);
            $affected++;
        }
        return $affected;
    }

    public function delete(string $table, array $where): int
    {
        if (!isset($this->tables[$table])) {
            return 0;
        }
        $affected = 0;
        foreach ($this->tables[$table] as $id => $row) {
            foreach ($where as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] != $v) {
                    continue 2;
                }
            }
            unset($this->tables[$table][$id]);
            $affected++;
        }
        return $affected;
    }

    public function incrementColumn(string $table, string $column, array $where): void
    {
        if (!isset($this->tables[$table])) {
            return;
        }
        foreach ($this->tables[$table] as $id => $row) {
            foreach ($where as $k => $v) {
                if (!array_key_exists($k, $row) || $row[$k] != $v) {
                    continue 2;
                }
            }
            $current = (int) ($row[$column] ?? 0);
            $this->tables[$table][$id][$column] = $current + 1;
        }
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }
}
