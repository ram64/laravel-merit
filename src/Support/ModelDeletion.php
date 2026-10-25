<?php

namespace Ram64\LaravelMerit\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Tells a hard delete from a soft one inside a model's deleted event.
 */
final class ModelDeletion
{
    /**
     * Whether the row is really gone: true for a model without soft deletes,
     * and for a soft-deleting model only when it is being force deleted.
     */
    public static function isPermanent(Model $model): bool
    {
        return ! method_exists($model, 'isForceDeleting') || $model->isForceDeleting();
    }
}
