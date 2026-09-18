{{--
    Everything that is not "type a question".

    One field today -- which model answers -- behind its own ability, and the
    server drops it again for a user who may not set it; see
    AskRequest::prepareForValidation().
--}}
<div class="rag-modal" id="rag-settings" role="dialog" aria-modal="true"
     aria-labelledby="rag-settings-title" hidden>
    <h2 id="rag-settings-title">{{ __('rag::rag.chat.settings') }}</h2>
    <p class="rag-modal__sub">{{ __('rag::rag.chat.settings_sub') }}</p>

    <div class="rag-field">
        <label class="rag-field__label" for="rag-set-model">{{ __('rag::rag.chat.model') }}</label>
        <select id="rag-set-model">
            @foreach ($payload['models'] as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
        <span class="rag-field__help">{{ __('rag::rag.chat.model_help') }}</span>
    </div>

    <div class="rag-modal__foot">
        <button type="button" class="rag-btn" id="rag-settings-reset">{{ __('rag::rag.chat.reset') }}</button>
        <button type="button" class="rag-btn" id="rag-settings-cancel">{{ __('rag::rag.chat.cancel') }}</button>
        <button type="button" class="rag-btn rag-btn--primary" id="rag-settings-save">{{ __('rag::rag.chat.save') }}</button>
    </div>
</div>
