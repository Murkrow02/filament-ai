<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Pages;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Murkrow\FilamentAi\Contracts\Answerer;
use Murkrow\FilamentAi\Contracts\Retriever;
use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Enums\QueryChannel;
use Murkrow\FilamentAi\Filament\Concerns\HasRagNavigation;
use Murkrow\FilamentAi\Sources\SourceRegistry;
use Throwable;

/**
 * Ask the knowledge base a question and see exactly what it retrieved.
 *
 * The passage panel is the point. A RAG system that answers badly is almost
 * always retrieving badly, and the only way to tell the two apart is to look
 * at what actually went into the prompt -- with its score, its rank and the
 * page it came from.
 */
class RagPlayground extends Page
{
    use HasRagNavigation;

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $title = 'Playground';

    protected string $view = 'rag::filament.pages.playground';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public ?string $answer = null;

    public bool $refused = false;

    public ?string $error = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $passages = [];

    /**
     * @var array<string, mixed>
     */
    public array $diagnostics = [];

    public bool $running = false;

    public static function getSlug(?\Filament\Panel $panel = null): string
    {
        return static::ragSlug('playground');
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('rag.filament.navigation_sort', 90) + 2;
    }

    public function mount(): void
    {
        abort_unless(static::canAccessRag(), 403);

        $this->form->fill([
            'question' => '',
            'answer_mode' => true,
            'model' => (string) config('rag.llm.model'),
            'top_k' => (int) config('rag.retrieval.top_k', 8),
            'min_score' => (float) config('rag.retrieval.min_score', 0.25),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        Textarea::make('question')
                            ->label('Question')
                            ->rows(3)
                            ->required()
                            ->placeholder('Ask a full question. Retrieval works considerably better on sentences than on keywords.')
                            ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: 'The natural-language question sent to the retriever. Full sentences retrieve better than bare keywords.'),

                        Grid::make(5)->schema([
                            Select::make('model')
                                ->label('Model')
                                ->native(false)
                                ->options(fn (): array => (array) config('rag.llm.available_models', []))
                                ->visible(fn (): bool => (array) config('rag.llm.available_models', []) !== [])
                                ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: 'Override the generation model for this query only. Options come from config(\'rag.llm.available_models\'); the panel default is config(\'rag.llm.model\').'),

                            Select::make('sources')
                                ->label('Sources')
                                ->multiple()
                                ->options(fn (): array => app(SourceRegistry::class)->options())
                                ->placeholder('All')
                                ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: 'Restrict retrieval to these knowledge sources. Leave empty to search across all configured sources.'),

                            TextInput::make('top_k')
                                ->label('Passages')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(30)
                                ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: 'Maximum number of passages to retrieve and pass to the model. Higher values give more context but cost more tokens.'),

                            TextInput::make('min_score')
                                ->label('Minimum score')
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0)
                                ->maxValue(1)
                                ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: 'Similarity score threshold (0-1). Passages scoring below this are discarded before ranking.'),

                            Toggle::make('answer_mode')
                                ->inline(false)
                                ->label('Generate an answer')
                                ->hintIcon(Heroicon::QuestionMarkCircle, tooltip: 'When on, an LLM call generates a grounded answer from the retrieved passages. When off, only retrieval runs — no model call, no cost.'),
                        ]),
                    ]),
            ]);
    }

    public function run(): void
    {
        abort_unless(static::canAccessRag(), 403);

        $state = $this->form->getState();
        $question = trim((string) ($state['question'] ?? ''));

        $this->reset(['answer', 'passages', 'diagnostics', 'error', 'refused']);

        if ($question === '') {
            return;
        }

        $this->running = true;

        $retrieval = new RetrievalOptions(
            sourceKeys: empty($state['sources']) ? null : array_values((array) $state['sources']),
            topK: isset($state['top_k']) ? (int) $state['top_k'] : null,
            minScore: isset($state['min_score']) ? (float) $state['min_score'] : null,
        );

        $model = empty($state['model']) ? null : (string) $state['model'];

        try {
            if ($state['answer_mode'] ?? true) {
                $result = app(Answerer::class)->answer($question, new AnswerOptions(
                    retrieval: $retrieval,
                    model: $model,
                    channel: QueryChannel::Filament,
                    userId: auth()->id(),
                ));

                $this->answer = $result->answer;
                $this->refused = $result->refused;

                $used = $result->citations->map(static fn ($citation): array => [
                    'marker' => $citation->marker,
                    'label' => $citation->label,
                    'score' => round($citation->chunk->score, 4),
                    'position_start' => $citation->chunk->positionStart,
                    'position_end' => $citation->chunk->positionEnd,
                    'content' => $citation->chunk->content,
                    'url' => $citation->chunk->url,
                    'used' => $citation->used,
                ])->all();

                $this->passages = $used;

                $this->diagnostics = [
                    'model' => $result->model,
                    'latency_ms' => $result->latencyMs,
                    'retrieval' => $result->retrieval->timings,
                    'prompt_tokens' => $result->usage->promptTokens,
                    'completion_tokens' => $result->usage->completionTokens,
                    'cost_usd' => round($result->usage->costUsd(), 6),
                ];

                return;
            }

            $result = app(Retriever::class)->retrieve($question, $retrieval);

            $this->passages = $result->chunks->values()->map(static fn ($chunk, $index): array => [
                'marker' => $index + 1,
                'label' => ($chunk->documentTitle ?? $chunk->externalId).' - '.$chunk->positionStart.'-'.$chunk->positionEnd,
                'score' => round($chunk->score, 4),
                'position_start' => $chunk->positionStart,
                'position_end' => $chunk->positionEnd,
                'content' => $chunk->content,
                'url' => $chunk->url,
                'used' => false,
            ])->all();

            $this->diagnostics = [
                'retrieval' => $result->timings,
                'candidates_examined' => $result->candidatesExamined,
            ];
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();

            Notification::make()
                ->title('The query failed')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->running = false;
        }
    }
}
