<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Console;

use Illuminate\Console\Command;
use Murkrow\FilamentAi\Contracts\Answerer;
use Murkrow\FilamentAi\Data\AnswerOptions;
use Murkrow\FilamentAi\Data\Citation;
use Murkrow\FilamentAi\Data\RetrievalOptions;
use Murkrow\FilamentAi\Enums\QueryChannel;
use Murkrow\FilamentAi\Ingestion\CostCalculator;

class AskCommand extends Command
{
    protected $signature = 'ai:ask
                            {question* : The question to answer}
                            {--source=* : Restrict to one or more sources}
                            {--document=* : Restrict to specific document identifiers}
                            {--k= : How many passages to ground the answer in}
                            {--model= : Override the generation model}
                            {--stream : Stream the answer as it is generated}';

    protected $description = 'Answer a question from the knowledge base, with citations';

    public function handle(Answerer $answerer): int
    {
        $question = implode(' ', (array) $this->argument('question'));

        $options = new AnswerOptions(
            retrieval: new RetrievalOptions(
                sourceKeys: $this->listOption('source'),
                externalIds: $this->listOption('document'),
                topK: $this->option('k') === null ? null : (int) $this->option('k'),
            ),
            model: $this->option('model') === null ? null : (string) $this->option('model'),
            channel: QueryChannel::Cli,
        );

        $this->newLine();

        if ($this->option('stream')) {
            $stream = $answerer->stream($question, $options);

            foreach ($stream as $delta) {
                $this->output->write($delta);
            }

            $result = $stream->getReturn();
            $this->newLine(2);
        } else {
            $result = $answerer->answer($question, $options);

            $this->line($result->answer);
            $this->newLine();
        }

        $used = $result->usedCitations();

        if ($used->isNotEmpty()) {
            $this->line('<fg=gray>Sources:</>');

            foreach ($used as $citation) {
                /** @var Citation $citation */
                $this->line("  <fg=cyan>[#{$citation->marker}]</> {$citation->label} <fg=gray>(".number_format($citation->chunk->score, 3).')</>');
            }

            $this->newLine();
        }

        $this->components->twoColumnDetail('model', $result->model ?? '<fg=gray>none (refused)</>');
        $this->components->twoColumnDetail('latency', $result->latencyMs.' ms');
        $this->components->twoColumnDetail('tokens', $result->usage->promptTokens.' in / '.$result->usage->completionTokens.' out');
        $this->components->twoColumnDetail('cost', CostCalculator::format($result->usage->costMicros, 5));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function listOption(string $name): ?array
    {
        $values = array_values(array_filter((array) $this->option($name)));

        return $values === [] ? null : array_map(strval(...), $values);
    }
}
