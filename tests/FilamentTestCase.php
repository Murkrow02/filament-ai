<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Murkrow\FilamentAi\Tests\Fixtures\TestPanelProvider;

/**
 * Boots a real Filament panel with the plugin registered exactly the way a
 * host registers it: one `->plugin()` call.
 *
 * The panel is worth testing for real rather than by asserting class lists.
 * Most of what can break here -- a renamed API, a schema built in the wrong
 * lifecycle phase -- only shows up when a component actually renders.
 */
abstract class FilamentTestCase extends TestCase
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

        $this->actingAs($this->panelUser());
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            TestPanelProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('rag.filament.enabled', true);
        // The panel is built once at boot, so a resource gated by config has
        // to be switched on before the provider runs.
        $app['config']->set('rag.agent.solving.enabled', true);
        $app['config']->set('auth.providers.users.model', AuthUser::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function panelUser(): Authenticatable
    {
        $user = new AuthUser;
        $user->setTable('users');
        $user->forceFill([
            'name' => 'Panel user',
            'email' => 'panel@example.test',
            'password' => bcrypt('secret'),
        ])->save();

        return $user;
    }
}
