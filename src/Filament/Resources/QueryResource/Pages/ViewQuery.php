<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Resources\QueryResource\Pages;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Murkrow\FilamentAi\Filament\Resources\QueryResource;
use Murkrow\FilamentAi\Ingestion\CostCalculator;
use Murkrow\FilamentAi\Models\QueryLog;
use Murkrow\FilamentAi\Models\QueryCitation;

class ViewQuery extends ViewRecord
{
    protected static string $resource = QueryResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Question')
                ->schema([
                    TextEntry::make('question')->label('')->size('lg'),
                ]),

            Section::make('Answer')
                ->schema([
                    TextEntry::make('answer')
                        ->label('')
                        ->placeholder('-')
                        ->color(static fn (QueryLog $record): ?string => $record->refused ? 'warning' : null),
                ]),

            Section::make('Retrieved passages')
                ->description('Highlighted rows were actually referenced in the answer. Passages that are never used are paying for context window without contributing.')
                ->schema([
                    RepeatableEntry::make('citations')
                        ->label('')
                        ->schema([
                            TextEntry::make('marker')
                                ->label('#')
                                ->formatStateUsing(static fn (int $state): string => "[#{$state}]")
                                ->badge()
                                ->color(static fn (QueryCitation $record): string => $record->used ? 'success' : 'gray'),
                            TextEntry::make('score')->label('Score')->numeric(3),
                            TextEntry::make('position')
                                ->label('Position')
                                ->state(static fn (QueryCitation $record): string => $record->position_start === $record->position_end
                                    ? (string) $record->position_start
                                    : $record->position_start.'-'.$record->position_end),
                            TextEntry::make('snippet')->label('Passage')->columnSpanFull(),
                        ])
                        ->columns(3),
                ]),

            Section::make('Diagnostics')
                ->columns(4)
                ->collapsed()
                ->schema([
                    TextEntry::make('llm_model')->label('Generation model')->placeholder('-'),
                    TextEntry::make('embedding_model')->label('Embedding model'),
                    TextEntry::make('latency_ms')->label('Latency')->state(static fn (QueryLog $r): string => ($r->latency_ms ?? 0).' ms'),
                    TextEntry::make('retrieval_ms')->label('Retrieval')->state(static fn (QueryLog $r): string => ($r->retrieval_ms ?? 0).' ms'),
                    TextEntry::make('tokens')
                        ->label('Tokens')
                        ->state(static fn (QueryLog $r): string => "{$r->prompt_tokens} in / {$r->completion_tokens} out / {$r->embedding_tokens} embedding"),
                    TextEntry::make('cost_micros')
                        ->label('Cost')
                        ->state(static fn (QueryLog $r): string => CostCalculator::format($r->cost_micros, 5)),
                    TextEntry::make('filters')
                        ->label('Filters')
                        ->state(static fn (QueryLog $r): string => (string) json_encode($r->filters ?: []))
                        ->fontFamily('mono')
                        ->columnSpan(2),
                ]),
        ]);
    }
}
