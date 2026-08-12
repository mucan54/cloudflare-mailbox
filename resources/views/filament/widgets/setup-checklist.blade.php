<x-filament-widgets::widget>
    <x-filament::section>
        @if ($this->isComplete())
            {{-- Compact success state — no clutter once everything is done. --}}
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-success-50 dark:bg-success-500/10">
                    <x-filament::icon icon="heroicon-s-check-badge" class="h-7 w-7 text-success-500" />
                </div>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ __('You’re all set 🎉') }}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('Your mail service is fully configured and ready.') }}
                    </p>
                </div>
            </div>
        @else
            {{-- Header: progress ring + title --}}
            <div class="flex items-center gap-4">
                <div class="shrink-0 text-gray-950 dark:text-white" style="position:relative;width:56px;height:56px;">
                    <svg width="56" height="56" viewBox="0 0 40 40" style="transform:rotate(-90deg)">
                        <circle cx="20" cy="20" r="15.9155" fill="none" stroke="currentColor"
                                stroke-opacity="0.12" stroke-width="3.5" />
                        <circle cx="20" cy="20" r="15.9155" fill="none" stroke="currentColor"
                                class="text-primary-500" stroke-width="3.5" stroke-linecap="round"
                                stroke-dasharray="100" stroke-dashoffset="{{ 100 - $this->getProgress() }}"
                                style="transition:stroke-dashoffset .5s ease" />
                    </svg>
                    <span style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;"
                          class="text-sm font-bold">{{ $this->getProgress() }}%</span>
                </div>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ __('Finish setting up your mail service') }}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->getDoneCount() }}/{{ $this->getTotalCount() }} {{ __('steps completed') }}
                    </p>
                </div>
            </div>

            {{-- Steps: one clean divided list rather than four heavy cards --}}
            <div class="mt-5 overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                @foreach ($this->getItems() as $index => $item)
                    <div @class([
                        'flex items-center gap-3 p-3',
                        'border-t border-gray-100 dark:border-white/5' => $index > 0,
                        'bg-gray-50/60 dark:bg-white/5' => $item['done'],
                    ])>
                        <div class="shrink-0">
                            @if ($item['done'])
                                <x-filament::icon icon="heroicon-s-check-circle" class="h-6 w-6 text-success-500" />
                            @else
                                <span class="flex h-6 w-6 items-center justify-center rounded-full border border-gray-300 text-xs font-semibold text-gray-400 dark:border-white/20">
                                    {{ $index + 1 }}
                                </span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1">
                            <p @class([
                                'text-sm font-medium',
                                'text-gray-400 line-through dark:text-gray-500' => $item['done'],
                                'text-gray-950 dark:text-white' => ! $item['done'],
                            ])>{{ $item['label'] }}</p>
                            @unless ($item['done'])
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item['hint'] }}</p>
                            @endunless
                        </div>

                        @if (! $item['done'] && $item['url'])
                            <x-filament::button
                                :href="$item['url']"
                                tag="a"
                                size="xs"
                                color="primary"
                                class="shrink-0">
                                {{ __('Go') }}
                            </x-filament::button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
