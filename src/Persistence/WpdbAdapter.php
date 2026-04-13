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

    public function selectAll(string $sql, array $params = []): array
    {
        $prepared = $this->prepare($sql, $params);
        $rows = $this->wpdb->get_results($prepared, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $prepared = $this->prepare($sql, $params);
        $row = $this->wpdb->get_row($prepared, ARRAY_A);
        return is_array($row) ? $row : null;
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

    public function table(string $name): string
    {
        return $this->wpdb->prefix . $name;
    }

    private function prepare(string $sql, array $params): string
    {
        if ($params === []) {
            return $sql;
        }
        return $this->wpdb->prepare($sql, $params);
    }
}
