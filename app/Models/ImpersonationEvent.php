<?php

namespace App\Models;

use App\Models\Builders\AppendOnlyBuilder;
use App\Models\Concerns\IsAppendOnly;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A page viewed, or an operation refused, during an employee impersonation. Append-only.
 *
 * @property int $id
 * @property int $impersonation_session_id
 * @property string $type "viewed" or "denied"
 * @property string $action Route name, path or Livewire component and method; never request data.
 * @property string|null $http_method
 * @property Carbon|null $created_at
 */
#[UseEloquentBuilder(AppendOnlyBuilder::class)]
class ImpersonationEvent extends Model
{
    use IsAppendOnly;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
