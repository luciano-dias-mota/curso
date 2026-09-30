<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

abstract class Model
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];

    protected function db(): PDO
    {
        return Database::connection();
    }

    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1";
        $stmt = $this->db()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function all(string $orderBy = 'id', string $direction = 'ASC'): array
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $allowedOrder = array_merge([$this->primaryKey], $this->fillable);
        $orderBy = in_array($orderBy, $allowedOrder, true) ? $orderBy : $this->primaryKey;

        return $this->db()
            ->query("SELECT * FROM {$this->table} ORDER BY {$orderBy} {$direction}")
            ->fetchAll();
    }

    public function create(array $data): int
    {
        $data = array_intersect_key($data, array_flip($this->fillable));

        if ($data === []) {
            return 0;
        }

        $columns = array_keys($data);
        $placeholders = array_map(fn ($column) => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db()->prepare($sql);
        $stmt->execute($data);

        return (int) $this->db()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $data = array_intersect_key($data, array_flip($this->fillable));

        if ($data === []) {
            return false;
        }

        $sets = array_map(fn ($column) => "{$column} = :{$column}", array_keys($data));
        $data['__id'] = $id;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets)
             . " WHERE {$this->primaryKey} = :__id";

        return $this->db()->prepare($sql)->execute($data);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id"
        );

        return $stmt->execute(['id' => $id]);
    }
}
