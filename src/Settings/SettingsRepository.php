<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Models\Setting;
use Murkrow\FilamentAi\Support\Tables;
use Throwable;

/**
 * Database-backed overrides for a whitelist of config keys.
 *
 * Applied into the config repository at boot rather than read at each call
 * site, so the rest of the package keeps using plain `config()` and stays
 * testable without this class existing at all.
 *
 * The whitelist is the point: retrieval and prompt tuning is exactly the kind
 * of thing an operator should be able to adjust between queries, while the
 * embedding model and vector width must not move without a re-index, so they
 * are deliberately absent from it.
 */
final class SettingsRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (! $this->available()) {
            return $this->cache = [];
        }

        $ttl = (int) config('filament-ai.settings.cache_ttl', 300);
        $key = (string) config('filament-ai.settings.cache_key', 'filament-ai.settings');

        /** @var array<string, mixed> $values */
        $values = Cache::remember($key, $ttl, static function (): array {
            $values = [];

            foreach (Setting::query()->get() as $setting) {
                $values[$setting->key] = $setting->typedValue();
            }

            return $values;
        });

        return $this->cache = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value, int|string|null $updatedBy = null): void
    {
        if (! $this->isOverridable($key)) {
            return;
        }

        $descriptor = $this->descriptor($key);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => Setting::encode($this->coerce($value, (string) ($descriptor['type'] ?? 'string'))),
                'type' => (string) ($descriptor['type'] ?? 'string'),
                'updated_by' => $updatedBy === null ? null : (string) $updatedBy,
            ],
        );

        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, int|string|null $updatedBy = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $updatedBy);
        }
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();

        $this->flush();
    }

    public function flush(): void
    {
        $this->cache = null;

        Cache::forget((string) config('filament-ai.settings.cache_key', 'filament-ai.settings'));
    }

    /**
     * Read the overrides again and layer them on.
     *
     * The in-process memo is what a long-lived worker would otherwise keep
     * for its whole life: under Octane or Horizon the process outlives the
     * request, so a setting saved in the panel would not be seen until the
     * next deploy. The shared cache entry is left alone -- it is invalidated
     * on write -- so this costs one cache read, not a query.
     */
    public function refresh(): void
    {
        $this->cache = null;

        $this->apply();
    }

    /**
     * Layer the stored overrides onto the config repository.
     */
    public function apply(): void
    {
        if (! config('filament-ai.settings.enabled', true)) {
            return;
        }

        foreach ($this->all() as $key => $value) {
            if ($this->isOverridable($key)) {
                config()->set("filament-ai.{$key}", $value);
            }
        }
    }

    /**
     * The descriptors the control panel builds its form from.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        /** @var array<string, array<string, mixed>> $schema */
        $schema = (array) config('filament-ai.settings.overridable', []);

        return $schema;
    }

    /**
     * Current effective value: the override if there is one, otherwise config.
     */
    public function effective(string $key): mixed
    {
        return $this->all()[$key] ?? config("filament-ai.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public function effectiveAll(): array
    {
        $values = [];

        foreach (array_keys($this->schema()) as $key) {
            $values[$key] = $this->effective($key);
        }

        return $values;
    }

    private function isOverridable(string $key): bool
    {
        return array_key_exists($key, $this->schema());
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptor(string $key): array
    {
        /** @var array<string, mixed> */
        return $this->schema()[$key] ?? [];
    }

    private function coerce(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'json' => is_array($value) ? $value : json_decode((string) $value, true),
            default => (string) $value,
        };
    }

    /**
     * Boot must survive a database that has not been migrated yet -- otherwise
     * `migrate` itself cannot run.
     */
    private function available(): bool
    {
        try {
            return Schema::connection(Tables::connection())->hasTable(Tables::settings());
        } catch (Throwable) {
            return false;
        }
    }
}
