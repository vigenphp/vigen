<?php

declare(strict_types=1);

namespace Vigen\Database;

use InvalidArgumentException;
use RuntimeException;

/**
 * A deliberately small active-record base class.
 *
 * It covers what generated application code actually needs - find, where,
 * create, save, update, delete - and stops there. Relations, eager loading and
 * events are out of scope; the whole surface has to stay small enough that a
 * local model can hold it in context.
 */
abstract class Model
{
    /** The table backing this model. Defaults to the pluralised class name. */
    protected static string $table = '';

    /** The primary key column. */
    protected static string $primaryKey = 'id';

    /** Whether created_at / updated_at are maintained automatically. */
    protected static bool $timestamps = true;

    /** Columns that may be set through create() / fill(). */
    protected array $fillable = [];

    /** Columns removed from toArray() / toJson(). */
    protected array $hidden = [];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /** True once the row exists in the database. */
    protected bool $exists = false;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [], bool $exists = false)
    {
        $this->attributes = $attributes;
        $this->exists = $exists;
    }

    public static function query(): QueryBuilder
    {
        return new QueryBuilder(Connection::current(), static::class, static::table());
    }

    public static function table(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        // Post, UserProfile, Category -> posts, user_profiles, categories
        $name = (new \ReflectionClass(static::class))->getShortName();
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        return match (substr($snake, -1)) {
            'y' => substr($snake, 0, -1) . 'ies',
            's' => $snake . 'es',
            default => $snake . 's',
        };
    }

    /**
     * Build a model from a database row without touching $fillable - the row is
     * already in the database, so mass-assignment rules do not apply.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): static
    {
        return new static($row, true);
    }

    public static function find(int|string $id): ?static
    {
        return static::query()->where(static::$primaryKey, $id)->first();
    }

    public static function findOrFail(int|string $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            throw new RuntimeException(sprintf(
                'No %s found with %s [%s].',
                static::class,
                static::$primaryKey,
                $id
            ));
        }

        return $model;
    }

    /**
     * @return list<static>
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * Start a query with a first condition - the same two forms as
     * QueryBuilder::where(), operator second when three arguments are given.
     */
    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        return func_num_args() < 3
            ? static::query()->where($column, $operatorOrValue)
            : static::query()->where($column, $operatorOrValue, $value);
    }

    public static function count(): int
    {
        return static::query()->count();
    }

    /**
     * Insert a new row and return the model.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static();
        $model->fill($attributes);
        $model->save();

        return $model;
    }

    /**
     * Set attributes, honouring $fillable.
     *
     * A model with an empty $fillable accepts nothing, which is the safe
     * default: a generated model that forgets to declare its columns fails
     * loudly instead of silently accepting whatever a request body contains.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $key = (string) $key;

            if ($key === static::$primaryKey) {
                continue;
            }

            if (! $this->isFillable($key)) {
                throw new InvalidArgumentException(sprintf(
                    'Column [%s] is not fillable on %s. Add it to the $fillable array.',
                    $key,
                    static::class
                ));
            }

            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * Set attributes, ignoring $fillable. For framework and seeding code.
     *
     * @param array<string, mixed> $attributes
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[(string) $key] = $value;
        }

        return $this;
    }

    public function save(): bool
    {
        $now = date('Y-m-d H:i:s');

        if (static::$timestamps) {
            $this->attributes['updated_at'] = $now;

            if (! $this->exists) {
                $this->attributes['created_at'] ??= $now;
            }
        }

        if ($this->exists) {
            $key = $this->getKey();

            if ($key === null) {
                throw new RuntimeException(
                    'Cannot update a ' . static::class . ': it has no primary key value.'
                );
            }

            unset($this->attributes[static::$primaryKey]);

            static::query()->where(static::$primaryKey, $key)->update($this->attributes);

            $this->attributes[static::$primaryKey] = $key;

            return true;
        }

        $connection = Connection::current();
        $columns = array_keys($this->attributes);

        $sql = sprintf(
            'insert into %s (%s) values (%s)',
            $connection->quoteIdentifier(static::table()),
            implode(', ', array_map($connection->quoteIdentifier(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );

        $connection->statement($sql, array_values($this->attributes));

        $id = $connection->lastInsertId();

        if ($id !== false && ! array_key_exists(static::$primaryKey, $this->attributes)) {
            $this->attributes[static::$primaryKey] = is_numeric($id) ? (int) $id : $id;
        }

        $this->exists = true;

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes): bool
    {
        if (! $this->exists) {
            throw new RuntimeException('Cannot update a ' . static::class . ' that has not been saved.');
        }

        return $this->fill($attributes)->save();
    }

    public function delete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        $key = $this->getKey();

        if ($key === null) {
            return false;
        }

        static::query()->where(static::$primaryKey, $key)->delete();

        $this->exists = false;

        return true;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function fresh(): ?static
    {
        $key = $this->getKey();

        return $key === null ? null : static::find($key);
    }

    public function getKey(): int|string|null
    {
        $value = $this->attributes[static::$primaryKey] ?? null;

        return is_int($value) || is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = $this->attributes;

        foreach ($this->hidden as $column) {
            unset($attributes[$column]);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    private function isFillable(string $column): bool
    {
        return in_array($column, $this->fillable, true);
    }
}
