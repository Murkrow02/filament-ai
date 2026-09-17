<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Murkrow\FilamentAi\Chat\ChatAbilities;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Sources\SourceRegistry;

/**
 * The one place a chat question turns into retrieval options.
 *
 * Hiding a control in the template is a presentation decision. This is the
 * authorization: a field whose ability is denied is dropped before validation
 * even sees it, so posting `top_k=30` by hand to an account that may not tune
 * retrieval gets the configured default, not an error and not the value.
 */
class AskRequest extends FormRequest
{
    /**
     * @var array<string, bool>|null
     */
    private ?array $allowed = null;

    public function authorize(): bool
    {
        return ChatAbilities::allows('view', $this->user());
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
            'message' => __('rag::rag.chat.forbidden'),
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
            // reason to refuse to answer the question. AskController resolves
            // it or opens a fresh conversation.
            'conversation' => ['sometimes', 'nullable', 'string', 'max:64'],
            'model' => ['sometimes', 'nullable', 'string', Rule::in($this->modelKeys())],
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['string', Rule::in(app(SourceRegistry::class)->keys())],
            // The ceiling matches rag.settings.overridable's, so a value the
            // control panel accepts can never be rejected here.
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'min_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'retrieval_only' => ['sometimes', 'boolean'],

            // Which half of the page asked: the knowledge pipeline, or the
            // agent with its tools and approvals.
            'mode' => ['sometimes', 'nullable', Rule::in(['knowledge', 'agent'])],

            // Agent mode only: keep trying in waves instead of answering once.
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

        if (! $this->allows('sources')) {
            $drop[] = 'sources';
        }

        if (! $this->allows('advanced')) {
            $drop = [...$drop, 'top_k', 'min_score', 'retrieval_only'];
        }

        if (! $this->allows('agent')) {
            // Without the agent there is no agent mode and nothing to solve
            // with: both fall back to the knowledge pipeline.
            $drop = [...$drop, 'mode', 'solve'];
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

    public function retrievalOnly(): bool
    {
        // Retrieval with no model call is a knowledge-mode idea: the agent has
        // no passages to return.
        return ! $this->isAgentMode() && (bool) $this->validated('retrieval_only', false);
    }

    public function isAgentMode(): bool
    {
        return $this->validated('mode') === 'agent';
    }

    /**
     * Whether this question should be answered by waves of attempts instead of
     * one turn. Only ever true when solving is switched on for real.
     */
    public function solves(): bool
    {
        return $this->isAgentMode()
            && (bool) $this->validated('solve', false)
            && (bool) config('rag.agent.solving.enabled', false);
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

    /**
     * Built through RetrievalOptions::fromArray so the chat speaks exactly the
     * same request vocabulary as the MCP tools and the console commands.
     */
    public function retrievalOptions(): RetrievalOptions
    {
        return RetrievalOptions::fromArray(array_filter([
            'sources' => $this->validated('sources'),
            'top_k' => $this->validated('top_k'),
            'min_score' => $this->validated('min_score'),
        ], static fn ($value): bool => $value !== null && $value !== []));
    }

    /**
     * What the answer was produced under, stored on the conversation so
     * reopening it restores the same settings.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return array_filter([
            'model' => $this->model(),
            'sources' => $this->validated('sources'),
            'top_k' => $this->validated('top_k'),
            'min_score' => $this->validated('min_score'),
        ], static fn ($value): bool => $value !== null && $value !== []);
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
        $keys = array_keys((array) config('rag.llm.available_models', []));

        // The model the application is actually configured to use is always
        // acceptable, whether or not it was listed as a choice. Without this,
        // an app that offers no alternatives rejects its own default.
        $default = (string) config('rag.llm.model', '');

        if ($default !== '' && ! in_array($default, $keys, true)) {
            $keys[] = $default;
        }

        return $keys;
    }
}
