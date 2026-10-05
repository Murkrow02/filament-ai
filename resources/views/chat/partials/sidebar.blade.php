<aside class="fai-sidebar">
    <div class="fai-sidebar__head">
        <div class="fai-brand">
            @if ($payload['brand']['logo'])
                <img src="{{ $payload['brand']['logo'] }}" alt="">
            @endif
            <span>{{ $payload['brand']['name'] }}</span>
        </div>

        <button type="button" class="fai-new" id="fai-new">
            @include('filament-ai::chat.partials.icon', ['name' => 'plus'])
            {{ __('filament-ai::messages.chat.new_chat') }}
        </button>

        @if ($abilities['history'])
            <div class="fai-search-row">
                <input type="search" class="fai-search" id="fai-search"
                       placeholder="{{ __('filament-ai::messages.chat.search_placeholder') }}"
                       aria-label="{{ __('filament-ai::messages.chat.search_placeholder') }}">

                @if ($abilities['folders'] ?? false)
                    <button type="button" class="fai-icon-btn fai-new-folder" id="fai-new-folder"
                            title="{{ __('filament-ai::messages.chat.js.newFolder') }}"
                            aria-label="{{ __('filament-ai::messages.chat.js.newFolder') }}">
                        @include('filament-ai::chat.partials.icon', ['name' => 'folderPlus'])
                    </button>
                @endif
            </div>
        @endif
    </div>

    {{-- Filled by filament-ai-chat.js: the same renderer draws the initial list and
         every later update, so a new thread cannot look different from an old
         one. --}}
    <div class="fai-threads" id="fai-threads"></div>

    <div class="fai-sidebar__foot">
        <span></span>

        <button type="button" class="fai-icon-btn" id="fai-theme"
                title="{{ __('filament-ai::messages.chat.toggle_theme') }}"
                aria-label="{{ __('filament-ai::messages.chat.toggle_theme') }}">
            @include('filament-ai::chat.partials.icon', ['name' => 'moon'])
        </button>
    </div>
</aside>
