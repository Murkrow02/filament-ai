<aside class="rag-sidebar">
    <div class="rag-sidebar__head">
        <div class="rag-brand">
            @if ($payload['brand']['logo'])
                <img src="{{ $payload['brand']['logo'] }}" alt="">
            @endif
            <span>{{ $payload['brand']['name'] }}</span>
        </div>

        <button type="button" class="rag-new" id="rag-new">
            @include('rag::chat.partials.icon', ['name' => 'plus'])
            {{ __('rag::rag.chat.new_chat') }}
        </button>

        @if ($abilities['history'])
            <input type="search" class="rag-search" id="rag-search"
                   placeholder="{{ __('rag::rag.chat.search_placeholder') }}"
                   aria-label="{{ __('rag::rag.chat.search_placeholder') }}">
        @endif
    </div>

    {{-- Filled by rag-chat.js: the same renderer draws the initial list and
         every later update, so a new thread cannot look different from an old
         one. --}}
    <div class="rag-threads" id="rag-threads"></div>

    <div class="rag-sidebar__foot">
        <span></span>

        <button type="button" class="rag-icon-btn" id="rag-theme"
                title="{{ __('rag::rag.chat.toggle_theme') }}"
                aria-label="{{ __('rag::rag.chat.toggle_theme') }}">
            @include('rag::chat.partials.icon', ['name' => 'moon'])
        </button>
    </div>
</aside>
