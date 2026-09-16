<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Schema;

/**
 * Boots the chat page's HTTP surface.
 *
 * The Feature base case has no session, no encryption key and no users table,
 * because nothing under it needs a request that belongs to somebody. The chat
 * needs all three, and it needs no Filament at all -- the whole point of the
 * page is that it works without a panel, so testing it inside one would prove
 * the wrong thing.
 */
abstract class WebTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', AuthUser::class);

        $app['config']->set('rag.chat.enabled', true);
        $app['config']->set('rag.chat.middleware', ['web']);
        $app['config']->set('rag.chat.throttle', null);

        // The default streams; the blocking path is easier to assert against,
        // so the streaming tests turn it back on for themselves.
        $app['config']->set('rag.answering.stream', false);
    }

    protected function user(string $email = 'reader@example.test'): Authenticatable
    {
        $user = new AuthUser;
        $user->setTable('users');
        $user->forceFill([
            'name' => 'Reader',
            'email' => $email,
            'password' => bcrypt('secret'),
        ])->save();

        return $user;
    }
}
