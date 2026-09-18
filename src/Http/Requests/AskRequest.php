<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Murkrow\FilamentAi\Chat\ChatAbilities;

/**
 * One question for the assistant.
 *
 * Hiding a control in the template is a presentation decision. This is the
 * authorization: a field whose ability is denied is dropped before validation
 * even sees it, so posting `model=...` by hand from an account that may not
 * choose the model gets the configured one, not an error and not the value.
 */
class AskRequest extends FormRequest
{
    /**
     * @var array<string, bool>|null
     */
    private ?array $allowed = null;

    public function authorize(): bool
    {
        return ChatAbilities::canUseChat($this->user());
    }

    /**
     * Always answer in JSON.
     *
     * The page calls this endpoint with `Accept: text/event-stream`, which
     * Laravel does not count as expecting JSON, so a failed rule redirected
     * back to the chat instead of reporting itself -- the browser saw a 302
     * and the user saw nothing at all.
     */
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => __('filament-ai::messages.chat.forbidden'),
        ], 403));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:2', 'max:4000'],
            // Not validated as a uuid: an unusable thread id means "this
            // thread is gone", which is a reason to start a new one, never a
            // reason to refuse to answer the question.
            'conversation' => ['sometimes', 'nullable', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', Rule::in($this->modelKeys())],

            // Keep trying in waves instead of answering once.
            'solve' => ['sometimes', 'boolean'],

            // The page the user is on, so "this order" resolves. Checked
            // against the resource's own policies before it is used, never
            // trusted as sent.
            'resource' => ['sometimes', 'nullable', 'string', 'max:191'],
            'record' => ['sometimes', 'nullable', 'string', 'max:191'],
        ];
    }

    /**
     * Strip everything this user is not allowed to set, before validation.
     */
    protected function prepareForValidation(): void
    {
        $drop = [];

        if (! $this->allows('model')) {
            $drop[] = 'model';
        }

        if (! $this->allows('solve')) {
            $drop[] = 'solve';
        }

        if ($drop !== []) {
            $this->replace($this->except($drop));
        }
    }

    public function question(): string
    {
        return trim((string) $this->validated('question'));
    }

    public function model(): ?string
    {
        $model = $this->validated('model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Whether this question should be answered by waves of attempts instead of
     * one turn. Only ever true when solving is switched on for real.
     */
    public function solves(): bool
    {
        return (bool) $this->validated('solve', false) && (bool) config('filament-ai.agent.solving.enabled', false);
    }

    public function conversationId(): ?string
    {
        $conversation = $this->validated('conversation');

        return is_string($conversation) && $conversation !== '' ? $conversation : null;
    }

    public function contextResource(): ?string
    {
        $resource = $this->validated('resource');

        return is_string($resource) && $resource !== '' ? $resource : null;
    }

    public function contextRecord(): ?string
    {
        $record = $this->validated('record');

        return is_string($record) && $record !== '' ? $record : null;
    }

    private function allows(string $ability): bool
    {
        $this->allowed ??= ChatAbilities::allowed($this->user());

        return $this->allowed[$ability] ?? false;
    }

    /**
     * @return array<int, string>
     */
    private function modelKeys(): array
    {
        $keys = array_keys((array) config('filament-ai.llm.available_models', []));

        // The model the application is actually configured to use is always
        // acceptable, whether or not it was listed as a choice. Without this,
        // an app that offers no alternatives rejects its own default.
        foreach ([config('filament-ai.agent.model'), config('filament-ai.llm.model')] as $default) {
            if (filled($default) && ! in_array((string) $default, $keys, true)) {
                $keys[] = (string) $default;
            }
        }

        return $keys;
    }
}
