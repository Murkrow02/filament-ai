<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Livewire\Attributes\Url;
use Murkrow\FilamentAi\Agent\Chat\AssistantAccess;
use Murkrow\FilamentAi\Agent\Chat\AssistantTurn;
use Murkrow\FilamentAi\Agent\Chat\PageContext;
use Murkrow\FilamentAi\Chat\ChatPayload;
use Throwable;
use UnitEnum;

/**
 * The assistant inside the panel.
 *
 * Deliberately thin: the chat itself is the package's own component, the same
 * one the standalone page at /ai/chat renders, and it talks to the HTTP
 * endpoints directly. This page only decides where it sits in the panel, who
 * may reach it, and which record the user came from.
 *
 * Nothing about a conversation lives on the component -- it never did -- and
 * now nothing about a turn does either: no Livewire round trip stands between
 * a token and the screen.
 */
class AssistantChat extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-sparkles';

    protected string $view = 'filament-ai::filament.pages.assistant';

    /** An agent conversation id. */
    #[Url(as: 'conversation')]
    public ?string $conversationId = null;

    /** A resource slug. */
    #[Url(as: 'resource')]
    public ?string $contextResource = null;

    #[Url(as: 'record')]
    public ?string $contextRecord = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return trim((string) config('filament-ai.agent.chat.slug', 'assistant'), '/');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('filament-ai::messages.assistant.navigation');
    }

    public function getTitle(): string
    {
        return (string) __('filament-ai::messages.assistant.title');
    }

    /**
     * The chat fills the page and carries its own header; a second one above
     * it would only push the conversation down. The title still names the tab.
     */
    public function getHeading(): string
    {
        return '';
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = config('filament-ai.agent.chat.navigation_group');

        return $group === null || $group === '' ? null : (string) $group;
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('filament-ai.agent.chat.navigation_sort', -1);
    }

    public static function canAccess(): bool
    {
        return AssistantAccess::allows();
    }

    /**
     * The topbar button: opens the chat about the page the user is on.
     */
    public static function topbarButton(): string
    {
        if (! static::canAccess()) {
            return '';
        }

        [$resource, $record] = PageContext::current();

        try {
            $url = static::getUrl(PageContext::parameters($resource, $record));
        } catch (Throwable) {
            return '';
        }

        return view('filament-ai::filament.assistant-button', ['url' => $url])->render();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $turn = app(AssistantTurn::class);

        $payload = app(ChatPayload::class)->build(auth()->user(), [
            'conversation' => $this->conversationId,
            'resource' => $this->contextResource,
            'record' => $this->contextRecord,
        ]);

        return [
            'installed' => $turn->available(),
            'payload' => $payload,
            'abilities' => $payload['abilities'],
        ];
    }
}
