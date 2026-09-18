<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament;

use Filament\Contracts\Plugin;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Murkrow\FilamentAi\Agent\Chat\AssistantAccess;
use Murkrow\FilamentAi\Filament\Pages\AgentSettings;
use Murkrow\FilamentAi\Filament\Pages\AssistantChat;
use Illuminate\Support\Facades\Route;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Filament\Pages\IngestKnowledge;
use Murkrow\FilamentAi\Filament\Pages\KnowledgeDashboard;
use Murkrow\FilamentAi\Filament\Pages\KnowledgePlayground;
use Murkrow\FilamentAi\Filament\Pages\KnowledgeSettings;
use Murkrow\FilamentAi\Filament\Resources\DocumentResource;
use Murkrow\FilamentAi\Filament\Resources\IngestionRunResource;
use Murkrow\FilamentAi\Filament\Resources\QueryResource;
use Murkrow\FilamentAi\Filament\Resources\SolveRunResource;
use Murkrow\FilamentAi\Filament\Widgets\IngestionThroughputChart;
use Murkrow\FilamentAi\Filament\Widgets\KnowledgeStatsOverview;
use Murkrow\FilamentAi\Filament\Widgets\LatestRunsTable;
use Murkrow\FilamentAi\Filament\Widgets\SourceCoverageChart;

/**
 * The control panel.
 *
 * Registered explicitly rather than discovered, because the host panel's
 * `discoverResources()` only scans its own app directories -- a package's
 * classes are invisible to it.
 *
 *     ->plugin(\Murkrow\FilamentAi\Filament\FilamentAiPlugin::make())
 *
 * Every page and resource is individually switchable in config, so a host can
 * expose the dashboard to operators while keeping ingestion controls to itself.
 */
class FilamentAiPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filament-ai';
    }

    public function register(Panel $panel): void
    {
        if (! config('filament-ai.enabled', true) || ! config('filament-ai.filament.enabled', true)) {
            return;
        }

        $panel
            ->resources($this->resources())
            ->pages($this->pages())
            ->widgets($this->widgets());

        if (AssistantAccess::enabled() && config('filament-ai.agent.chat.topbar_button', true)) {
            // Resolved at render time, not here: the button needs the
            // signed-in user and the page being rendered.
            $panel->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, static fn (): string => AssistantChat::topbarButton());
        }
    }

    /**
     * A link out to the standalone chat page.
     *
     * A plain URL item rather than a Filament page: the chat is a separate
     * document with its own stylesheet and no Livewire on it at all.
     */
    protected function registerChatLink(Panel $panel): void
    {
        if (! config('filament-ai.filament.pages.chat_link', true)) {
            return;
        }

        if (! Route::has('filament-ai.chat.index')) {
            return;
        }

        $url = route('filament-ai.chat.index');

        // boot() runs on every panel boot, and a panel boots more than once per
        // request -- every Livewire component mounted inside it boots it again.
        // Panel::navigationItems() appends rather than replaces, so without this
        // guard the sidebar accumulates one chat link per boot. Deduplicating
        // against the panel's own items (rather than a static flag) keeps this
        // correct under Octane, where the panel is rebuilt but statics persist.
        foreach ($panel->getNavigationItems() as $item) {
            if ($item->getUrl() === $url) {
                return;
            }
        }

        // A panel in SPA mode puts wire:navigate on every in-app link, and
        // Livewire would then swap this page's body into the panel's document
        // -- the panel's <head>, none of the chat's. The chat has to be a real
        // navigation, so its URLs are declared an exception.
        if (FilamentView::hasSpaMode()) {
            FilamentView::spaUrlExceptions([
                route('filament-ai.chat.index'),
                route('filament-ai.chat.index').'/*',
            ]);
        }

        $panel->navigationItems([
            NavigationItem::make('fai-chat')
                ->label(fn (): string => (string) __('filament-ai::messages.chat.title'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->group(fn () => config('filament-ai.filament.navigation_group', 'Knowledge'))
                ->sort((int) config('filament-ai.filament.navigation_sort', 90) + 3)
                ->url(fn () => $url)
                // Both checks belong here rather than around the
                // registration: the panel is built once per worker under
                // Octane, and a page switched off in the settings has to
                // disappear on the next render, not on the next deploy.
                ->visible(fn (): bool => config('filament-ai.chat.enabled', true) && ChatAbilities::allows('view')),
        ]);
    }

    public function boot(Panel $panel): void
    {
        // Not register(): a panel provider builds its panel during the
        // register phase, before this package's boot() has bound the chat
        // routes, so Route::has() there is always false.
        $this->registerChatLink($panel);
    }

    /**
     * @return array<int, class-string>
     */
    protected function resources(): array
    {
        return array_values(array_filter([
            config('filament-ai.filament.resources.runs', true) ? IngestionRunResource::class : null,
            config('filament-ai.filament.resources.documents', true) ? DocumentResource::class : null,
            config('filament-ai.filament.resources.queries', true) ? QueryResource::class : null,
            // Only worth a navigation entry where solving is switched on.
            config('filament-ai.agent.solving.enabled', false) ? SolveRunResource::class : null,
        ]));
    }

    /**
     * @return array<int, class-string>
     */
    protected function pages(): array
    {
        return array_values(array_filter([
            config('filament-ai.filament.pages.dashboard', true) ? KnowledgeDashboard::class : null,
            config('filament-ai.filament.pages.ingest', true) ? IngestKnowledge::class : null,
            config('filament-ai.filament.pages.playground', true) ? KnowledgePlayground::class : null,
            config('filament-ai.filament.pages.settings', true) ? KnowledgeSettings::class : null,
            AssistantAccess::enabled() ? AssistantChat::class : null,
            // Registered even when the chat is off: switching it back on
            // is one of the things this page is for.
            config('filament-ai.enabled', true) && config('filament-ai.agent.enabled', true) && config('filament-ai.agent.settings.enabled', true)
                ? AgentSettings::class
                : null,
        ]));
    }

    /**
     * @return array<int, class-string>
     */
    protected function widgets(): array
    {
        if (! config('filament-ai.filament.pages.dashboard', true)) {
            return [];
        }

        return [
            KnowledgeStatsOverview::class,
            IngestionThroughputChart::class,
            SourceCoverageChart::class,
            LatestRunsTable::class,
        ];
    }
}
