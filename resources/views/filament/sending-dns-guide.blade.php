<div class="space-y-4 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        Giden e-postanın Outlook/Gmail gibi katı alıcılar tarafından reddedilmemesi için
        aşağıdaki <b>SPF / DKIM / DMARC</b> kayıtlarının <span class="font-mono">{{ $domain }}</span>
        DNS bölgesinde bulunması gerekir. Cloudflare nameserver’larını kullanıyorsanız çoğu otomatik
        eklenir; <b>DMARC’ı elle eklemeniz</b> gerekir.
    </p>

    @if ($error)
        <div class="rounded-lg bg-danger-50 dark:bg-danger-500/10 p-3 text-danger-700 dark:text-danger-400">
            Kayıtlar Cloudflare’den alınamadı: {{ $error }}
        </div>
    @endif

    @if (count($records))
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-white/5 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2">Tür</th>
                        <th class="px-3 py-2">Ad</th>
                        <th class="px-3 py-2">Değer</th>
                        <th class="px-3 py-2">Öncelik</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($records as $r)
                        <tr class="align-top">
                            <td class="px-3 py-2 font-medium">{{ $r['type'] }}</td>
                            <td class="px-3 py-2 font-mono break-all">{{ $r['name'] }}</td>
                            <td class="px-3 py-2 font-mono break-all">{{ $r['value'] }}</td>
                            <td class="px-3 py-2">{{ $r['priority'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif (! $error)
        <div class="rounded-lg bg-warning-50 dark:bg-warning-500/10 p-3 text-warning-700 dark:text-warning-400">
            Cloudflare bu bölge için otomatik e-posta kaydı döndürmedi. SPF/DKIM değerlerini
            Cloudflare panelinde <b>Email → Email Sending</b> kurulumundan kopyalayın.
        </div>
    @endif

    @if ($dmarc)
        <div class="rounded-lg border border-primary-200 dark:border-primary-500/30 bg-primary-50 dark:bg-primary-500/10 p-3 space-y-1">
            <div class="font-semibold text-primary-800 dark:text-primary-300">Önerilen DMARC (eksik — ekleyin)</div>
            <div class="font-mono text-xs break-all">
                <span class="text-gray-500">{{ $dmarc['type'] }}</span>
                &nbsp;{{ $dmarc['name'] }}&nbsp;→&nbsp;{{ $dmarc['value'] }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                İzlemeye hazır olduğunuzda <span class="font-mono">p=none</span> yerine
                <span class="font-mono">p=quarantine</span> ya da <span class="font-mono">p=reject</span> kullanın.
            </div>
        </div>
    @endif

    <div class="text-xs text-gray-500 dark:text-gray-400">
        Not: Kayıtlar doğruysa ve Gmail teslim ediyor ama Outlook hâlâ reddediyorsa, bu yeni
        domain <b>itibarı</b> kaynaklıdır — düşük hacimle ısıtın ve Microsoft SNDS/JMRP’ye kaydolun.
    </div>
</div>
