<?php

declare(strict_types=1);

use Laravel\Ai\Tools\Request;
use Murkrow\FilamentAi\Agent\Sandbox\FakeSandbox;
use Murkrow\FilamentAi\Agent\Sandbox\SandboxResult;
use Murkrow\FilamentAi\Agent\Tools\RunCode;
use Murkrow\FilamentAi\Contracts\CodeSandbox;

/*
 * The tool around the sandbox. What the sandbox itself allows is the
 * sandbox's business; these are the refusals that must happen before anything
 * is run at all, and the shape of what comes back.
 */

function fakeSandbox(array $languages = ['python']): FakeSandbox
{
    config()->set('filament-ai.agent.sandbox.enabled', true);
    config()->set('filament-ai.agent.sandbox.driver', 'fake');
    config()->set('filament-ai.agent.sandbox.languages', array_fill_keys($languages, '*'));

    $sandbox = new FakeSandbox($languages);

    app()->instance(CodeSandbox::class, $sandbox);

    return $sandbox;
}

it('is off unless the host switches it on', function (): void {
    config()->set('filament-ai.agent.sandbox.enabled', false);

    expect(RunCode::enabled())->toBeFalse()
        ->and((new RunCode)->handle(new Request(['language' => 'python', 'code' => 'print(1)'])))
        ->toBe('Error: running code is switched off for this application.');
});

it('runs a program and returns what it printed', function (): void {
    $sandbox = fakeSandbox();
    $sandbox->respondWith(new SandboxResult(stdout: "roma\namor\nmora", exitCode: 0));

    $output = (new RunCode)->handle(new Request([
        'language' => 'python',
        'code' => "from itertools import permutations\nprint('roma')",
    ]));

    expect($output)->toContain('roma')->toContain('stdout:')
        ->and($sandbox->received()[0]['language'])->toBe('python');
});

it('passes stdin through to the program', function (): void {
    $sandbox = fakeSandbox();

    (new RunCode)->handle(new Request(['language' => 'python', 'code' => 'print(input())', 'stdin' => 'ciao']));

    expect($sandbox->received()[0]['stdin'])->toBe('ciao');
});

it('reports a failing program with its stderr, because that is how it gets fixed', function (): void {
    $sandbox = fakeSandbox();
    $sandbox->respondWith(new SandboxResult(stderr: "Traceback...\nNameError: name 'x' is not defined", exitCode: 1));

    $output = (new RunCode)->handle(new Request(['language' => 'python', 'code' => 'print(x)']));

    expect($output)->toContain('NameError')->toContain('exit code: 1');
});

it('says when the program ran out of time', function (): void {
    $sandbox = fakeSandbox();
    $sandbox->respondWith(new SandboxResult(timedOut: true));

    expect((new RunCode)->handle(new Request(['language' => 'python', 'code' => 'while True: pass'])))
        ->toContain('[timed out]');
});

it('refuses a language nobody allowed', function (): void {
    fakeSandbox(['python']);

    expect((new RunCode)->handle(new Request(['language' => 'bash', 'code' => 'rm -rf /'])))
        ->toStartWith('Error:');
});

it('refuses an empty or oversized program before running anything', function (): void {
    $sandbox = fakeSandbox();
    config()->set('filament-ai.agent.sandbox.max_code_characters', 50);

    expect((new RunCode)->handle(new Request(['language' => 'python', 'code' => '   '])))->toStartWith('Error:')
        ->and((new RunCode)->handle(new Request(['language' => 'python', 'code' => str_repeat('a', 100)])))->toStartWith('Error:')
        ->and($sandbox->received())->toBe([]);
});

it('truncates a flood of output from the end', function (): void {
    $sandbox = fakeSandbox();
    $sandbox->respondWith(new SandboxResult(stdout: str_repeat('x', 5000).'ULTIMA RIGA', exitCode: 0));
    config()->set('filament-ai.agent.sandbox.max_output', 200);

    $output = (new RunCode)->handle(new Request(['language' => 'python', 'code' => 'print("x" * 5000)']));

    expect($output)->toContain('truncated')->toContain('ULTIMA RIGA')
        ->and(mb_strlen($output))->toBeLessThan(400);
});

it('reports a sandbox that cannot be reached as an error, not as an empty answer', function (): void {
    $sandbox = fakeSandbox();
    $sandbox->respondWith(SandboxResult::failure('the sandbox is unreachable.'));

    expect((new RunCode)->handle(new Request(['language' => 'python', 'code' => 'print(1)'])))
        ->toBe('Error: the sandbox is unreachable.');
});

it('tells the model plainly when a program printed nothing', function (): void {
    $sandbox = fakeSandbox();
    $sandbox->respondWith(new SandboxResult(exitCode: 0));

    expect((new RunCode)->handle(new Request(['language' => 'python', 'code' => 'x = 1'])))
        ->toContain('printed nothing');
});
