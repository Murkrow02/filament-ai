<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Whether a string can be a model's primary key at all.
 *
 * Checked before the id reaches SQL: on PostgreSQL `where id = 'abc'` against
 * a bigint or uuid column is not a miss but an error, which ends the turn.
 */
final class RecordKey
{
    public static function valid(Model $model, string $id): bool
    {
        if ($id === '' || mb_strlen($id) > 191) {
            return false;
        }

        $traits = class_uses_recursive($model);

        if (in_array(HasUlids::class, $traits, true)) {
            return Str::isUlid($id);
        }

        if (in_array(HasUuids::class, $traits, true) || in_array(HasVersion7Uuids::class, $traits, true)) {
            return Str::isUuid($id);
        }

        if ($model->getIncrementing() || in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return ctype_digit($id);
        }

        return true;
    }
}
