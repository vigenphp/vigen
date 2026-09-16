<?php

declare(strict_types=1);

namespace Vigen\Database;

use RuntimeException;

/**
 * A small fluent query builder. Every value is bound, never interpolated;
 * only identifiers and operators are written into the SQL, and both are
 * whitelisted first.
 */
class QueryBuilder
{
    /**
     * Operators accepted in a where clause. Anything outside this list is
     * refused rather than pasted into the statement.
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'];

    /** @var list<array{boolean: string, sql: string, bindings: list<mixed>}> */
    private array $wheres = [];

    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /**
     * @param class-string<Model> $model
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $model,
        private readonly string $table,
    ) {
    }

    /**
     * Add a WHERE clause.
     *
     *     where('name', 'Ada')             -> name = 'Ada'
     *     where('name', 'like', '%Ada%')   -> name like '%Ada%'
     *     where('deleted_at', null)        -> deleted_at is null
     *
     * The two-argument form is the common case. The three-argument form takes
     * the operator *second* - the order Laravel uses, and therefore the order
     * a model trained on Laravel will write.
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return $this->addWhere('and', ...$this->condition($column, $operatorOrValue, $value, func_num_args()));
    }

    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        return $this->addWhere('or', ...$this->condition($column, $operatorOrValue, $value, func_num_args()));
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): static
    {
        if ($values === []) {
            // An empty IN () is a syntax error on every driver, and the only
            // truthful answer is "matches nothing".
            $this->wheres[] = ['boolean' => 'and', 'sql' => '1 = 0', 'bindings' => []];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        $this->wheres[] = [
            'boolean' => 'and',
            'sql' => $this->column($column) . " in ({$placeholders})",
            'bindings' => array_values($values),
        ];

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = [
            'boolean' => 'and',
            'sql' => $this->column($column) . ' is null',
            'bindings' => [],
        ];

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = [
            'boolean' => 'and',
            'sql' => $this->column($column) . ' is not null',
            'bindings' => [],
        ];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        $this->orders[] = ['column' => $this->column($column), 'direction' => $direction];

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * @return list<Model>
     */
    public function get(): array
    {
        $rows = $this->connection->select($this->toSelectSql(), $this->bindings());

        return array_map(
            fn (array $row): Model => $this->model::hydrate($row),
            $rows
        );
    }

    public function first(): ?Model
    {
        $rows = $this->connection->select($this->toSelectSql(1), $this->bindings());

        return $rows === [] ? null : $this->model::hydrate($rows[0]);
    }

    public function count(): int
    {
        $sql = sprintf(
            'select count(*) as aggregate from %s%s',
            $this->connection->quoteIdentifier($this->table),
            $this->whereSql()
        );

        $rows = $this->connection->select($sql, $this->bindings());

        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insert(array $attributes): bool
    {
        return $this->model::create($attributes)->exists();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): int
    {
        if ($attributes === []) {
            return 0;
        }

        $assignments = [];
        $bindings = [];

        foreach ($attributes as $column => $value) {
            $assignments[] = $this->connection->quoteIdentifier((string) $column) . ' = ?';
            $bindings[] = $value;
        }

        $sql = sprintf(
            'update %s set %s%s',
            $this->connection->quoteIdentifier($this->table),
            implode(', ', $assignments),
            $this->whereSql()
        );

        return $this->connection->statement($sql, [...$bindings, ...$this->bindings()]);
    }

    public function delete(): int
    {
        $sql = sprintf(
            'delete from %s%s',
            $this->connection->quoteIdentifier($this->table),
            $this->whereSql()
        );

        return $this->connection->statement($sql, $this->bindings());
    }

    /**
     * The query as it will be sent, with bindings shown - for error messages
     * and debugging.
     */
    public function toSql(): string
    {
        return $this->toSelectSql();
    }

    /**
     * Normalise where()'s two accepted forms into the (column, value,
     * operator) triple addWhere() expects. func_num_args() is what tells the
     * two forms apart - where('a', 'b') means a = 'b', where('a', 'b', 'c')
     * means a b c.
     *
     * @return array{0: string, 1: mixed, 2: string}
     */
    private function condition(string $column, mixed $operatorOrValue, mixed $value, int $argumentCount): array
    {
        if ($argumentCount < 3) {
            return [$column, $operatorOrValue, '='];
        }

        $operator = strtolower(trim((string) $operatorOrValue));

        return [$column, $value, $operator === '' ? '=' : $operator];
    }

    private function addWhere(string $boolean, string $column, mixed $value, string $operator): static
    {
        $operator = strtolower(trim($operator));

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new RuntimeException(sprintf(
                'Unsupported where operator [%s]. Supported: %s.',
                $operator,
                implode(', ', self::OPERATORS)
            ));
        }

        if ($value === null) {
            return $operator === '!=' || $operator === '<>'
                ? $this->whereNotNull($column)
                : $this->whereNull($column);
        }

        $this->wheres[] = [
            'boolean' => $boolean,
            'sql' => $this->column($column) . " {$operator} ?",
            'bindings' => [$value],
        ];

        return $this;
    }

    private function toSelectSql(?int $limit = null): string
    {
        $sql = sprintf(
            'select * from %s%s%s',
            $this->connection->quoteIdentifier($this->table),
            $this->whereSql(),
            $this->orderSql()
        );

        $effectiveLimit = $limit ?? $this->limit;

        if ($effectiveLimit !== null) {
            $sql .= ' limit ' . $effectiveLimit;
        }

        if ($this->offset !== null && $effectiveLimit !== null) {
            $sql .= ' offset ' . $this->offset;
        }

        return $sql;
    }

    private function whereSql(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $clauses = [];

        foreach ($this->wheres as $index => $where) {
            $clauses[] = ($index === 0 ? '' : $where['boolean'] . ' ') . $where['sql'];
        }

        return ' where ' . implode(' ', $clauses);
    }

    private function orderSql(): string
    {
        if ($this->orders === []) {
            return '';
        }

        $parts = array_map(
            static fn (array $order): string => $order['column'] . ' ' . $order['direction'],
            $this->orders
        );

        return ' order by ' . implode(', ', $parts);
    }

    /**
     * @return list<mixed>
     */
    private function bindings(): array
    {
        $bindings = [];

        foreach ($this->wheres as $where) {
            foreach ($where['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        return $bindings;
    }

    private function column(string $column): string
    {
        return $this->connection->quoteIdentifier($column);
    }
}
