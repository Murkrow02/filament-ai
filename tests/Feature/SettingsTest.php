<?php

declare(strict_types=1);

use Murkrow\FilamentAi\Settings\SettingsRepository;

beforeEach(function (): void {
    config()->set('filament-ai.settings.enabled', true);
});

it('stores an override for a whitelisted key', function (): void {
    $settings = app(SettingsRepository::class);

    $settings->set('retrieval.top_k', 12);

    expect($settings->get('retrieval.top_k'))->toBe(12);
});

it('refuses to store a key that is not whitelisted', function (): void {
    $settings = app(SettingsRepository::class);

    $settings->set('embeddings.model', 'something-else');

    expect($settings->get('embeddings.model'))->toBeNull();
});

it('coerces a stored value back into its declared type', function (): void {
    $settings = app(SettingsRepository::class);

    $settings->set('retrieval.min_score', '0.42');
    $settings->set('retrieval.mmr.enabled', '0');

    expect($settings->get('retrieval.min_score'))->toBe(0.42)
        ->and($settings->get('retrieval.mmr.enabled'))->toBeFalse();
});

it('layers overrides onto the config repository', function (): void {
    $settings = app(SettingsRepository::class);

    $settings->set('retrieval.top_k', 3);
    $settings->apply();

    expect(config('filament-ai.retrieval.top_k'))->toBe(3);
});

it('falls back to config when there is no override', function (): void {
    config()->set('filament-ai.retrieval.top_k', 9);

    expect(app(SettingsRepository::class)->effective('retrieval.top_k'))->toBe(9);
});

it('reverts to the config value when an override is forgotten', function (): void {
    config()->set('filament-ai.retrieval.top_k', 9);

    $settings = app(SettingsRepository::class);
    $settings->set('retrieval.top_k', 3);
    $settings->forget('retrieval.top_k');

    expect($settings->effective('retrieval.top_k'))->toBe(9);
});
