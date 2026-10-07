<div class="fai-empty" id="fai-empty" @if ($hasMessages) hidden @endif>
    <h1>{{ __('filament-ai::messages.chat.empty_title') }}</h1>
    <p>{{ __(\Murkrow\FilamentAi\Support\Knowledge::enabled() ? 'filament-ai::messages.chat.empty_body' : 'filament-ai::messages.chat.empty_body_records') }}</p>

    @if ($payload['suggestions'])
        <div class="fai-suggestions">
            @foreach ($payload['suggestions'] as $suggestion)
                <button type="button" class="fai-suggestion" data-prompt="{{ $suggestion }}">
                    {{ $suggestion }}
                </button>
            @endforeach
        </div>
    @endif
</div>
