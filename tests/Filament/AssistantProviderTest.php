<?php

declare(strict_types=1);

use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\FakeTextGateway;
use Murkrow\FilamentAi\Agent\PanelAssistant;

/*
 * Which model the panel agent talks to. The gateway is faked on one specific
 * provider, so a prompt that resolved to any other provider would leave the
 * fake untouched and try a real HTTP call with no key -- which is exactly the
 * bug this pins: the package's own `rag.llm.*` settings do not reach a
 * laravel/ai agent unless the agent answers `provider()` and `model()`.
 */

it('answers through the configured provider and model', function (): void {
    config()->set('filament-ai.llm.provider', 'anthropic');
    config()->set('filament-ai.llm.model', 'claude-haiku-4-5');

    Ai::textProvider('anthropic')->useTextGateway(new FakeTextGateway(['Ci sono tre attività aperte.']));

    $response = (new PanelAssistant)->prompt('Quante attività aperte ci sono?');

    expect($response->text)->toBe('Ci sono tre attività aperte.')
        ->and($response->meta->provider)->toBe('anthropic')
        ->and($response->meta->model)->toBe('claude-haiku-4-5');
});

it('lets the agent section override the retrieval model', function (): void {
    config()->set('filament-ai.llm.provider', 'openai');
    config()->set('filament-ai.llm.model', 'gpt-4o-mini');
    config()->set('filament-ai.agent.provider', 'anthropic');
    config()->set('filament-ai.agent.model', 'claude-sonnet-5');

    Ai::textProvider('anthropic')->useTextGateway(new FakeTextGateway(['Fatto.']));

    $response = (new PanelAssistant)->prompt('Ciao');

    expect($response->meta->provider)->toBe('anthropic')
        ->and($response->meta->model)->toBe('claude-sonnet-5');
});

it('leaves laravel/ai in charge when nothing is configured', function (): void {
    config()->set('filament-ai.llm.provider', null);
    config()->set('filament-ai.llm.model', null);
    config()->set('filament-ai.agent.provider', null);
    config()->set('filament-ai.agent.model', null);

    $assistant = new PanelAssistant;

    expect($assistant->provider())->toBeNull()
        ->and($assistant->model())->toBeNull();
});
