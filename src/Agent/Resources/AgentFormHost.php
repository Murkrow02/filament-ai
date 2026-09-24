<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Agent\Resources;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Resources\Resource;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * The Livewire component a resource's form is built against when the agent
 * writes a record.
 *
 * Never mounted, never rendered. It exists because a Filament schema reads its
 * state from, and validates through, a Livewire component: with this one the
 * agent's writes go through the resource's own form -- the same visibility,
 * disabled state, validation and dehydration as the panel's create and edit
 * pages -- instead of a re-implementation of them.
 *
 * It is configured exactly the way `CreateRecord::defaultForm()` and
 * `EditRecord::defaultForm()` configure theirs: operation, model or record,
 * and a `data` state path.
 *
 * @internal
 */
final class AgentFormHost extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var class-string<\Filament\Resources\Resource>|null */
    private ?string $resource = null;

    private string $operation = 'create';

    private Model|string|null $record = null;

    /**
     * @param  class-string<\Filament\Resources\Resource>  $resource
     */
    public static function for(string $resource, string $operation, Model|string $record): self
    {
        $host = app(self::class);
        $host->setId('filament-ai-agent-form');
        $host->resource = $resource;
        $host->operation = $operation;
        $host->record = $record;

        return $host;
    }

    public function form(Schema $schema): Schema
    {
        $resource = $this->resource;

        $schema
            ->operation($this->operation)
            ->model($this->record)
            ->statePath('data');

        return $resource === null ? $schema : $resource::form($schema);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
