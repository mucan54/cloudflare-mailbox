@php
    $badge = match ($s->status) {
        'delivered' => ['Teslim edildi', 'success'],
        'queued' => ['Kuyrukta — Cloudflare kabul etti, teslim asenkron', 'info'],
        'bounced' => ['Geri döndü (alıcı reddetti)', 'warning'],
        'failed' => ['Başarısız', 'danger'],
        default => [$s->status, 'gray'],
    };
    $colors = [
        'success' => ['#065f46', '#d1fae5'],
        'info' => ['#1e40af', '#dbeafe'],
        'warning' => ['#92400e', '#fef3c7'],
        'danger' => ['#991b1b', '#fee2e2'],
        'gray' => ['#374151', '#f3f4f6'],
    ];
    [$ink, $bg] = $colors[$badge[1]];
    $body = strip_tags((string) ($s->text_body ?: $s->html_body));
@endphp

<div class="space-y-4 text-sm">
    <div>
        <span style="display:inline-block;padding:4px 12px;border-radius:999px;font-weight:600;color:{{ $ink }};background:{{ $bg }}">
            {{ $badge[0] }}
        </span>
    </div>

    <div class="grid grid-cols-1 gap-2">
        <div><b>Gönderen:</b> <span class="font-mono">{{ $s->from_email }}</span></div>
        <div><b>Kime:</b> <span class="font-mono">{{ collect($s->to)->implode(', ') ?: '—' }}</span></div>
        @if (filled($s->cc))
            <div><b>Cc:</b> <span class="font-mono">{{ collect($s->cc)->implode(', ') }}</span></div>
        @endif
        @if (filled($s->bcc))
            <div><b>Bcc:</b> <span class="font-mono">{{ collect($s->bcc)->implode(', ') }}</span></div>
        @endif
        <div><b>Sürücü:</b> {{ $s->driver }}</div>
        <div><b>Tarih:</b> {{ optional($s->sent_at)->format('d.m.Y H:i:s') ?? '—' }}</div>
    </div>

    @if ($s->error)
        <div class="rounded-lg bg-danger-50 dark:bg-danger-500/10 p-3 text-danger-700 dark:text-danger-400">
            <div class="font-semibold mb-1">Red / hata sebebi</div>
            <div style="white-space:pre-wrap;word-break:break-word">{{ $s->error }}</div>
        </div>
    @endif

    @if ($s->status === 'queued')
        <div class="rounded-lg bg-primary-50 dark:bg-primary-500/10 p-3 text-primary-800 dark:text-primary-300 text-xs">
            “Kuyrukta” = Cloudflare mesajı kabul etti ve gönderiyor. Nihai teslim/red sonucu
            (ör. alıcı sunucu engeli) asenkron gerçekleşir ve Cloudflare panelindeki
            <b>Email Sending → Activity Log</b>’da görünür.
        </div>
    @endif

    @if ($body)
        <div>
            <div class="font-semibold mb-1">İçerik önizleme</div>
            <div class="rounded-lg bg-gray-50 dark:bg-white/5 p-3" style="max-height:160px;overflow:auto;white-space:pre-wrap;word-break:break-word">{{ \Illuminate\Support\Str::limit($body, 1000) }}</div>
        </div>
    @endif

    @if (filled($s->cf_response))
        <div>
            <div class="font-semibold mb-1">Ham Cloudflare yanıtı</div>
            <pre class="rounded-lg bg-gray-900 text-gray-100 p-3 text-xs" style="max-height:220px;overflow:auto">{{ json_encode($s->cf_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif
</div>
