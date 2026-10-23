<?php

namespace App\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Query builder of append-only models: mass updates and deletes through the model are
 * refused as well, not only changes to a loaded record (IsAppendOnly).
 *
 * Raw queries (DB::table, toBase()) are outside the application's normal operations and
 * are not covered; tamper-evidence against direct database access is a production topic.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class AppendOnlyBuilder extends Builder
{
    /**
     * @param  array<mixed>  $values
     */
    public function update(array $values): int
    {
        throw $this->refused('changed');
    }

    /**
     * @param  array<mixed>  $values
     * @param  array<mixed>|string  $uniqueBy
     * @param  array<mixed>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        throw $this->refused('changed');
    }

    /**
     * @param  string|null  $column
     */
    public function touch($column = null): int|false
    {
        throw $this->refused('changed');
    }

    /**
     * @param  array<mixed>  $extra
     */
    public function increment($column, $amount = 1, array $extra = []): int
    {
        throw $this->refused('changed');
    }

    /**
     * @param  array<mixed>  $extra
     */
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        throw $this->refused('changed');
    }

    /**
     * @param  array<mixed>  $columns
     * @param  array<mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        throw $this->refused('changed');
    }

    /**
     * @param  array<mixed>  $columns
     * @param  array<mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        throw $this->refused('changed');
    }

    public function delete(): mixed
    {
        throw $this->refused('deleted');
    }

    public function forceDelete(): mixed
    {
        throw $this->refused('deleted');
    }

    /**
     * Passed on to the query builder by Eloquent; it would empty the whole table.
     */
    public function truncate(): never
    {
        throw $this->refused('deleted');
    }

    /**
     * Passed on to the query builder by Eloquent; it updates an existing row in place.
     *
     * @param  array<mixed>  $attributes
     * @param  array<mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw $this->refused('changed');
    }

    protected function refused(string $verb): LogicException
    {
        return new LogicException(class_basename($this->model).' records cannot be '.$verb.'.');
    }
}
