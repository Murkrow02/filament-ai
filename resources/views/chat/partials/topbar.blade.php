<header class="rag-topbar">
    <button type="button" class="rag-icon-btn" id="rag-sidebar-toggle"
            title="{{ __('rag::rag.chat.toggle_sidebar') }}"
            aria-label="{{ __('rag::rag.chat.toggle_sidebar') }}">
        @include('rag::chat.partials.icon', ['name' => 'menu'])
    </button>

    <span class="rag-topbar__title" id="rag-title">{{ $payload['current']['title'] ?? __('rag::rag.chat.new_chat') }}</span>

    @if ($payload['modes']['agent'])
        {{-- Two backends, one page: the knowledge pipeline answers with
             citations, the assistant answers with tools. Which one a thread
             belongs to is decided when it starts and never changes. --}}
        <div class="rag-modes" role="group" aria-label="{{ __('rag::rag.chat.title') }}">
            <button type="button" class="rag-mode" data-mode="knowledge"
                    title="{{ __('rag::rag.chat.js.knowledgeHint') }}">
                {{ __('rag::rag.chat.js.knowledge') }}
            </button>
            <button type="button" class="rag-mode" data-mode="agent"
                    title="{{ __('rag::rag.chat.js.agentHint') }}">
                {{ __('rag::rag.chat.js.agent') }}
            </button>
        </div>
    @endif

    @if ($payload['context']['label'])
        <span class="rag-context" title="{{ $payload['context']['label'] }}">
            @include('rag::chat.partials.icon', ['name' => 'book'])
            {{ $payload['context']['label'] }}
        </span>
    @endif

    @if ($showSettings)
        <button type="button" class="rag-icon-btn" id="rag-settings-open"
                title="{{ __('rag::rag.chat.settings') }}"
                aria-label="{{ __('rag::rag.chat.settings') }}">
            @include('rag::chat.partials.icon', ['name' => 'gear'])
        </button>
    @endif
</header>
