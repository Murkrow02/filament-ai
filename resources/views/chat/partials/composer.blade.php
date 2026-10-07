<div class="fai-composer">
    <div class="fai-composer__inner">
        {{-- What the next question will run with. Populated by filament-ai-chat.js so
             it stays in step with the settings modal. --}}
        <div class="fai-pills" id="fai-pills"></div>

        <div class="fai-box">
            <textarea class="fai-input" id="fai-input" rows="1" autocomplete="off"
                      placeholder="{{ __('filament-ai::messages.chat.placeholder') }}"
                      aria-label="{{ __('filament-ai::messages.chat.placeholder') }}"></textarea>

            {{-- Dictation. Hidden until filament-ai-chat.js has checked the browser can
                 record and the payload allows it, so it never shows up dead. --}}
            <button type="button" class="fai-mic" id="fai-mic" hidden
                    aria-label="{{ __('filament-ai::messages.chat.js.dictate') }}"
                    title="{{ __('filament-ai::messages.chat.js.dictate') }}">
                @include('filament-ai::chat.partials.icon', ['name' => 'mic'])
                <span class="fai-mic__timer" id="fai-mic-timer" aria-live="polite"></span>
            </button>

            <button type="button" class="fai-send" id="fai-send" aria-label="{{ __('filament-ai::messages.chat.send') }}">
                @include('filament-ai::chat.partials.icon', ['name' => 'send'])
            </button>
        </div>

        <p class="fai-hint">{{ __(\Murkrow\FilamentAi\Support\Knowledge::enabled() ? 'filament-ai::messages.chat.hint' : 'filament-ai::messages.chat.hint_records') }}</p>
    </div>
</div>
