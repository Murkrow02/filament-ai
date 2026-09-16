<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;
use Murkrow\FilamentAi\Enums\IngestionMode;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Filament\Forms\SourceFilterSchema;
use Murkrow\FilamentAi\Filament\Resources\IngestionRunResource;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Ingestion\IngestionPlanner;
use Murkrow\FilamentAi\Ingestion\StartIngestionRun;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Throwable;

/**
 * Launch an ingestion run.
 *
 * The estimate is the reason this page exists rather than a bare button:
 * embedding a corpus costs real money and takes real time, and both numbers
 * are trivially computable beforehand. Nobody should have to find out after
 * the fact.
 */
class IngestKnowledge extends Page
{
    use HasRagNavigation;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $title = 'Ingest knowledge';

    protected string $view = 'rag::filament.pages.ingest';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    #[Url]
    public ?string $source = null;

    /**
     * Held as a plain array, not as the DTO the planner returns: Livewire can
     * only round-trip scalars and arrays through its component state.
     *
     * @var array<string, mixed>|null
     */
    public ?array $estimate = null;

    public bool $estimating = false;

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::ragSlug('ingest');
    }

    public function mount(): void
    {
        abort_unless(static::canAccessRag(), 403);

        $sources = app(SourceRegistry::class)->keys();

        $this->form->fill([
            'source' => $this->source ?? ($sources[0] ?? null),
            'mode' => IngestionMode::Incremental->value,
            'sync' => false,
            'target_tokens' => (int) config('rag.chunking.target_tokens', 512),
            'overlap_tokens' => (int) config('rag.chunking.overlap_tokens', 80),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('What to index')
                    ->columns(2)
                    ->schema([
                        Select::make('source')
                            ->label('Knowledge source')
                            ->options(fn (): array => app(SourceRegistry::class)->options())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (): void {
                                // Filters belong to the previous source; keeping
                                // them would silently apply the wrong columns.
                                $this->data['filters'] = [];
                                $this->estimate = null;
                            })
                            ->helperText('Sources are classes in app/Knowledge, listed under rag.sources.'),

                        Select::make('mode')
                            ->label('Mode')
                            ->options(IngestionMode::options())
                            ->default(IngestionMode::Incremental->value)
                            ->required()
                            ->live()
                            ->helperText(fn (?string $state): string => match ($state) {
                                IngestionMode::Full->value => 'Re-chunks everything. Unchanged text still keeps its vectors.',
                                IngestionMode::EmbeddingsOnly->value => 'Skips chunking; only embeds chunks that have no vector yet.',
                                default => 'Skips documents whose text and parameters are unchanged.',
                            }),
                    ]),

                Section::make('Filters')
                    ->description('Narrow the run to a subset. Leave empty to process everything the source exposes.')
                    ->visible(fn (): bool => $this->currentSource() !== null && $this->filterFields() !== [])
                    ->schema(fn (): array => $this->filterFields()),

                Section::make('Chunking')
                    ->description('Overrides for this run only. Changing them marks affected documents stale, so they will be re-chunked and re-embedded.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('target_tokens')
                            ->label('Target chunk size (tokens)')
                            ->numeric()
                            ->minValue(64)
                            ->maxValue(4000),

                        TextInput::make('overlap_tokens')
                            ->label('Overlap (tokens)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->helperText('Carried from the end of one chunk into the next so a fact on a boundary survives intact.'),
                    ]),

                Section::make('Execution')
                    ->schema([
                        Toggle::make('sync')
                            ->label('Run in this request instead of on the queue')
                            ->helperText('Only for small subsets: a large run will hit the request timeout.'),

                        Text::make(fn (): string => $this->estimateSummary()),
                    ]),
            ]);
    }

    public function estimateAction(): void
    {
        $source = $this->currentSource();

        if ($source === null) {
            return;
        }

        $this->estimating = true;

        try {
            $estimate = app(IngestionPlanner::class)->estimate(
                app(SourceRegistry::class)->get($source),
                $this->filters(),
            );

            $this->estimate = [
                'documents' => $estimate->documents,
                'segments' => $estimate->segments,
                'chunks' => $estimate->chunks,
                'tokens' => $estimate->tokens,
                'cost_micros' => $estimate->costMicros,
            ];
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not estimate this run')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->estimating = false;
        }
    }

    public function start(): void
    {
        abort_unless(static::canAccessRag(), 403);

        $state = $this->form->getState();
        $sourceKey = (string) ($state['source'] ?? '');

        if ($sourceKey === '') {
            return;
        }

        $registry = app(SourceRegistry::class);

        if (! $registry->has($sourceKey)) {
            Notification::make()->title("Unknown source [{$sourceKey}]")->danger()->send();

            return;
        }

        $overrides = array_filter([
            'target_tokens' => isset($state['target_tokens']) ? (int) $state['target_tokens'] : null,
            'overlap_tokens' => isset($state['overlap_tokens']) ? (int) $state['overlap_tokens'] : null,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $run = app(StartIngestionRun::class)(
                $registry->get($sourceKey),
                $this->filters(),
                IngestionMode::from((string) ($state['mode'] ?? 'incremental')),
                $overrides,
                auth()->id(),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not start the run')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Ingestion queued')
            ->body("{$run->documents_total} documents. Make sure a worker is consuming the '".config('rag.queue.queue')."' queue.")
            ->success()
            ->send();

        $this->redirect(IngestionRunResource::getUrl('view', ['record' => $run->uuid]));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('estimate')
                ->label('Estimate')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->action('estimateAction'),

            Action::make('start')
                ->label('Start ingestion')
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->modalHeading('Start ingestion')
                ->modalDescription(fn (): string => $this->estimateSummary())
                ->action('start'),
        ];
    }

    private function currentSource(): ?string
    {
        $source = $this->data['source'] ?? null;

        return $source === null || $source === '' ? null : (string) $source;
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        /** @var array<string, mixed> $filters */
        $filters = (array) ($this->data['filters'] ?? []);

        return array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array<int, mixed>
     */
    private function filterFields(): array
    {
        $key = $this->currentSource();

        if ($key === null) {
            return [];
        }

        $registry = app(SourceRegistry::class);

        if (! $registry->has($key)) {
            return [];
        }

        try {
            return SourceFilterSchema::for($registry->get($key));
        } catch (Throwable) {
            // A misconfigured source must not take the whole page down.
            return [];
        }
    }

    private function estimateSummary(): string
    {
        if ($this->estimate === null) {
            return 'Press Estimate to see how many documents, chunks and tokens this run would process, and what it would cost.';
        }

        return sprintf(
            '~%s documents, ~%s chunks, ~%s tokens, about %s.',
            number_format((int) $this->estimate['documents']),
            number_format((int) $this->estimate['chunks']),
            number_format((int) $this->estimate['tokens']),
            CostCalculator::format((int) $this->estimate['cost_micros'], 4),
        );
    }
}
