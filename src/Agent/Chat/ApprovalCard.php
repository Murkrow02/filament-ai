<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Chat;

/**
 * The card a host write tool puts to the user, in the same shape as the
 * cards of the resource write tools (see docs/api.md): a title, the record it
 * is about, one row per thing that changes, a summary and warnings.
 *
 *     ApprovalCard::make('Registra vendita')
 *         ->record('Negozio Positano')
 *         ->change('Camicia lino coreana, M', '1 × 49,00 €')
 *         ->change('Totale', '49,00 €')
 *         ->warning('Ultimo pezzo in magazzino.');
 */
final class ApprovalCard
{
    private ?string $record = null;

    /** @var list<array{label: string, before: string|null, after: string|null, missing: bool}> */
    private array $changes = [];

    private ?string $summary = null;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private string $title) {}

    public static function make(string $title): self
    {
        return new self($title);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function record(?string $record): self
    {
        $this->record = $record;

        return $this;
    }

    /**
     * One row: what it is, what it becomes, and what it was when it already
     * existed.
     */
    public function change(string $label, mixed $after, mixed $before = null): self
    {
        $this->changes[] = ['label' => $label, 'before' => self::text($before), 'after' => self::text($after), 'missing' => false];

        return $this;
    }

    /**
     * A value the request left empty, flagged so it is noticed before
     * approving rather than after.
     */
    public function missing(string $label): self
    {
        $this->changes[] = ['label' => $label, 'before' => null, 'after' => null, 'missing' => true];

        return $this;
    }

    public function summary(?string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function warning(string $warning): self
    {
        $this->warnings[] = $warning;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return array{title: string, record: string|null, changes: list<array<string, mixed>>, summary: string|null, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'record' => $this->record,
            'changes' => $this->changes,
            'summary' => $this->summary,
            'warnings' => $this->warnings,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? (string) __('filament-ai::messages.approval.yes') : (string) __('filament-ai::messages.approval.no'),
            is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
