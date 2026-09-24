<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Murkrow\FilamentAi\Chat\ChatAbilities;

/*
 * Who may see what in the chat. The vocabulary is config, so it is testable
 * without a browser, a panel or a request -- which is the point of keeping it
 * in one class instead of in the templates.
 */

it('answers from the defaults when nothing is configured', function (): void {
    expect(ChatAbilities::allows('view'))->toBeTrue()
        ->and(ChatAbilities::allows('solve'))->toBeTrue()
        // What only an administrator should see has to be granted.
        ->and(ChatAbilities::allows('cost'))->toBeFalse()
        ->and(ChatAbilities::allows('debug'))->toBeFalse();
});

it('configures exactly the abilities it knows', function (): void {
    expect(array_keys((array) config('filament-ai.chat.abilities')))->toBe(ChatAbilities::names());
});

it('takes a plain boolean from config', function (): void {
    config()->set('filament-ai.chat.abilities.solve', false);
    config()->set('filament-ai.chat.abilities.settings', false);

    expect(ChatAbilities::allows('solve'))->toBeFalse()
        ->and(ChatAbilities::allows('settings'))->toBeFalse()
        ->and(ChatAbilities::allows('model'))->toBeTrue();
});

it('resolves an ability from a permission name', function (): void {
    config()->set('filament-ai.chat.abilities.cost', 'see ai costs');

    // A user model with no can() opinion of its own comes back denied rather
    // than exploding -- which is what a host without that permission wants.
    $user = new class extends User
    {
        public function can($abilities, $arguments = []): bool
        {
            return $abilities === 'see ai costs';
        }
    };

    expect(ChatAbilities::allows('cost', $user))->toBeTrue();
});

it('resolves an ability from a callable', function (): void {
    config()->set('filament-ai.chat.abilities.model', fn ($user): bool => $user !== null);

    expect(ChatAbilities::resolve('model', new User))->toBeTrue()
        ->and(ChatAbilities::resolve('model', null))->toBeFalse();
});

it('lets a host Gate definition win over config', function (): void {
    config()->set('filament-ai.chat.abilities.cost', true);

    Gate::define(ChatAbilities::ability('cost'), fn (): bool => false);
    ChatAbilities::register();

    expect(ChatAbilities::allows('cost'))->toBeFalse();
});

it('resolves every ability at once for the page', function (): void {
    config()->set('filament-ai.chat.abilities.solve', false);

    $allowed = ChatAbilities::allowed();

    expect(array_keys($allowed))->toBe(ChatAbilities::names())
        ->and($allowed['solve'])->toBeFalse()
        ->and($allowed['view'])->toBeTrue();
});
