<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Illuminate\Database\Eloquent\Model;

/**
 * What running a write through the resource's form produced.
 */
final readonly class FormOutcome
{
    /**
     * @param  array<string, mixed>  $data  the dehydrated form state, as the page would have saved it
     * @param  array<string, list<string>>  $errors  validation messages by field name; empty when valid
     * @param  list<string>  $ignored  submitted keys the form did not take (unknown, hidden, disabled or not fillable)
     * @param  array<string, array{before: mixed, after: mixed}>  $changes  what the write changes, by field name
     */
    public function __construct(
        public array $data = [],
        public array $errors = [],
        public array $ignored = [],
        public array $changes = [],
        public ?Model $record = null,
        public ?string $failure = null,
    ) {}

    public function passes(): bool
    {
        return $this->errors === [] && $this->failure === null;
    }

    /**
     * An error the model can read and act on: which field, and why.
     */
    public function errorMessage(): string
    {
        if ($this->failure !== null) {
            return 'Error: '.$this->failure;
        }

        $messages = [];

        foreach ($this->errors as $field => $fieldMessages) {
            foreach ($fieldMessages as $message) {
                $messages[] = "[{$field}] {$message}";
            }
        }

        return 'Error: '.implode(' ', $messages);
    }
}
