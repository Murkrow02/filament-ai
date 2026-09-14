<x-filament-panels::page>
    @include('rag::partials.styles')

    <form wire:submit="start">
        {{ $this->form }}
    </form>

    @if ($estimate)
        <x-filament::section icon="heroicon-o-calculator">
            <x-slot name="heading">Estimate</x-slot>
            <x-slot name="description">
                Extrapolated from a sample of documents, so treat it as an order of
                magnitude rather than an invoice.
            </x-slot>

            <dl style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(8rem,1fr));">
                @foreach ([
                    'Documents' => number_format((int) $estimate['documents']),
                    'Segments' => number_format((int) $estimate['segments']),
                    'Chunks' => '~' . number_format((int) $estimate['chunks']),
                    'Tokens' => '~' . number_format((int) $estimate['tokens']),
                ] as $label => $value)
                    <div>
                        <dt style="font-size:.875rem;color:var(--rag-muted);">{{ $label }}</dt>
                        <dd style="font-size:1.125rem;font-weight:600;font-variant-numeric:tabular-nums;">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <p style="margin-top:1rem;font-size:.875rem;color:var(--rag-muted);">
                Estimated embedding cost:
                <span style="font-weight:600;color:var(--rag-strong);">
                    {{ \Murkrow\FilamentAi\Ingestion\CostCalculator::format((int) $estimate['cost_micros'], 4) }}
                </span>
                using {{ config('rag.embeddings.model') }}.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Before a large run</x-slot>

        <ul style="list-style:disc;padding-inline-start:1.25rem;font-size:.875rem;color:var(--rag-muted);">
            <li>
                A worker must be consuming the
                <code>{{ config('rag.queue.queue') }}</code> queue on the
                <code>{{ config('rag.queue.connection') }}</code> connection, otherwise the
                run sits at zero forever.
            </li>
            <li>
                Incremental mode re-embeds only text that changed, so re-running it over an
                unchanged corpus costs nothing.
            </li>
            <li>
                Build the vector index after a bulk load rather than before:
                <code>php artisan rag:vector:reindex</code>.
            </li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
