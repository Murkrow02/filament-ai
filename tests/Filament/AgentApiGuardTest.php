<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Responses\Data\ToolCall;
use Murkrow\FilamentAi\Tests\Fixtures\TestBook;
use Murkrow\FilamentAi\Tests\Fixtures\TestUser;

/*
 * A token request is authenticated on its own guard (Sanctum in a host), not
 * on the panel's. The resource tools must still see the user's policies.
 */

/** A policy like a host's: guests are denied, any signed-in user may read. */
final class TokenBookPolicy
{
    public function viewAny(TestUser $user): bool
    {
        return true;
    }

    public function view(TestUser $user, TestBook $book): bool
    {
        return true;
    }
}

beforeEach(function (): void {
    Gate::policy(TestBook::class, TokenBookPolicy::class);

    $this->artisan('migrate', [
        '--database' => 'testing',
        '--path' => dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations',
        '--realpath' => true,
    ])->run();

    config()->set('ai.conversations.generate_title', false);
    config()->set('filament-ai.api.resources', true);

    // A token guard standing in for Sanctum: the bearer token is the user id.
    Auth::viaRequest('test-token', static fn ($request) => TestUser::query()->find($request->bearerToken()));
    config()->set('auth.guards.token', ['driver' => 'test-token']);
});

it('runs resource tools for a user authenticated on a token guard', function (): void {
    $user = Auth::user();
    Auth::logout();
    TestBook::query()->create(['title' => 'Statuti del comune']);

    // The suite registers the API behind `auth` (web) and without resources:
    // put it on the token guard and boot the panel, as a host with
    // `api.resources` on gets it.
    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'filament-ai.api.ask');
    $route->action['middleware'] = [
        ...array_map(static fn ($m) => $m === 'auth' ? 'auth:token' : $m, (array) $route->action['middleware']),
        \Murkrow\FilamentAi\Http\Middleware\BootAssistantPanel::class,
    ];

    scriptAgent([new ToolCall('call_1', 'test_books_list', []), 'There is one.']);

    $done = $this->withHeader('Authorization', 'Bearer '.$user->getKey())
        ->postJson('/api/ai/ask?stream=0', ['question' => 'Books?'])
        ->assertOk()
        ->json();

    expect($done['answer'])->toBe('There is one.');

    $results = json_encode(\Laravel\Ai\Models\ConversationMessage::query()->get()->pluck('tool_results'));
    expect($results)->toContain('Statuti del comune');
});

it('acts for the token user even when the panel guard holds someone else', function (): void {
    $other = Auth::user();
    $tokenUser = TestUser::query()->forceCreate(['name' => 'Token', 'email' => 'token@example.test', 'password' => 'x']);

    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === 'filament-ai.api.conversations.index');
    $route->action['middleware'] = [
        ...array_map(static fn ($m) => $m === 'auth' ? 'auth:token' : $m, (array) $route->action['middleware']),
        \Murkrow\FilamentAi\Http\Middleware\BootAssistantPanel::class,
    ];

    // A thread of the user the panel guard (web) still holds.
    \Laravel\Ai\Models\Conversation::query()->create([
        'id' => (string) \Illuminate\Support\Str::uuid7(),
        'participant_type' => $other->getMorphClass(),
        'participant_id' => $other->getKey(),
        'title' => 'Not yours',
    ]);

    $this->withHeader('Authorization', 'Bearer '.$tokenUser->getKey())
        ->getJson('/api/ai/conversations')
        ->assertOk()
        ->assertJsonPath('data', []);
});
