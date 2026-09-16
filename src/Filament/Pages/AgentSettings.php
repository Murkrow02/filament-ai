<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Murkrow\FilamentAi\Agent\Resources\AgentTools;
use Murkrow\FilamentAi\Agent\Resources\ResourceToolRegistry;
use Murkrow\FilamentAi\Contracts\CodeSandbox;
use Murkrow\FilamentAi\Contracts\ListsRuntimes;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Settings\SettingsRepository;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use UnitEnum;

/**
 * What the assistant may do, editable from the panel.
 *
 * Gated by `rag.filament.authorize`, not by `rag.agent.authorize`: using the
 * assistant and deciding what it is allowed to do are different permissions.
 *
 * Only differences from the code are stored. A resource left exactly as its
 * `agentTools()` declared it writes no override at all, so a later change in
 * the code is picked up instead of being shadowed by a stale row.
 */
class AgentSettings extends Page
{
    use HasRagNavigation;

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-shield-check';

    protected string $view = 'rag::filament.pages.agent-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getSlug(?Panel $panel = null): string
    {
        return trim((string) config('rag.agent.settings.slug', 'assistant-settings'), '/');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('rag::rag.assistant_settings.navigation');
    }

    public function getTitle(): string
    {
        return (string) __('rag::rag.assistant_settings.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = config('rag.agent.chat.navigation_group');

        return $group === null || $group === '' ? null : (string) $group;
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('rag.agent.chat.navigation_sort', -1) + 1;
    }

    public function mount(SettingsRepository $settings): void
    {
        abort_unless(static::canAccessRag(), 403);

        $this->form->fill($this->currentState($settings));
    }

    public function form(Schema $schema): Schema
    {
        $sections = [
            Section::make(__('rag::rag.assistant_settings.agent'))
                ->description(__('rag::rag.assistant_settings.agent_help'))
                ->columns(2)
                ->schema([
                    Toggle::make('agent__enabled')->label(__('rag::rag.assistant_settings.enabled')),
                    Toggle::make('agent__chat__enabled')->label(__('rag::rag.assistant_settings.chat_enabled')),
                    TextInput::make('agent__provider')
                        ->label(__('rag::rag.assistant_settings.provider'))
                        ->placeholder((string) config('rag.llm.provider'))
                        ->helperText(__('rag::rag.assistant_settings.provider_help')),
                    TextInput::make('agent__model')
                        ->label(__('rag::rag.assistant_settings.model'))
                        ->placeholder((string) config('rag.llm.model')),
                    TextInput::make('agent__chat__history')
                        ->label(__('rag::rag.assistant_settings.history'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100),
                    Toggle::make('agent__chat__topbar_button')->label(__('rag::rag.assistant_settings.topbar_button')),
                    TextInput::make('agent__max_steps')
                        ->label(__('rag::rag.assistant_settings.max_steps'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(40)
                        ->helperText(__('rag::rag.assistant_settings.max_steps_help')),
                ]),

            Section::make(__('rag::rag.assistant_settings.knowledge'))
                ->columns(2)
                ->schema([
                    Toggle::make('agent__knowledge__enabled')->label(__('rag::rag.assistant_settings.knowledge_enabled')),
                    Select::make('agent__knowledge__sources')
                        ->label(__('rag::rag.assistant_settings.sources'))
                        ->multiple()
                        ->options(fn (): array => app(SourceRegistry::class)->options())
                        ->helperText(__('rag::rag.assistant_settings.sources_help')),
                ]),

            Section::make(__('rag::rag.assistant_settings.records'))
                ->columns(2)
                ->schema([
                    Toggle::make('agent__resources__enabled')->label(__('rag::rag.assistant_settings.resources_enabled')),
                    TextInput::make('agent__resources__max_records')
                        ->label(__('rag::rag.assistant_settings.max_records'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(200),
                ]),
        ];

        $sections[] = $this->sandboxSection();

        foreach ($this->catalogue() as $index => $resource) {
            $sections[] = $this->resourceSection($index, $resource);
        }

        return $schema->statePath('data')->components($sections);
    }

    public function save(SettingsRepository $settings): void
    {
        abort_unless(static::canAccessRag(), 403);

        $state = $this->form->getState();

        $settings->setMany([
            'agent.enabled' => (bool) ($state['agent__enabled'] ?? true),
            'agent.provider' => $this->blankToNull($state['agent__provider'] ?? null),
            'agent.model' => $this->blankToNull($state['agent__model'] ?? null),
            'agent.knowledge.enabled' => (bool) ($state['agent__knowledge__enabled'] ?? true),
            // Empty means every source, which is what a null says in config;
            // switching knowledge off is how you reach "none".
            'agent.knowledge.sources' => empty($state['agent__knowledge__sources'])
                ? null
                : array_values((array) $state['agent__knowledge__sources']),
            'agent.resources.enabled' => (bool) ($state['agent__resources__enabled'] ?? true),
            'agent.resources.max_records' => (int) ($state['agent__resources__max_records'] ?? 25),
            'agent.resources.overrides' => $this->overridesFrom($state),
            'agent.sandbox.enabled' => (bool) ($state['agent__sandbox__enabled'] ?? false),
            'agent.sandbox.languages' => $this->languagesFrom($state),
            'agent.sandbox.timeout' => (int) ($state['agent__sandbox__timeout'] ?? 5000),
            'agent.sandbox.max_output' => (int) ($state['agent__sandbox__max_output'] ?? 4000),
            'agent.sandbox.max_code_characters' => (int) ($state['agent__sandbox__max_code'] ?? 20000),
            'agent.max_steps' => blank($state['agent__max_steps'] ?? null) ? null : (int) $state['agent__max_steps'],
        ], auth()->id());

        Notification::make()
            ->title(__('rag::rag.assistant_settings.saved'))
            ->body(__('rag::rag.assistant_settings.saved_body'))
            ->success()
            ->send();
    }

    public function resetToDefaults(SettingsRepository $settings): void
    {
        abort_unless(static::canAccessRag(), 403);

        foreach (array_keys($settings->schema()) as $key) {
            if (str_starts_with($key, 'agent.')) {
                $settings->forget($key);
            }
        }

        $this->mount($settings);

        Notification::make()->title(__('rag::rag.assistant_settings.reset'))->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(__('rag::rag.assistant_settings.save'))
                ->icon('heroicon-o-check')
                ->action('save'),
            Action::make('reset')
                ->label(__('rag::rag.assistant_settings.reset_action'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->action('resetToDefaults'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['hasResources' => $this->catalogue() !== []];
    }

    private function sandboxSection(): Section
    {
        $runtimes = $this->runtimes();

        $description = $runtimes === []
            ? (string) __('rag::rag.assistant_settings.sandbox_unreachable')
            : (string) __('rag::rag.assistant_settings.sandbox_installed', [
                'runtimes' => implode(', ', array_map(
                    static fn (string $language, string $version): string => "{$language} {$version}",
                    array_keys($runtimes),
                    array_values($runtimes),
                )),
            ]);

        return Section::make(__('rag::rag.assistant_settings.sandbox_section'))
            ->description($description.' '.__('rag::rag.assistant_settings.sandbox_where'))
            ->columns(2)
            ->schema([
                Toggle::make('agent__sandbox__enabled')
                    ->label(__('rag::rag.assistant_settings.sandbox'))
                    ->helperText(__('rag::rag.assistant_settings.sandbox_help')),
                Select::make('agent__sandbox__languages')
                    ->label(__('rag::rag.assistant_settings.sandbox_languages'))
                    ->multiple()
                    ->options($this->languageOptions())
                    ->disabled($this->languageOptions() === [])
                    ->helperText(__('rag::rag.assistant_settings.sandbox_languages_help')),
                TextInput::make('agent__sandbox__timeout')
                    ->label(__('rag::rag.assistant_settings.sandbox_timeout'))
                    ->numeric()
                    ->minValue(500)
                    ->maxValue(60000)
                    ->helperText(__('rag::rag.assistant_settings.sandbox_timeout_help')),
                TextInput::make('agent__sandbox__max_output')
                    ->label(__('rag::rag.assistant_settings.sandbox_max_output'))
                    ->numeric()
                    ->minValue(200)
                    ->maxValue(50000),
                TextInput::make('agent__sandbox__max_code')
                    ->label(__('rag::rag.assistant_settings.sandbox_max_code'))
                    ->numeric()
                    ->minValue(200)
                    ->maxValue(200000),
            ]);
    }

    /**
     * What may be picked: what the sandbox has installed, plus whatever the
     * configuration already names.
     *
     * A configured language the sandbox does not have is kept and marked, not
     * dropped: dropping it would make the saved value invalid the moment the
     * sandbox is down, and the form would refuse to save anything at all.
     *
     * @return array<string, string>
     */
    private function languageOptions(): array
    {
        $runtimes = $this->runtimes();
        $options = [];

        foreach ($runtimes as $language => $version) {
            $options[$language] = "{$language} {$version}";
        }

        foreach (array_keys($this->configuredLanguages()) as $language) {
            $options[$language] ??= (string) __('rag::rag.assistant_settings.sandbox_not_installed', ['language' => $language]);
        }

        ksort($options);

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function configuredLanguages(): array
    {
        $languages = config('rag.agent.sandbox.languages', []);

        return is_array($languages) ? $languages : [];
    }

    /**
     * What the sandbox has installed, asked once per minute: an unreachable
     * one would otherwise hold the page for the whole HTTP timeout on every
     * render.
     *
     * @return array<string, string>
     */
    private function runtimes(): array
    {
        return cache()->remember('rag.agent.sandbox.runtimes', 60, static function (): array {
            $sandbox = app(CodeSandbox::class);

            return $sandbox instanceof ListsRuntimes ? $sandbox->runtimes() : [];
        });
    }

    /**
     * Languages the agent may use, pinned to the versions the sandbox reports.
     * Choosing none means every installed one.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, string>|null
     */
    private function languagesFrom(array $state): ?array
    {
        $runtimes = $this->runtimes();
        $configured = $this->configuredLanguages();
        $offered = $runtimes + $configured;

        $selected = array_values(array_intersect(array_keys($offered), (array) ($state['agent__sandbox__languages'] ?? [])));

        if ($selected === []) {
            // Nothing chosen: leave the configuration alone rather than
            // storing an empty list, which would mean "no language at all".
            return $offered === [] ? null : $offered;
        }

        $languages = [];

        foreach ($selected as $language) {
            // The installed version wins over the configured selector: it is
            // the one that will actually run.
            $languages[$language] = $runtimes[$language] ?? $configured[$language] ?? '*';
        }

        return $languages;
    }

    /**
     * @param  array{resource: string, label: string, plural: string, abilities: list<string>, unapproved: list<string>, max_records: int}  $resource
     */
    private function resourceSection(int $index, array $resource): Section
    {
        $declared = $resource['abilities'];
        $approvable = array_values(array_intersect($declared, [AgentTools::CREATE, AgentTools::EDIT]));

        return Section::make(ucfirst($resource['plural']))
            ->description($resource['resource'])
            ->columns(3)
            ->schema(array_values(array_filter([
                CheckboxList::make("resources.{$index}.abilities")
                    ->label(__('rag::rag.assistant_settings.abilities'))
                    ->options($this->abilityOptions($declared))
                    ->helperText(__('rag::rag.assistant_settings.abilities_help')),

                $approvable === [] ? null : CheckboxList::make("resources.{$index}.unapproved")
                    ->label(__('rag::rag.assistant_settings.unapproved'))
                    ->options($this->abilityOptions($approvable))
                    ->helperText(__('rag::rag.assistant_settings.unapproved_help')),

                Grid::make(1)->schema([
                    TextInput::make("resources.{$index}.max_records")
                        ->label(__('rag::rag.assistant_settings.max_records'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(200),
                ]),
            ])));
    }

    /**
     * @param  list<string>  $abilities
     * @return array<string, string>
     */
    private function abilityOptions(array $abilities): array
    {
        $options = [];

        foreach ($abilities as $ability) {
            $options[$ability] = (string) __("rag::rag.assistant_settings.ability_{$ability}");
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function currentState(SettingsRepository $settings): array
    {
        $overrides = (array) $settings->effective('agent.resources.overrides');
        $resources = [];

        foreach ($this->catalogue() as $index => $resource) {
            $override = (array) ($overrides[$resource['resource']] ?? []);

            $resources[$index] = [
                'resource' => $resource['resource'],
                'abilities' => array_values(array_intersect(
                    $resource['abilities'],
                    isset($override['abilities']) && is_array($override['abilities']) ? $override['abilities'] : $resource['abilities'],
                )),
                'unapproved' => array_values(array_intersect(
                    $resource['abilities'],
                    isset($override['unapproved']) && is_array($override['unapproved']) ? $override['unapproved'] : $resource['unapproved'],
                )),
                'max_records' => (int) ($override['max_records'] ?? $resource['max_records']),
            ];
        }

        return [
            'agent__enabled' => (bool) $settings->effective('agent.enabled'),
            'agent__provider' => $settings->effective('agent.provider'),
            'agent__model' => $settings->effective('agent.model'),
            'agent__knowledge__enabled' => (bool) $settings->effective('agent.knowledge.enabled'),
            'agent__knowledge__sources' => (array) ($settings->effective('agent.knowledge.sources') ?? []),
            'agent__resources__enabled' => (bool) $settings->effective('agent.resources.enabled'),
            'agent__resources__max_records' => (int) $settings->effective('agent.resources.max_records'),
            'agent__sandbox__enabled' => (bool) $settings->effective('agent.sandbox.enabled'),
            'agent__sandbox__languages' => array_keys((array) $settings->effective('agent.sandbox.languages')),
            'agent__sandbox__timeout' => (int) $settings->effective('agent.sandbox.timeout'),
            'agent__sandbox__max_output' => (int) $settings->effective('agent.sandbox.max_output'),
            'agent__sandbox__max_code' => (int) $settings->effective('agent.sandbox.max_code_characters'),
            'agent__max_steps' => $settings->effective('agent.max_steps'),
            'resources' => $resources,
        ];
    }

    /**
     * Only what differs from the code, so a resource left alone keeps
     * following its own `agentTools()`.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, array<string, mixed>>
     */
    private function overridesFrom(array $state): array
    {
        $overrides = [];

        foreach ($this->catalogue() as $index => $resource) {
            $row = (array) ($state['resources'][$index] ?? []);

            $abilities = array_values(array_intersect($resource['abilities'], (array) ($row['abilities'] ?? $resource['abilities'])));
            $unapproved = array_values(array_intersect($abilities, (array) ($row['unapproved'] ?? [])));
            $maxRecords = (int) ($row['max_records'] ?? $resource['max_records']);

            $override = [];

            if ($abilities !== $resource['abilities']) {
                $override['abilities'] = $abilities;
            }

            if ($unapproved !== $resource['unapproved']) {
                $override['unapproved'] = $unapproved;
            }

            if ($maxRecords !== $resource['max_records']) {
                $override['max_records'] = $maxRecords;
            }

            if ($override !== []) {
                $overrides[$resource['resource']] = $override;
            }
        }

        return $overrides;
    }

    /**
     * @return list<array{resource: string, label: string, plural: string, abilities: list<string>, unapproved: list<string>, max_records: int}>
     */
    private function catalogue(): array
    {
        return app(ResourceToolRegistry::class)->catalogue();
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
