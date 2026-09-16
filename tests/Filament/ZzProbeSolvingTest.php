<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Murkrow\FilamentAi\Agent\Solving\Solver;
use Murkrow\FilamentAi\Data\SolveOptions;

it('probes a two wave run', function (): void {
    config()->set('rag.agent.solving.enabled', true);
    config()->set('rag.agent.solving.queue.connection', 'database');
    config()->set('rag.agent.solving.queue.queue', 'rag');
    config()->set('queue.default', 'database');
    $this->createQueueTables();

    Ai::textProvider()->useTextGateway(new FakeTextGateway(
        fn (string $prompt): string|array => str_contains($prompt, 'ANSWER TO GRADE')
            ? ['accepted' => false, 'score' => 42, 'reason' => 'Manca la giustificazione.']
            : 'ANSWER: Torino',
    ));

    $run = app(Solver::class)->solve('Trova la citta.', new SolveOptions(criteria: 'Una citta.', attemptsPerWave: 2, maxWaves: 2));

    $this->drainQueue();
    $run->refresh();

    fwrite(STDERR, 'PROBE status='.$run->status->value.' wave='.$run->wave.' attempts='.$run->attempts()->count()
        .' failed='.$run->attempts_failed.' msg='.var_export($run->message, true)."\n");
    fwrite(STDERR, 'PROBE per attempt: '.$run->attempts()->get()->map(
        fn ($a): string => "w{$a->wave}p{$a->position}:{$a->status->value}".($a->error ? '('.mb_substr((string) $a->error, 0, 80).')' : '')
    )->implode(', ')."\n");
    fwrite(STDERR, 'PROBE jobs='.DB::table('jobs')->count().' failed_jobs='.DB::table('failed_jobs')->count()."\n");

    $failed = DB::table('failed_jobs')->first();

    if ($failed !== null) {
        fwrite(STDERR, 'PROBE failure: '.mb_substr((string) $failed->exception, 0, 400)."\n");
    }

    expect(true)->toBeTrue();
});
