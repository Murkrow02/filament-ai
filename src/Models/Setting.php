<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Models;

use Murkrow\FilamentAi\Models\Concerns\UsesRagConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * A single runtime override of a whitelisted config key.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property int|string|null $updated_by
 */
class Setting extends Model
{
    use UsesRagConnection;

    protected $guarded = [];

    protected function ragTableKey(): string
    {
        return 'settings';
    }

    /**
     * Decode the stored string back into its declared PHP type.
     */
    public function typedValue(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return match ($this->type) {
            'int' => (int) $this->value,
            'float' => (float) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOL),
            'json' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    public static function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return (string) json_encode($value);
        }

        return (string) $value;
    }
}
