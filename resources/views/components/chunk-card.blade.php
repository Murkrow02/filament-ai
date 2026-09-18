@include('filament-ai::partials.styles')

@props(['passage'])

@php
    $score = (float) ($passage['score'] ?? 0);
    // Scores below ~0.3 are usually noise; the bar makes that visible at a glance.
    $width = max(2, min(100, (int) round($score * 100)));
    // Resolved into a CSS variable, not a class name: `bg-{$tone}-500` could never
    // work, because Tailwind cannot see a class that is assembled at runtime.
    $tone = $score >= 0.6 ? 'success' : ($score >= 0.35 ? 'warning' : 'danger');
    $used = (bool) ($passage['used'] ?? false);
@endphp

<div id="fai-passage-{{ $passage['marker'] }}"
     style="scroll-margin-top:6rem;border:1px solid var(--fai-line);border-radius:.5rem;padding:1rem;">
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;">
        <x-filament::badge :color="$used ? 'success' : 'gray'">
            [#{{ $passage['marker'] }}]{{ $used ? ' cited' : '' }}
        </x-filament::badge>

        <span style="font-size:.875rem;font-weight:500;">{{ $passage['label'] }}</span>

        @if (! empty($passage['url']))
            <x-filament::link :href="$passage['url']" target="_blank" rel="noopener"
                              icon="heroicon-o-arrow-top-right-on-square" size="sm">
                <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);">Open source</span>
            </x-filament::link>
        @endif

        <span style="margin-inline-start:auto;font-size:.75rem;font-variant-numeric:tabular-nums;color:var(--fai-muted);">
            {{ number_format($score, 3) }}
        </span>
    </div>

    <div style="margin-top:.5rem;height:.25rem;border-radius:.25rem;background:var(--fai-line);">
        <div style="height:.25rem;border-radius:.25rem;background:var(--{{ $tone }}-500);width:{{ $width }}%"></div>
    </div>

    <p style="margin-top:.75rem;font-size:.875rem;line-height:1.6;">{{ $passage['content'] }}</p>
</div>
