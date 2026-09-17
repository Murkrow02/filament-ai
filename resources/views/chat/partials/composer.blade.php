<div class="rag-composer">
    <div class="rag-composer__inner">
        {{-- What the next question will run with. Populated by rag-chat.js so
             it stays in step with the settings modal. --}}
        <div class="rag-pills" id="rag-pills"></div>

        <div class="rag-box">
            <textarea class="rag-input" id="rag-input" rows="1" autocomplete="off"
                      placeholder="{{ __('rag::rag.chat.placeholder') }}"
                      aria-label="{{ __('rag::rag.chat.placeholder') }}"></textarea>

            <button type="button" class="rag-send" id="rag-send" aria-label="{{ __('rag::rag.chat.send') }}">
                @include('rag::chat.partials.icon', ['name' => 'send'])
            </button>
        </div>

        @if ($payload['solving'])
            {{-- Only rendered where iterative solving is switched on and this
                 user may use it; rag-chat.js shows it in agent mode only,
                 because waves of attempts are the agent's, not the pipeline's. --}}
            <label class="rag-toggle" id="rag-solve-row" hidden>
                <input type="checkbox" id="rag-solve">
                <span class="rag-toggle__label">{{ __('rag::rag.chat.js.iterative') }}</span>
                <span class="rag-toggle__hint">
                    {{ __('rag::rag.chat.js.iterativeHint', ['calls' => $payload['solving']['calls']]) }}
                </span>
            </label>
        @endif

        <p class="rag-hint">{{ __('rag::rag.chat.hint') }}</p>
    </div>
</div>
