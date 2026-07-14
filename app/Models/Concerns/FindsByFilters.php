<?php

namespace App\Models\Concerns;

trait FindsByFilters
{
  abstract protected function filterableColumns(): array;

  protected function findByFilters($filters = [], $limit = null): array
  {
    if ($filters === null) {
      $filters = [];
    }

    if (!is_array($filters)) {
      throw new \InvalidArgumentException('Os filtros precisam ser um objeto JSON.');
    }

    $allowedColumns = $this->filterableColumns();
    foreach (array_keys($filters) as $column) {
      if (!in_array($column, $allowedColumns, true)) {
        throw new \InvalidArgumentException("Filtro não permitido: {$column}");
      }
    }

    if ($limit !== null && (!is_numeric($limit) || (int) $limit < 1)) {
      throw new \InvalidArgumentException('O limite precisa ser um inteiro maior que zero.');
    }

    $sql = "SELECT * FROM {$this->table}";
    if ($filters !== []) {
      $conditions = array_map(
        static fn(string $column): string => "{$column} = :{$column}",
        array_keys($filters)
      );
      $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    if ($limit !== null) {
      $sql .= ' LIMIT :limit';
    }

    $statement = $this->conn->prepare($sql);
    foreach ($filters as $column => $value) {
      $statement->bindValue(":{$column}", $value);
    }

    if ($limit !== null) {
      $statement->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
    }

    $statement->execute();

    return $statement->fetchAll(\PDO::FETCH_ASSOC);
  }
}
