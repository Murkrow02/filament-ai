{{--
    Everything that is not "type a question".

    One field today -- which model answers -- behind its own ability, and the
    server drops it again for a user who may not set it; see
    AskRequest::prepareForValidation().
--}}
<div class="fai-modal" id="fai-settings" role="dialog" aria-modal="true"
     aria-labelledby="fai-settings-title" hidden>
    <h2 id="fai-settings-title">{{ __('filament-ai::messages.chat.settings') }}</h2>
    <p class="fai-modal__sub">{{ __('filament-ai::messages.chat.settings_sub') }}</p>

    <div class="fai-field">
        <label class="fai-field__label" for="fai-set-model">{{ __('filament-ai::messages.chat.model') }}</label>
        <select id="fai-set-model">
            @foreach ($payload['models'] as $id => $label)
                <option value="{{ $id }}">{{ $label }}</option>
            @endforeach
        </select>
        <span class="fai-field__help">{{ __('filament-ai::messages.chat.model_help') }}</span>
    </div>

    <div class="fai-modal__foot">
        <button type="button" class="fai-btn" id="fai-settings-reset">{{ __('filament-ai::messages.chat.reset') }}</button>
        <button type="button" class="fai-btn" id="fai-settings-cancel">{{ __('filament-ai::messages.chat.cancel') }}</button>
        <button type="button" class="fai-btn fai-btn--primary" id="fai-settings-save">{{ __('filament-ai::messages.chat.save') }}</button>
    </div>
</div>
