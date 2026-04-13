<?php
declare(strict_types=1);

namespace PowerDiscount\Tests\Stub;

use PowerDiscount\Persistence\DatabaseAdapter;

/**
 * Minimal in-memory stand-in for wpdb. Tests populate rows directly via
 * insert() and then query them by primary key (id) or by full-table
 * scan using a closure passed as the first SQL param.
 *
 * This is NOT a real SQL engine. It exists purely so Repository tests can
 * assert on CRUD calls without a real database.
 */
final class InMemoryDatabaseAdapter implements DatabaseAdapter
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $tables = [];

    /** @var array<string, int> */
    private array $autoIncrement = [];

    public string $prefix = 'wp_';

    public function selectAll(string $sql, array $params = []): array
    {
        [$table, $filter] = $this->parseCustomQuery($sql, $params);
        $rows = array_values($this->tables[$table] ?? []);
        if ($filter !== null) {
            $rows = array_values(array_filter($rows, $filter));
        }
        return $rows;
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $rows = $this->selectAll($sql, $params);
        return $rows[0] ?? null;
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
                if (!array_key_exists($k, $row) || $row[$k] !== $v) {
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
                if (!array_key_exists($k, $row) || $row[$k] !== $v) {
                    continue 2;
                }
            }
            unset($this->tables[$table][$id]);
            $affected++;
        }
        return $affected;
    }

    public function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * @return array{0:string,1:callable|null}
     */
    private function parseCustomQuery(string $sql, array $params): array
    {
        if (strpos($sql, 'SELECT_ALL_FROM:') === 0) {
            $table = substr($sql, strlen('SELECT_ALL_FROM:'));
            $filter = $params[0] ?? null;
            return [$table, is_callable($filter) ? $filter : null];
        }
        return ['', null];
    }
}
