<?php
declare(strict_types=1);

namespace PowerDiscount\Persistence;

use wpdb;

final class WpdbAdapter implements DatabaseAdapter
{
    private wpdb $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function findById(string $table, int $id): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM `{$this->escapeIdentifier($table)}` WHERE `id` = %d LIMIT 1",
            $id
        );
        $row = $this->wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function findWhere(string $table, array $where = [], array $orderBy = []): array
    {
        $tableEscaped = $this->escapeIdentifier($table);
        $clauses = [];
        $params = [];
        foreach ($where as $column => $value) {
            $colEscaped = $this->escapeIdentifier((string) $column);
            if ($value === null) {
                $clauses[] = "`{$colEscaped}` IS NULL";
            } else {
                $clauses[] = "`{$colEscaped}` = " . $this->placeholder($value);
                $params[] = $value;
            }
        }

        $sql = "SELECT * FROM `{$tableEscaped}`";
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }
        if ($orderBy !== []) {
            $parts = [];
            foreach ($orderBy as $column => $direction) {
                $col = $this->escapeIdentifier((string) $column);
                $dir = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
                $parts[] = "`{$col}` {$dir}";
            }
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        }

        if ($params !== []) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function insert(string $table, array $data): int
    {
        $this->wpdb->insert($table, $data);
        return (int) $this->wpdb->insert_id;
    }

    public function update(string $table, array $data, array $where): int
    {
        $affected = $this->wpdb->update($table, $data, $where);
        return (int) ($affected ?: 0);
    }

    public function delete(string $table, array $where): int
    {
        $affected = $this->wpdb->delete($table, $where);
        return (int) ($affected ?: 0);
    }

    public function incrementColumn(string $table, string $column, array $where): void
    {
        if ($where === []) {
            return;
        }
        $tableEscaped = $this->escapeIdentifier($table);
        $columnEscaped = $this->escapeIdentifier($column);

        $clauses = [];
        $params = [];
        foreach ($where as $whereCol => $value) {
            $c = $this->escapeIdentifier((string) $whereCol);
            $clauses[] = "`{$c}` = " . $this->placeholder($value);
            $params[] = $value;
        }
        $sql = "UPDATE `{$tableEscaped}` SET `{$columnEscaped}` = `{$columnEscaped}` + 1 WHERE " . implode(' AND ', $clauses);
        $this->wpdb->query($this->wpdb->prepare($sql, $params));
    }

    public function table(string $name): string
    {
        return $this->wpdb->prefix . $name;
    }

    private function placeholder($value): string
    {
        if (is_int($value) || is_bool($value)) {
            return '%d';
        }
        if (is_float($value)) {
            return '%f';
        }
        return '%s';
    }

    private function escapeIdentifier(string $name): string
    {
        // Accept only [a-zA-Z0-9_]. Strip anything else.
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
    }
}
