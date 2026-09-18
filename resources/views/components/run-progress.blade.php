@include('filament-ai::partials.styles')

@props(['run'])

@php
    $percent = $run->progressPercent();
@endphp

<div style="width:10rem;">
    <div style="display:flex;justify-content:space-between;font-size:.75rem;font-variant-numeric:tabular-nums;">
        <span>{{ $percent }}%</span>
        <span style="color:var(--fai-muted);">{{ $run->status->label() }}</span>
    </div>

    <div style="margin-top:.25rem;height:.375rem;border-radius:.25rem;background:var(--fai-line);">
        <div style="height:.375rem;border-radius:.25rem;background:var(--{{ $run->status->color() }}-500);width:{{ max(2, $percent) }}%"></div>
    </div>
</div>
