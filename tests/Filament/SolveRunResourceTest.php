<?php

declare(strict_types=1);

use Filament\Panel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Murkrow\FilamentAi\Enums\SolveAttemptStatus;
use Murkrow\FilamentAi\Enums\SolveStatus;
use Murkrow\FilamentAi\Filament\FilamentAiPlugin;
use Murkrow\FilamentAi\Filament\Resources\SolveRunResource;
use Murkrow\FilamentAi\Models\SolveAttempt;
use Murkrow\FilamentAi\Models\SolveRun;

function solveRunWithAttempts(SolveStatus $status = SolveStatus::Exhausted): SolveRun
{
    $run = SolveRun::query()->create([
        'uuid' => (string) Str::uuid7(),
        'status' => $status,
        'goal' => 'Trova la citta nascosta nell indizio.',
        'criteria' => 'Una citta italiana.',
        'wave' => 2,
        'waves_total' => 2,
        'attempts_per_wave' => 1,
        'attempts_total' => 2,
        'best_score' => 55,
        'message' => 'Non ho trovato una risposta che rispetti i criteri.',
        'started_at' => now()->subMinute(),
        'finished_at' => $status->isTerminal() ? now() : null,
    ]);

    $rejected = SolveAttempt::query()->create([
        'run_id' => $run->id,
        'wave' => 1,
        'position' => 1,
        'status' => SolveAttemptStatus::Rejected,
        'answer' => "Ci ho pensato.\nANSWER: Milano",
        'score' => 55,
        'reason' => 'Milano non compare nell indizio.',
    ]);

    SolveAttempt::query()->create([
        'run_id' => $run->id,
        'wave' => 2,
        'position' => 1,
        'status' => SolveAttemptStatus::Failed,
        'error' => 'il provider non ha risposto',
    ]);

    $run->forceFill(['best_attempt_id' => $rejected->id])->save();

    return $run->refresh();
}

it('is on the panel only while solving is switched on', function (): void {
    config()->set('filament-ai.agent.solving.enabled', false);
    // The import of Filament\Panel matters here: with the Filament facade
    // imported instead, "Filament\Panel" resolves under the facade's own
    // namespace and the class is not found.
    $panel = Panel::make()->id('off');
    FilamentAiPlugin::make()->register($panel);

    expect($panel->getResources())->not->toContain(SolveRunResource::class);

    config()->set('filament-ai.agent.solving.enabled', true);
    $panel = Panel::make()->id('on');
    FilamentAiPlugin::make()->register($panel);

    expect($panel->getResources())->toContain(SolveRunResource::class);
});

it('lists runs with what they cost and how far they got', function (): void {
    $run = solveRunWithAttempts();

    Livewire::test(SolveRunResource\Pages\ListSolveRuns::class)
        ->assertOk()
        ->assertSee(substr($run->uuid, 0, 8))
        ->assertSee('No solution found')
        ->assertSee('2 / 2')
        ->assertSee('55/100');
});

it('shows every attempt, including the ones that were rejected', function (): void {
    $run = solveRunWithAttempts();

    Livewire::test(SolveRunResource\Pages\ViewSolveRun::class, ['record' => $run->uuid])
        ->assertOk()
        ->assertSee('Milano')
        ->assertSee('Milano non compare nell indizio.')
        ->assertSee('il provider non ha risposto')
        ->assertSee('Non ho trovato una risposta che rispetti i criteri.');
});

it('does not poll a run that has finished', function (): void {
    $run = solveRunWithAttempts();

    $page = Livewire::test(SolveRunResource\Pages\ViewSolveRun::class, ['record' => $run->uuid]);

    expect($page->instance()->getPollingInterval())->toBeNull();

    $run->forceFill(['status' => SolveStatus::Running, 'finished_at' => null])->save();

    $running = Livewire::test(SolveRunResource\Pages\ViewSolveRun::class, ['record' => $run->uuid]);

    expect($running->instance()->getPollingInterval())->not->toBeNull();
});
