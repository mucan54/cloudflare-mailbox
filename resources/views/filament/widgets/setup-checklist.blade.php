<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Kurulum</x-slot>
        <x-slot name="description">Mail servisini çalışır hâle getirmek için kalan adımlar.</x-slot>

        <ul class="space-y-3">
            @foreach ($this->getItems() as $item)
                <li class="flex items-start gap-3">
                    @if ($item['done'])
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500 shrink-0" />
                        <span class="text-sm">{{ $item['label'] }}</span>
                    @else
                        <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5 text-gray-400 shrink-0" />
                        <span class="text-sm">
                            {{ $item['label'] }}
                            <span class="text-gray-500">— {{ $item['hint'] }}</span>
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
