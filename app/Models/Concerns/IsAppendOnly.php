<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Records that may only be created: financial history is never rewritten or deleted
 * through the application.
 */
trait IsAppendOnly
{
    protected static function bootIsAppendOnly(): void
    {
        static::updating(fn (): never => throw new LogicException(class_basename(static::class).' records cannot be changed.'));
        static::deleting(fn (): never => throw new LogicException(class_basename(static::class).' records cannot be deleted.'));
    }
}
