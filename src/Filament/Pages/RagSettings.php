<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Settings\SettingsRepository;

/**
 * Runtime tuning for the whitelisted config keys.
 *
 * The whitelist is deliberately conservative. Retrieval and prompt settings are
 * safe to change between queries; the embedding model and vector width are not,
 * because changing either invalidates every stored vector -- so they are absent
 * from the form and stay a deployment concern.
 */
class RagSettings extends Page
{
    use HasRagNavigation;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $title = 'Knowledge settings';

    protected string $view = 'rag::filament.pages.settings';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::ragSlug('settings');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('rag.filament.navigation_sort', 90) + 3;
    }

    public function mount(SettingsRepository $settings): void
    {
        abort_unless(static::canAccessRag(), 403);

        // Dots are Livewire state-path separators, so keys are flattened.
        $values = [];

        foreach ($settings->effectiveAll() as $key => $value) {
            // Agent keys belong to AgentSettings. Filling them here would put
            // them in this component's state, and save() would write them
            // straight back -- including the per-resource policies.
            if (! $this->owns($key)) {
                continue;
            }

            $values[$this->flatten($key)] = $value;
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(SettingsRepository::class);

        $groups = [];

        foreach ($settings->schema() as $key => $descriptor) {
            $group = explode('.', $key)[0];

            // The assistant has its own page: its policies are per resource
            // and do not fit a flat form of scalars.
            if ($group === 'agent') {
                continue;
            }

            $groups[$group][$key] = $descriptor;
        }

        $sections = [];

        foreach ($groups as $group => $keys) {
            $sections[] = Section::make(ucfirst($group))
                ->columns(2)
                ->schema(array_values(array_map(
                    fn (string $key): mixed => $this->field($key, $keys[$key]),
                    array_keys($keys),
                )));
        }

        return $schema->statePath('data')->components($sections);
    }

    public function save(SettingsRepository $settings): void
    {
        abort_unless(static::canAccessRag(), 403);

        $state = $this->form->getState();
        $values = [];

        foreach (array_keys($settings->schema()) as $key) {
            $flat = $this->flatten($key);

            if ($this->owns($key) && array_key_exists($flat, $state)) {
                $values[$key] = $state[$flat];
            }
        }

        $settings->setMany($values, auth()->id());

        Notification::make()
            ->title('Settings saved')
            ->body('They take effect on the next request.')
            ->success()
            ->send();
    }

    public function resetToDefaults(SettingsRepository $settings): void
    {
        abort_unless(static::canAccessRag(), 403);

        foreach (array_keys($settings->schema()) as $key) {
            if ($this->owns($key)) {
                $settings->forget($key);
            }
        }

        $this->mount($settings);

        Notification::make()->title('Reverted to the values in config/rag.php')->success()->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->icon('heroicon-o-check')
                ->action('save'),

            Action::make('reset')
                ->label('Reset to config defaults')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->action('resetToDefaults'),
        ];
    }

    /**
     * @param  array<string, mixed>  $descriptor
     */
    private function field(string $key, array $descriptor): mixed
    {
        $label = ucfirst(str_replace(['.', '_'], [' ', ' '], $key));
        $path = $this->flatten($key);

        return match ((string) ($descriptor['type'] ?? 'string')) {
            'bool' => Toggle::make($path)->label($label),

            'int' => TextInput::make($path)
                ->label($label)
                ->numeric()
                ->minValue($descriptor['min'] ?? null)
                ->maxValue($descriptor['max'] ?? null),

            'float' => TextInput::make($path)
                ->label($label)
                ->numeric()
                ->step(0.01)
                ->minValue($descriptor['min'] ?? null)
                ->maxValue($descriptor['max'] ?? null),

            'enum' => Select::make($path)
                ->label($label)
                ->options(self::enumOptions((array) ($descriptor['options'] ?? []))),

            'text' => Textarea::make($path)->label($label)->rows(3)->columnSpanFull(),

            default => TextInput::make($path)->label($label),
        };
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<string, string>
     */
    private static function enumOptions(array $options): array
    {
        $result = [];

        foreach ($options as $option) {
            if ($option === null) {
                $result[''] = 'None';

                continue;
            }

            $result[(string) $option] = (string) $option;
        }

        return $result;
    }

    /**
     * Whether this page, rather than AgentSettings, owns a settings key.
     */
    private function owns(string $key): bool
    {
        return ! str_starts_with($key, 'agent.');
    }

    private function flatten(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}
