<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Murkrow\FilamentAi\Tests\Fixtures\TestPanelProvider;
use Murkrow\FilamentAi\Tests\Fixtures\TestTenantPanelProvider;
use Murkrow\FilamentAi\Tests\Fixtures\TestUser;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

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
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            TestPanelProvider::class,
            TestTenantPanelProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filament-ai.filament.enabled', true);
        // The panel is built once at boot, so a resource gated by config has
        // to be switched on before the provider runs.
        $app['config']->set('filament-ai.agent.solving.enabled', true);
        $app['config']->set('auth.providers.users.model', TestUser::class);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function panelUser(): Authenticatable
    {
        TestUser::$panelAccess = true;
        TestUser::$teams = [];

        $user = new TestUser;
        $user->forceFill([
            'name' => 'Panel user',
            'email' => 'panel@example.test',
            'password' => bcrypt('secret'),
        ])->save();

        return $user;
    }
}
