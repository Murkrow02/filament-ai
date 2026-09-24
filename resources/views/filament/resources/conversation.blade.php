@php
    use Illuminate\Support\Str;

    // The answer is the model's Markdown. Rendered with raw HTML escaped and
    // unsafe links dropped: text written by a model is not trusted markup.
    $markdown = static fn (string $text): string => Str::markdown($text, [
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
    ]);

    $json = static fn (mixed $value): string => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

<x-filament-panels::page>
    <style>
        .fai-audit { display: grid; gap: 1rem; }
        .fai-audit__meta { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; font-size: .875rem; color: var(--gray-500, #6b7280); }
        .fai-audit__meta strong { color: var(--gray-950, #030712); font-weight: 600; }
        .dark .fai-audit__meta strong { color: var(--gray-50, #f9fafb); }
        .fai-audit__turn { border-radius: .75rem; padding: .875rem 1rem; border: 1px solid color-mix(in oklab, var(--gray-950, #000) 10%, transparent); background: var(--gray-50, #f9fafb); }
        .dark .fai-audit__turn { border-color: color-mix(in oklab, #fff 10%, transparent); background: color-mix(in oklab, #fff 3%, transparent); }
        .fai-audit__turn--user { background: color-mix(in oklab, var(--primary-500, #6366f1) 8%, transparent); }
        .fai-audit__turn--failed { border-color: var(--danger-500, #ef4444); }
        .fai-audit__head { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .5rem; font-size: .75rem; color: var(--gray-500, #6b7280); }
        .fai-audit__role { font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
        .fai-audit__body { font-size: .9rem; line-height: 1.6; overflow-wrap: anywhere; }
        .fai-audit__body p { margin: 0 0 .6rem; }
        .fai-audit__body ul { list-style: disc; padding-left: 1.4rem; margin: 0 0 .6rem; }
        .fai-audit__body ol { list-style: decimal; padding-left: 1.4rem; margin: 0 0 .6rem; }
        .fai-audit__body h1, .fai-audit__body h2, .fai-audit__body h3, .fai-audit__body h4 { font-weight: 650; margin: .8rem 0 .4rem; }
        .fai-audit__body table { border-collapse: collapse; margin: 0 0 .6rem; font-size: .85rem; }
        .fai-audit__body th, .fai-audit__body td { border-bottom: 1px solid color-mix(in oklab, var(--gray-500, #6b7280) 30%, transparent); padding: .3rem .6rem; text-align: left; }
        .fai-audit__body code { font-size: .85em; padding: .05rem .3rem; border-radius: .3rem; background: color-mix(in oklab, var(--gray-500, #6b7280) 15%, transparent); }
        .fai-audit__body a { text-decoration: underline; }
        .fai-audit__tool { margin-top: .5rem; font-size: .8rem; border-radius: .5rem; border: 1px solid color-mix(in oklab, var(--gray-500, #6b7280) 25%, transparent); }
        .fai-audit__tool summary { cursor: pointer; padding: .4rem .6rem; display: flex; gap: .5rem; align-items: center; }
        .fai-audit__tool pre { margin: 0; padding: .5rem .6rem; max-height: 22rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-size: .75rem; border-top: 1px solid color-mix(in oklab, var(--gray-500, #6b7280) 25%, transparent); }
        .fai-audit__name { font-family: ui-monospace, monospace; color: var(--gray-500, #6b7280); }
        .fai-audit__error { margin-top: .5rem; font-size: .8rem; color: var(--danger-600, #dc2626); white-space: pre-wrap; }
    </style>

    <div class="fai-audit">
        <div class="fai-audit__meta">
            <span>{{ __('filament-ai::messages.conversations.user') }}: <strong>{{ $participant }}</strong></span>
            <span>{{ __('filament-ai::messages.conversations.started') }}: <strong>{{ $this->getRecord()->created_at?->format('d/m/Y H:i') }}</strong></span>
            <span>{{ __('filament-ai::messages.conversations.messages') }}: <strong>{{ count($trail) }}</strong></span>
            <span>{{ __('filament-ai::messages.conversations.tokens') }}: <strong>{{ number_format($inputTokens) }} / {{ number_format($outputTokens) }}</strong></span>
        </div>

        @forelse ($trail as $message)
            <div @class([
                'fai-audit__turn',
                'fai-audit__turn--user' => $message['role'] === 'user',
                'fai-audit__turn--failed' => $message['status'] === 'failed',
            ])>
                <div class="fai-audit__head">
                    <span class="fai-audit__role">
                        {{ $message['role'] === 'user' ? $participant : __('filament-ai::messages.assistant.assistant') }}
                    </span>
                    <span>
                        @if ($message['input_tokens'] !== null)
                            {{ number_format((int) $message['input_tokens']) }} / {{ number_format((int) $message['output_tokens']) }} token ·
                        @endif
                        @if ($message['status'] && $message['status'] !== 'completed')
                            <x-filament::badge :color="$message['status'] === 'failed' ? 'danger' : 'warning'" size="sm">{{ $message['status'] }}</x-filament::badge>
                        @endif
                        {{ $message['at'] ? \Illuminate\Support\Carbon::parse($message['at'])->timezone(config('app.timezone'))->format('d/m/Y H:i:s') : '' }}
                    </span>
                </div>

                @if (trim($message['content']) !== '')
                    <div class="fai-audit__body">
                        @if ($message['role'] === 'user')
                            <p>{!! nl2br(e($message['content'])) !!}</p>
                        @else
                            {!! $markdown($message['content']) !!}
                        @endif
                    </div>
                @endif

                @foreach ($message['tools'] as $tool)
                    <details class="fai-audit__tool">
                        <summary>
                            <x-filament::badge size="sm" :color="match ($tool['status']) { 'failed' => 'danger', 'denied' => 'warning', 'pending' => 'info', default => 'success' }">{{ $tool['status'] }}</x-filament::badge>
                            <span>{{ $tool['label'] }}</span>
                            <span class="fai-audit__name">{{ $tool['name'] }}</span>
                        </summary>
                        <pre>{{ __('filament-ai::messages.conversations.arguments') }}: {{ $json($tool['arguments']) }}</pre>
                        @if ($tool['result'] !== null)
                            <pre>{{ __('filament-ai::messages.conversations.result') }}: {{ Str::limit($tool['result'], 20000) }}</pre>
                        @endif
                    </details>
                @endforeach

                @if ($message['error'])
                    <div class="fai-audit__error">{{ $message['error'] }}</div>
                @endif
            </div>
        @empty
            <p class="fai-audit__meta">{{ __('filament-ai::messages.conversations.empty') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
