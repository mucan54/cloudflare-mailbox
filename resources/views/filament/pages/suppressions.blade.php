<x-filament-panels::page>
    <div class="space-y-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Cloudflare, kalıcı bounce veya spam şikâyeti alan adresleri otomatik olarak bu listeye ekler ve
            bu adreslere göndermeyi reddeder. Yanlışlıkla eklenen bir adresi buradan kaldırıp tekrar
            gönderim yapabilirsiniz. <b>Not:</b> spam şikâyeti nedeniyle eklenen adreslerin kaldırılması
            Cloudflare tarafından kısıtlanabilir.
        </p>

        @if ($loadError)
            <div class="rounded-xl bg-danger-50 p-4 text-sm text-danger-700 ring-1 ring-inset ring-danger-200 dark:bg-danger-500/10 dark:text-danger-300 dark:ring-danger-500/30">
                <div class="font-medium">Liste alınamadı</div>
                <div class="mt-1 opacity-90">{{ $loadError }}</div>
            </div>
        @elseif (empty($rows))
            <div class="rounded-xl bg-gray-50 p-8 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                Baskılama listesi boş — tüm alıcılara gönderim açık. 🎉
            </div>
        @else
            <div class="overflow-x-auto rounded-xl ring-1 ring-inset ring-gray-200 dark:ring-white/10">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 font-semibold">E-posta</th>
                            <th class="px-4 py-3 font-semibold">Sebep</th>
                            <th class="px-4 py-3 font-semibold">Eklenme</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-4 py-3 font-mono text-gray-800 dark:text-gray-100">{{ $row['email'] }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                                        {{ $row['reason'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $row['created_at'] ?: '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <x-filament::button
                                        size="xs"
                                        color="danger"
                                        icon="heroicon-o-trash"
                                        wire:click="remove(@js($row['key']))"
                                        wire:confirm="{{ $row['email'] }} adresini baskılama listesinden kaldırmak istiyor musunuz?"
                                    >
                                        Kaldır
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
