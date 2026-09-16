<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Exceptions;

use Murkrow\FilamentAi\Contracts\KnowledgeSource;

class InvalidSourceConfigurationException extends RagException
{
    public static function notAModel(string $source, string $class): self
    {
        return new self("Knowledge source [{$source}] points at [{$class}], which is not an Eloquent model.");
    }

    public static function notARelation(string $source, string $relation, string $model): self
    {
        return new self("Knowledge source [{$source}] declares segment relation [{$relation}], which does not exist on [{$model}].");
    }

    public static function notASource(mixed $entry): self
    {
        $given = is_object($entry) ? $entry::class : (is_string($entry) ? $entry : get_debug_type($entry));

        return new self("[{$given}] is registered under rag.sources but is not a ".KnowledgeSource::class.'.');
    }
}
