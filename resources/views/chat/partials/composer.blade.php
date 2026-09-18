<div class="fai-composer">
    <div class="fai-composer__inner">
        {{-- What the next question will run with. Populated by filament-ai-chat.js so
             it stays in step with the settings modal. --}}
        <div class="fai-pills" id="fai-pills"></div>

        <div class="fai-box">
            <textarea class="fai-input" id="fai-input" rows="1" autocomplete="off"
                      placeholder="{{ __('filament-ai::messages.chat.placeholder') }}"
                      aria-label="{{ __('filament-ai::messages.chat.placeholder') }}"></textarea>

            <button type="button" class="fai-send" id="fai-send" aria-label="{{ __('filament-ai::messages.chat.send') }}">
                @include('filament-ai::chat.partials.icon', ['name' => 'send'])
            </button>
        </div>

        @if ($payload['solving'])
            {{-- Only rendered where iterative solving is switched on and this
                 user holds the `solve` ability: it multiplies what a question
                 costs, so it is never simply "on". --}}
            <label class="fai-toggle" id="fai-solve-row">
                <input type="checkbox" id="fai-solve">
                <span class="fai-toggle__label">{{ __('filament-ai::messages.chat.js.iterative') }}</span>
                <span class="fai-toggle__hint">
                    {{ __('filament-ai::messages.chat.js.iterativeHint', ['calls' => $payload['solving']['calls']]) }}
                </span>
            </label>
        @endif

        <p class="fai-hint">{{ __('filament-ai::messages.chat.hint') }}</p>
    </div>
</div>
