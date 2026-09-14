<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Tests\Fixtures;

use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Murkrow\FilamentAi\Filament\RagPlugin;

/**
 * A minimal host panel, so the plugin is exercised the way a real application
 * registers it -- one `->plugin()` call and nothing else.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('testing')
            ->path('admin')
            ->middleware([
                EncryptCookies::class,
                ConvertEmptyStringsToNull::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugin(RagPlugin::make());
    }
}
