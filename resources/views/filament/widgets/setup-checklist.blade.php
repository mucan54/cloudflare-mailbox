<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $this->isComplete() ? __('You’re all set 🎉') : __('Finish setting up your mail service') }}
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->getDoneCount() }}/{{ $this->getTotalCount() }} {{ __('steps completed') }}
                </p>
            </div>
            <span @class([
                'text-sm font-semibold',
                'text-success-600 dark:text-success-400' => $this->isComplete(),
                'text-primary-600 dark:text-primary-400' => ! $this->isComplete(),
            ])>{{ $this->getProgress() }}%</span>
        </div>

        {{-- progress bar --}}
        <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
            <div @class([
                    'h-full rounded-full transition-all',
                    'bg-success-500' => $this->isComplete(),
                    'bg-primary-500' => ! $this->isComplete(),
                ])
                style="width: {{ $this->getProgress() }}%"></div>
        </div>

        {{-- steps --}}
        <ul role="list" class="mt-4 space-y-2">
            @foreach ($this->getItems() as $index => $item)
                <li>
                    <div @class([
                        'flex items-start gap-3 rounded-xl border p-3 transition',
                        'border-success-200 bg-success-50/50 dark:border-success-500/20 dark:bg-success-500/5' => $item['done'],
                        'border-gray-200 bg-white hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10' => ! $item['done'],
                    ])>
                        <div class="mt-0.5 shrink-0">
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
                                'text-gray-500 line-through dark:text-gray-500' => $item['done'],
                                'text-gray-950 dark:text-white' => ! $item['done'],
                            ])>{{ $item['label'] }}</p>
                            @unless ($item['done'])
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $item['hint'] }}</p>
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
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
