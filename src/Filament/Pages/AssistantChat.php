<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Filament\Pages;

use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Responses\AgentResponse;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Murkrow\FilamentAi\Agent\Chat\AssistantAccess;
use Murkrow\FilamentAi\Agent\Chat\ConversationTranscript;
use Murkrow\FilamentAi\Agent\Chat\PageContext;
use Murkrow\FilamentAi\Agent\PanelAssistant;
use Murkrow\FilamentAi\Agent\Resources\RecordPresenter;
use Throwable;
use UnitEnum;

/**
 * The panel assistant, as a native Filament page.
 *
 * Nothing about a conversation lives on the component: messages and pending
 * approvals are read from laravel/ai's store on every render. The public
 * properties (conversation, context) are hints the browser can rewrite, so
 * every action resolves them again -- the conversation must belong to the
 * user, the record must pass the resource's query and `view` policy.
 */
class AssistantChat extends Page
{
    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-sparkles';

    protected string $view = 'rag::filament.pages.assistant';

    #[Url(as: 'conversation')]
    public ?string $conversationId = null;

    /** A resource slug. */
    #[Url(as: 'resource')]
    public ?string $contextResource = null;

    #[Url(as: 'record')]
    public ?string $contextRecord = null;

    public string $prompt = '';

    public ?string $error = null;

    /**
     * Decisions taken so far on the pending tool calls, by call id. The turn
     * resumes once every pending call has one.
     *
     * @var array<string, bool>
     */
    #[Locked]
    public array $decisions = [];

    public static function getSlug(?Panel $panel = null): string
    {
        return trim((string) config('rag.agent.chat.slug', 'assistant'), '/');
    }

    public static function getNavigationLabel(): string
    {
        return (string) __('rag::rag.assistant.navigation');
    }

    public function getTitle(): string
    {
        return (string) __('rag::rag.assistant.title');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = config('rag.agent.chat.navigation_group');

        return $group === null || $group === '' ? null : (string) $group;
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('rag.agent.chat.navigation_sort', -1);
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

        return view('rag::filament.assistant-button', ['url' => $url])->render();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->ownedConversation() === null) {
            $this->conversationId = null;
        }
    }

    public function send(): void
    {
        $text = trim($this->prompt);

        if ($text === '') {
            return;
        }

        // A paused turn must be decided first: laravel/ai resumes it from the
        // latest stored turn, and a new message would bury it.
        $conversation = $this->ownedConversation();

        if ($conversation !== null && app(ConversationTranscript::class)->pendingApprovals($conversation) !== []) {
            return;
        }

        $this->prompt = '';

        $this->run(static fn (PanelAssistant $assistant): AgentResponse => $assistant->prompt($text));
    }

    public function decide(string $callId, bool $approve): void
    {
        $conversation = $this->ownedConversation();

        if ($conversation === null) {
            return;
        }

        $pending = app(ConversationTranscript::class)->pendingApprovals($conversation);

        if (! array_key_exists($callId, $pending)) {
            return;
        }

        $this->decisions[$callId] = $approve;

        if (array_diff_key($pending, $this->decisions) !== []) {
            return;
        }

        $decisions = [];

        foreach (array_keys($pending) as $id) {
            $decisions[$id] = $this->decisions[$id]
                ? Decision::approve()
                : Decision::reject((string) __('rag::rag.assistant.rejected_reason'));
        }

        $this->decisions = [];

        $this->run(static fn (PanelAssistant $assistant): AgentResponse => $assistant->prompt(Decisions::from($decisions)));
    }

    public function newChat(): void
    {
        $this->reset(['conversationId', 'decisions', 'error', 'prompt']);
    }

    public function openChat(string $conversationId): void
    {
        $user = auth()->user();

        if ($user === null || ! app(ConversationTranscript::class)->owns($conversationId, $user)) {
            return;
        }

        $this->conversationId = $conversationId;
        $this->reset(['decisions', 'error']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $transcript = app(ConversationTranscript::class);

        if (! $transcript->available()) {
            return ['installed' => false, 'messages' => [], 'pending' => [], 'history' => [], 'contextLabel' => null];
        }

        $conversation = $this->ownedConversation();

        return [
            'installed' => true,
            'messages' => $conversation === null ? [] : $transcript->messages($conversation),
            'pending' => $conversation === null ? [] : $transcript->pendingApprovals($conversation),
            'history' => $transcript->recent(auth()->user(), (int) config('rag.agent.chat.history', 20)),
            'contextLabel' => $this->contextLabel(),
        ];
    }

    /**
     * @param  Closure(PanelAssistant): AgentResponse  $call
     */
    private function run(Closure $call): void
    {
        abort_unless(static::canAccess(), 403);

        $this->error = null;

        $user = auth()->user();
        $conversation = $this->ownedConversation();
        [$resource, $record] = $this->context();

        /** @var PanelAssistant $assistant */
        $assistant = app((string) config('rag.agent.assistant', PanelAssistant::class));

        $assistant = $conversation === null
            ? $assistant->forUser($user)
            : $assistant->continue($conversation, as: $user);

        $assistant->inPanel(Filament::getCurrentOrDefaultPanel())->onPage($resource, $record);

        try {
            $response = $call($assistant);
            $this->conversationId = $response->conversationId ?? $conversation;
        } catch (Throwable $exception) {
            report($exception);

            // The message can carry SQL or provider internals: shown only
            // where the application already shows them.
            $this->error = (string) __('rag::rag.assistant.failed')
                .(config('app.debug') ? ' ('.$exception->getMessage().')' : '');
        }
    }

    private function ownedConversation(): ?string
    {
        $user = auth()->user();

        if ($this->conversationId === null || $user === null) {
            return null;
        }

        $transcript = app(ConversationTranscript::class);

        return $transcript->available() && $transcript->owns($this->conversationId, $user)
            ? $this->conversationId
            : null;
    }

    /**
     * @return array{0: class-string<\Filament\Resources\Resource>|null, 1: Model|null}
     */
    private function context(): array
    {
        if ($this->contextResource === null || $this->contextResource === '') {
            return [null, null];
        }

        $resource = PageContext::resourceForSlug($this->contextResource);

        if ($resource === null || ! $resource::canViewAny()) {
            return [null, null];
        }

        if ($this->contextRecord === null || $this->contextRecord === '') {
            return [$resource, null];
        }

        $record = $resource::getEloquentQuery()->whereKey($this->contextRecord)->first();

        return [$resource, $record !== null && $resource::canView($record) ? $record : null];
    }

    private function contextLabel(): ?string
    {
        [$resource, $record] = $this->context();

        if ($resource === null) {
            return null;
        }

        if ($record === null) {
            return (string) $resource::getPluralModelLabel();
        }

        return (string) __('rag::rag.assistant.context', [
            'label' => $resource::getModelLabel(),
            'title' => RecordPresenter::titleFor($resource, $record) ?? $record->getKey(),
        ]);
    }
}
