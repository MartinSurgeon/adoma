<?php

namespace App\Domain\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Add to a model to record every create, change and delete in the audit log.
 * A model can define auditLabel() to say what it is called in the log.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            Audit::record('created', $model, [], $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $new = Audit::clean($model->getChanges());

            if ($new === []) {
                return; // only a timestamp moved
            }

            $old = [];
            foreach (array_keys($new) as $field) {
                $old[$field] = $model->getRawOriginal($field);
            }

            Audit::record('updated', $model, $old, $new);
        });

        static::deleted(function (Model $model) {
            Audit::record('deleted', $model, $model->getAttributes());
        });
    }
}
