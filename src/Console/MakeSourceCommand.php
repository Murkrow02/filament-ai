<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generates a knowledge source class from the package stub.
 *
 * Sources are code, not configuration: a class is type-checked, can take
 * constructor dependencies and is testable in isolation. Publish the stub with
 * `vendor:publish --tag=filament-ai-stubs` to change what this writes.
 */
class MakeSourceCommand extends GeneratorCommand
{
    protected $name = 'ai:make:source';

    protected $description = 'Create a knowledge source class';

    protected $type = 'Knowledge source';

    public function handle(): bool|null
    {
        $result = parent::handle();

        if ($result === false) {
            return $result;
        }

        $this->components->info(sprintf(
            'Register it by adding %s::class to the sources array in config/filament-ai.php.',
            $this->qualifyClass($this->getNameInput()),
        ));

        return $result;
    }

    protected function getStub(): string
    {
        $published = base_path('stubs/rag/source.stub');

        return file_exists($published) ? $published : __DIR__.'/../../stubs/source.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Knowledge';
    }

    /**
     * @param  string  $name
     */
    protected function buildClass($name): string
    {
        $class = class_basename($name);
        $model = $this->qualifyModel((string) ($this->option('model') ?: Str::before($class, 'Source')));
        $key = (string) ($this->option('key') ?: Str::snake(Str::pluralStudly(Str::before($class, 'Source'))));

        return str_replace(
            ['{{ model }}', '{{ modelClass }}', '{{ key }}', '{{ label }}', '{{ relation }}', '{{ text }}', '{{ position }}', '{{ positionLabel }}', '{{ positionLabelSingle }}'],
            [
                ltrim($model, '\\'),
                class_basename($model),
                $key,
                Str::headline($key),
                (string) $this->option('relation'),
                (string) $this->option('text'),
                (string) $this->option('position'),
                (string) $this->option('position-label'),
                (string) $this->option('position-label-single'),
            ],
            parent::buildClass($name),
        );
    }

    /**
     * @return array<int, array<int, mixed>|InputOption>
     */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'The Eloquent model the source indexes'],
            ['key', null, InputOption::VALUE_OPTIONAL, 'The source key used by ai:ingest and stored on every document'],
            ['relation', null, InputOption::VALUE_OPTIONAL, 'The has-many relation holding the ordered text', 'pages'],
            ['text', null, InputOption::VALUE_OPTIONAL, 'The column on the related model holding the text', 'content'],
            ['position', null, InputOption::VALUE_OPTIONAL, 'The column holding the human-meaningful position', 'number'],
            ['position-label', null, InputOption::VALUE_OPTIONAL, 'Citation label for a range of positions', 'Pages :start-:end'],
            ['position-label-single', null, InputOption::VALUE_OPTIONAL, 'Citation label for a single position', 'Page :start'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the class if it already exists'],
        ];
    }
}
