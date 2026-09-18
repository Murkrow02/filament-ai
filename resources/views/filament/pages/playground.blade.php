<x-filament-panels::page>
    @include('filament-ai::partials.styles')

    <form wire:submit="run">
        {{ $this->form }}

        <div style="display:flex;align-items:center;gap:.75rem;margin-top:1rem;">
            <x-filament::button type="submit" icon="heroicon-o-play" wire:loading.attr="disabled">
                Run
            </x-filament::button>

            <span wire:loading wire:target="run" style="font-size:.875rem;color:var(--fai-muted);">
                Retrieving...
            </span>
        </div>
    </form>

    @if ($error)
        <x-filament::section>
            <x-slot name="heading">Error</x-slot>
            <p style="font-size:.875rem;color:var(--danger-600);">{{ $error }}</p>
        </x-filament::section>
    @endif

    @if ($answer !== null)
        <x-filament::section :icon="$refused ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-sparkles'">
            <x-slot name="heading">{{ $refused ? 'Refused' : 'Answer' }}</x-slot>
            @if ($refused)
                <x-slot name="description">
                    Nothing in the retrieved passages supported an answer. That is the
                    intended behaviour: a refusal is preferable to a plausible invention.
                </x-slot>
            @endif

            @php
                // Turn "[#1]" into a markdown link to its passage card below --
                // clicking a citation should jump to the source, not just claim one.
                $linkedAnswer = preg_replace('/\[#(\d+)\]/', '[#$1](#fai-passage-$1)', $answer);
            @endphp

            <div class="fai-answer">
                {!! \Illuminate\Support\Str::markdown($linkedAnswer, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
            </div>
        </x-filament::section>
    @endif

    @if ($passages !== [])
        <x-filament::section>
            <x-slot name="heading">Retrieved passages ({{ count($passages) }})</x-slot>
            <x-slot name="description">
                Ordered as they were given to the model. A passage marked "cited" was
                actually referenced in the answer.
            </x-slot>

            <div style="display:flex;flex-direction:column;gap:1rem;">
                @foreach ($passages as $passage)
                    <x-filament-ai::chunk-card :passage="$passage" />
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($diagnostics !== [])
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Diagnostics</x-slot>

            <pre style="overflow-x:auto;font-size:.75rem;">{{ json_encode($diagnostics, JSON_PRETTY_PRINT) }}</pre>
        </x-filament::section>
    @endif

    <style>
        /* Markdown body. Replaces the Typography plugin so that installing this
           package never drags a Tailwind plugin into the host application. */
        .fai-answer { font-size: .875rem; line-height: 1.7; }
        .fai-answer > :first-child { margin-top: 0; }
        .fai-answer > :last-child { margin-bottom: 0; }
        .fai-answer p, .fai-answer ul, .fai-answer ol, .fai-answer pre { margin: .75rem 0; }
        .fai-answer ul, .fai-answer ol { padding-inline-start: 1.25rem; }
        .fai-answer ul { list-style: disc; }
        .fai-answer ol { list-style: decimal; }
        .fai-answer li { margin: .25rem 0; }
        .fai-answer h1, .fai-answer h2, .fai-answer h3 { font-weight: 600; margin: 1rem 0 .5rem; }
        .fai-answer h1 { font-size: 1.125rem; }
        .fai-answer h2 { font-size: 1rem; }
        .fai-answer h3 { font-size: .9375rem; }
        .fai-answer code { font-size: .8125rem; padding: .075rem .25rem; border-radius: .25rem; background: var(--fai-track); }
        .fai-answer pre { padding: .75rem; border-radius: .5rem; overflow-x: auto; background: var(--fai-track); }
        .fai-answer pre code { background: none; padding: 0; }
        .fai-answer blockquote { margin: .75rem 0; padding-inline-start: .75rem; border-inline-start: 3px solid var(--fai-line); color: var(--fai-muted); }
        .fai-answer a { color: var(--primary-600); text-decoration: underline; }

        @keyframes fai-passage-flash {
            0% { box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.9); background-color: rgba(250, 204, 21, 0.15); }
            100% { box-shadow: 0 0 0 0 rgba(250, 204, 21, 0); background-color: transparent; }
        }
        .fai-passage-highlight {
            animation: fai-passage-flash 1.6s ease-out;
        }
    </style>

    <script>
        // Guarded against Livewire re-rendering this script tag on every "Run".
        if (! window.__faiPassageHighlightBound) {
            window.__faiPassageHighlightBound = true;

            document.addEventListener('click', (event) => {
                const link = event.target.closest('a[href^="#fai-passage-"]');

                if (! link) {
                    return;
                }

                const target = document.querySelector(link.getAttribute('href'));

                if (! target) {
                    return;
                }

                event.preventDefault();
                target.scrollIntoView({behavior: 'smooth', block: 'center'});
                target.classList.remove('fai-passage-highlight');
                // Force a reflow so the animation restarts on repeat clicks.
                void target.offsetWidth;
                target.classList.add('fai-passage-highlight');
            });
        }
    </script>
</x-filament-panels::page>
