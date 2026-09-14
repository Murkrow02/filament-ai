<x-filament-panels::page>
    @include('rag::partials.assistant-styles')

    @if (! $installed)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <p class="rag-assistant-muted">{{ __('rag::rag.assistant.not_installed') }}</p>
        </x-filament::section>
    @else
        <div class="rag-assistant">
            <aside class="rag-assistant-history">
                <x-filament::button wire:click="newChat" icon="heroicon-o-plus" color="gray" size="sm">
                    {{ __('rag::rag.assistant.new_chat') }}
                </x-filament::button>

                <p class="rag-assistant-label">{{ __('rag::rag.assistant.history') }}</p>

                @forelse ($history as $thread)
                    <button
                        type="button"
                        wire:key="thread-{{ $thread['id'] }}"
                        wire:click="openChat(@js($thread['id']))"
                        @class(['rag-assistant-thread', 'is-active' => $thread['id'] === $conversationId])
                    >
                        <span class="rag-assistant-thread-title">{{ $thread['title'] }}</span>
                        <span class="rag-assistant-muted">{{ $thread['updated_at'] }}</span>
                    </button>
                @empty
                    <p class="rag-assistant-muted">{{ __('rag::rag.assistant.no_history') }}</p>
                @endforelse
            </aside>

            <section class="rag-assistant-main">
                @if ($contextLabel)
                    <div>
                        <x-filament::badge icon="heroicon-o-eye" color="gray">{{ $contextLabel }}</x-filament::badge>
                    </div>
                @endif

                <div class="rag-assistant-messages">
                    @if ($messages === [] && $pending === [])
                        <div class="rag-assistant-empty">
                            <x-filament::icon icon="heroicon-o-sparkles" class="rag-assistant-empty-icon" />
                            <h2 class="rag-assistant-empty-title">{{ __('rag::rag.assistant.empty_title') }}</h2>
                            <p class="rag-assistant-muted">{{ __('rag::rag.assistant.empty_body') }}</p>
                        </div>
                    @endif

                    @foreach ($messages as $index => $message)
                        <div
                            wire:key="message-{{ $index }}"
                            @class(['rag-assistant-message', 'is-user' => $message['role'] === 'user'])
                        >
                            <p class="rag-assistant-label">
                                {{ $message['role'] === 'user' ? __('rag::rag.assistant.you') : __('rag::rag.assistant.assistant') }}
                            </p>

                            @if ($message['tools'] !== [])
                                <div class="rag-assistant-tools">
                                    @foreach ($message['tools'] as $tool)
                                        <x-filament::badge
                                            size="sm"
                                            icon="heroicon-o-wrench-screwdriver"
                                            :color="match ($tool['status']) { 'denied', 'failed' => 'danger', 'pending' => 'warning', default => 'gray' }"
                                        >
                                            {{ $tool['status'] === 'denied'
                                                ? __('rag::rag.assistant.tool_denied', ['tool' => $tool['name']])
                                                : __('rag::rag.assistant.tool_used', ['tool' => $tool['name']]) }}
                                        </x-filament::badge>
                                    @endforeach
                                </div>
                            @endif

                            @if (trim($message['content']) !== '')
                                <div class="rag-assistant-content">
                                    @if ($message['role'] === 'user')
                                        <p class="rag-assistant-plain">{{ $message['content'] }}</p>
                                    @else
                                        {!! \Illuminate\Support\Str::markdown($message['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @foreach ($pending as $callId => $approval)
                        <x-filament::section
                            wire:key="approval-{{ $callId }}"
                            icon="heroicon-o-shield-exclamation"
                            icon-color="warning"
                            compact
                        >
                            <x-slot name="heading">{{ __('rag::rag.assistant.approval_heading') }}</x-slot>
                            <x-slot name="description">{{ $approval['reason'] ?? $approval['tool'] }}</x-slot>

                            <div class="rag-assistant-actions">
                                @if (array_key_exists($callId, $decisions))
                                    <x-filament::badge :color="$decisions[$callId] ? 'success' : 'danger'">
                                        {{ $decisions[$callId] ? __('rag::rag.assistant.approve') : __('rag::rag.assistant.reject') }}
                                    </x-filament::badge>
                                @else
                                    <x-filament::button
                                        color="success"
                                        icon="heroicon-o-check"
                                        wire:click="decide(@js($callId), true)"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ __('rag::rag.assistant.approve') }}
                                    </x-filament::button>

                                    <x-filament::button
                                        color="danger"
                                        outlined
                                        icon="heroicon-o-x-mark"
                                        wire:click="decide(@js($callId), false)"
                                        wire:loading.attr="disabled"
                                    >
                                        {{ __('rag::rag.assistant.reject') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </x-filament::section>
                    @endforeach

                    @if ($error)
                        <p class="rag-assistant-error">{{ $error }}</p>
                    @endif

                    <div wire:loading.flex wire:target="send, decide" class="rag-assistant-thinking">
                        <x-filament::loading-indicator class="rag-assistant-spinner" />
                        {{ __('rag::rag.assistant.thinking') }}
                    </div>
                </div>

                <form wire:submit="send" class="rag-assistant-composer">
                    <x-filament::input.wrapper class="rag-assistant-input" :disabled="$pending !== []">
                        <x-filament::input
                            type="text"
                            wire:model="prompt"
                            autocomplete="off"
                            :placeholder="__('rag::rag.assistant.placeholder')"
                            :disabled="$pending !== []"
                        />
                    </x-filament::input.wrapper>

                    <x-filament::button
                        type="submit"
                        icon="heroicon-o-paper-airplane"
                        :disabled="$pending !== []"
                        wire:loading.attr="disabled"
                    >
                        {{ __('rag::rag.assistant.send') }}
                    </x-filament::button>
                </form>
            </section>
        </div>
    @endif
</x-filament-panels::page>
