<x-filament-panels::page>
    @include('filament-ai::partials.styles')

    <form wire:submit="save">
        {{ $this->form }}
    </form>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">What is not editable here, and why</x-slot>

        <p style="font-size:.875rem;color:var(--fai-muted);">
            The embedding model and its dimensions are missing from this form on purpose.
            Changing either invalidates every vector already stored, because vectors from
            two different models are not comparable and pgvector columns have a fixed
            width. Changing them is a deployment: update <code>config/filament-ai.php</code>, run
            <code>php artisan ai:vector:reindex</code>, then re-embed with
            <code>php artisan ai:ingest &lt;source&gt; --mode=embeddings_only</code>.
        </p>
    </x-filament::section>
</x-filament-panels::page>
