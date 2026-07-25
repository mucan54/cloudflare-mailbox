@php
    // Type badge modifier class — coloured so the record kind is scannable.
    $badgeClass = fn (string $type): string => 'dnsg-badge-'.(in_array($type, ['MX', 'TXT', 'CNAME'], true) ? strtolower($type) : 'other');
@endphp

<style>
    .dnsg { display: flex; flex-direction: column; gap: 1.15rem; color: #374151; font-size: 0.875rem; }
    .dark .dnsg { color: #d1d5db; }
    .dnsg-intro { line-height: 1.6; }
    .dnsg-intro b { color: #111827; }
    .dark .dnsg-intro b { color: #f3f4f6; }
    .dnsg-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 600; color: #111827; }
    .dark .dnsg-mono { color: #f3f4f6; }

    .dnsg-section-label { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #9ca3af; margin-bottom: 0.55rem; }

    /* Cards */
    .dnsg-card { border: 1px solid #e5e7eb; background: #fff; border-radius: 0.85rem; padding: 1rem; box-shadow: 0 1px 2px rgba(16,24,40,0.04); }
    .dark .dnsg-card { border-color: rgba(255,255,255,0.10); background: rgba(255,255,255,0.03); box-shadow: none; }
    .dnsg-card + .dnsg-card { margin-top: 0.7rem; }

    .dnsg-card-suggest { border-color: rgba(59,130,246,0.35); background: rgba(59,130,246,0.06); }
    .dark .dnsg-card-suggest { border-color: rgba(96,165,250,0.35); background: rgba(59,130,246,0.10); }

    .dnsg-card-head { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.85rem; }
    .dnsg-suggest-title { font-weight: 600; color: #1d4ed8; }
    .dark .dnsg-suggest-title { color: #93c5fd; }

    /* Badges */
    .dnsg-badge { display: inline-flex; align-items: center; border-radius: 0.4rem; padding: 0.1rem 0.45rem; font-size: 0.72rem; font-weight: 700; line-height: 1.3; border: 1px solid transparent; }
    .dnsg-badge-mx    { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
    .dnsg-badge-txt   { background: #faf5ff; color: #7e22ce; border-color: #e9d5ff; }
    .dnsg-badge-cname { background: #fffbeb; color: #b45309; border-color: #fde68a; }
    .dnsg-badge-other { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }
    .dark .dnsg-badge-mx    { background: rgba(59,130,246,0.12); color: #93c5fd; border-color: rgba(59,130,246,0.30); }
    .dark .dnsg-badge-txt   { background: rgba(168,85,247,0.12); color: #d8b4fe; border-color: rgba(168,85,247,0.30); }
    .dark .dnsg-badge-cname { background: rgba(245,158,11,0.12); color: #fcd34d; border-color: rgba(245,158,11,0.30); }
    .dark .dnsg-badge-other { background: rgba(255,255,255,0.08); color: #d1d5db; border-color: rgba(255,255,255,0.15); }

    .dnsg-pill { border-radius: 0.4rem; padding: 0.1rem 0.45rem; font-size: 0.72rem; font-weight: 600; background: #f3f4f6; color: #4b5563; }
    .dark .dnsg-pill { background: rgba(255,255,255,0.08); color: #d1d5db; }
    .dnsg-tag { border-radius: 999px; padding: 0.1rem 0.5rem; font-size: 0.68rem; font-weight: 700; background: #dbeafe; color: #1d4ed8; }
    .dark .dnsg-tag { background: rgba(59,130,246,0.20); color: #93c5fd; }

    /* Field grid */
    .dnsg-grid { display: grid; gap: 0.75rem; }
    @media (min-width: 640px) { .dnsg-grid { grid-template-columns: minmax(0,1fr) minmax(0,2fr); } }

    .dnsg-field-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.3rem; }
    .dnsg-label { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #9ca3af; }
    .dark .dnsg-label { color: #6b7280; }

    .dnsg-copy { display: inline-flex; align-items: center; flex-shrink: 0; gap: 0.25rem; border: 0; cursor: pointer; border-radius: 0.4rem; padding: 0.2rem 0.45rem; font-size: 0.75rem; font-weight: 500; color: #6b7280; background: transparent; transition: background .12s, color .12s; }
    .dnsg-copy:hover { background: #f3f4f6; color: #374151; }
    .dark .dnsg-copy { color: #9ca3af; }
    .dark .dnsg-copy:hover { background: rgba(255,255,255,0.08); color: #e5e7eb; }
    .dnsg-copy.is-copied { color: #16a34a; }
    .dark .dnsg-copy.is-copied { color: #4ade80; }
    .dnsg-copy-in { display: inline-flex; align-items: center; gap: 0.25rem; }
    .dnsg-copy svg { width: 0.9rem; height: 0.9rem; }

    .dnsg-value { display: block; word-break: break-all; user-select: all; border-radius: 0.5rem; background: #f9fafb; padding: 0.5rem 0.7rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem; line-height: 1.55; color: #1f2937; border: 1px solid #e5e7eb; }
    .dark .dnsg-value { background: rgba(255,255,255,0.05); color: #f3f4f6; border-color: rgba(255,255,255,0.10); }

    .dnsg-note { border-radius: 0.6rem; padding: 0.7rem 0.85rem; font-size: 0.75rem; line-height: 1.6; }
    .dnsg-hint { color: #6b7280; margin-top: 0.7rem; }
    .dark .dnsg-hint { color: #9ca3af; }
    .dnsg-hint code, .dnsg-note code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    .dnsg-note-info { background: #f9fafb; color: #6b7280; }
    .dark .dnsg-note-info { background: rgba(255,255,255,0.05); color: #9ca3af; }
    .dnsg-note-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
    .dark .dnsg-note-danger { background: rgba(239,68,68,0.10); color: #fca5a5; border-color: rgba(239,68,68,0.30); }
    .dnsg-note-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .dark .dnsg-note-warning { background: rgba(245,158,11,0.10); color: #fcd34d; border-color: rgba(245,158,11,0.30); }
    .dnsg-note-title { font-weight: 600; }
    .dnsg-note-sub { margin-top: 0.35rem; opacity: 0.9; }

    /* Auth status summary */
    .dnsg-status { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .dnsg-chk { display: inline-flex; align-items: center; gap: 0.4rem; border-radius: 0.6rem; padding: 0.4rem 0.7rem; font-size: 0.8rem; font-weight: 600; border: 1px solid transparent; }
    .dnsg-chk-ico { display: inline-grid; place-items: center; width: 1.15rem; height: 1.15rem; border-radius: 999px; font-size: 0.75rem; font-weight: 800; color: #fff; }
    .dnsg-chk-pass { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
    .dnsg-chk-pass .dnsg-chk-ico { background: #16a34a; }
    .dnsg-chk-fail { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
    .dnsg-chk-fail .dnsg-chk-ico { background: #dc2626; }
    .dark .dnsg-chk-pass { background: rgba(34,197,94,0.12); color: #86efac; border-color: rgba(34,197,94,0.30); }
    .dark .dnsg-chk-fail { background: rgba(239,68,68,0.12); color: #fca5a5; border-color: rgba(239,68,68,0.30); }
    .dnsg-chk-sub { font-weight: 500; opacity: 0.75; }
</style>

<div class="dnsg">
    <p class="dnsg-intro">
        Giden e-postanın Outlook/Gmail gibi katı alıcılar tarafından reddedilmemesi için aşağıdaki
        <b>SPF / DKIM / DMARC</b> kayıtlarının
        <span class="dnsg-mono">{{ $domain }}</span>
        DNS bölgesinde bulunması gerekir. Cloudflare nameserver’larını kullanıyorsanız çoğu otomatik
        eklenir; <b>DMARC’ı elle eklemeniz</b> gerekir.
    </p>

    {{-- Sender-authentication check: SPF / DKIM / DMARC pass/fail at a glance --}}
    @if ($auth_status)
        <div>
            <div class="dnsg-section-label">Kimlik doğrulama kontrolü</div>
            <div class="dnsg-status">
                @foreach (['spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $k => $labelText)
                    <span class="dnsg-chk {{ $auth_status[$k] ? 'dnsg-chk-pass' : 'dnsg-chk-fail' }}">
                        <span class="dnsg-chk-ico">{{ $auth_status[$k] ? '✓' : '!' }}</span>
                        {{ $labelText }}
                        <span class="dnsg-chk-sub">{{ $auth_status[$k] ? 'var' : 'eksik' }}</span>
                    </span>
                @endforeach
            </div>
            @unless ($auth_status['dkim'])
                <div class="dnsg-hint">
                    <b>DKIM eksik</b> — Outlook/Gmail gönderen kimliğini doğrulayamadığında “doğrulanmamış
                    gönderen” uyarısı verir. Cloudflare panelinde <b>Email → Email Sending → Onboard/DNS</b>
                    adımındaki <code>_domainkey</code> kaydını bu bölgeye ekleyin.
                </div>
            @endunless
        </div>
    @endif

    @if ($error)
        <div class="dnsg-note dnsg-note-danger">
            <div class="dnsg-note-title">Kayıtlar Cloudflare’den alınamadı</div>
            <div class="dnsg-note-sub">{{ $error }}</div>
            @if ($auth_error)
                <div class="dnsg-note-sub">
                    Bu bir <b>izin eksikliği</b>: token’ınızda <b>Zone · Email Routing Rules · Read/Edit</b> yok.
                    Ayarlar → “Cloudflare’de yeni token oluştur” ile bu izni ekleyin. Aşağıdaki önerilen
                    değerleri yine de elle ekleyebilirsiniz.
                </div>
            @endif
        </div>
    @endif

    {{-- Live records returned by Cloudflare --}}
    @if (count($records))
        <div>
            <div class="dnsg-section-label">Mevcut kayıtlar</div>
            @foreach ($records as $r)
                <div class="dnsg-card">
                    <div class="dnsg-card-head">
                        <span class="dnsg-badge {{ $badgeClass($r['type']) }}">{{ $r['type'] }}</span>
                        @if (! is_null($r['priority']) && $r['priority'] !== '')
                            <span class="dnsg-pill">Öncelik {{ $r['priority'] }}</span>
                        @endif
                    </div>
                    <div class="dnsg-grid">
                        <x-dns-copy label="Ad" :value="$r['name']" />
                        <x-dns-copy label="Değer" :value="$r['value']" />
                    </div>
                </div>
            @endforeach
        </div>
    @elseif (! $error)
        <div class="dnsg-note dnsg-note-warning">
            Cloudflare bu bölge için otomatik e-posta kaydı döndürmedi. Aşağıdaki önerilen SPF/DMARC’ı
            ekleyin; <b>DKIM</b> değerini Cloudflare panelinde <b>Email → Email Sending</b> kurulumundan kopyalayın.
        </div>
    @endif

    {{-- Suggested SPF (only when missing) --}}
    @if ($spf)
        <div class="dnsg-card dnsg-card-suggest">
            <div class="dnsg-card-head">
                <span class="dnsg-badge {{ $badgeClass($spf['type']) }}">{{ $spf['type'] }}</span>
                <span class="dnsg-suggest-title">Önerilen SPF</span>
                <span class="dnsg-tag">eksik — ekleyin</span>
            </div>
            <div class="dnsg-grid">
                <x-dns-copy label="Ad" :value="$spf['name']" />
                <x-dns-copy label="Değer" :value="$spf['value']" />
            </div>
            <div class="dnsg-hint">
                Bölgede zaten bir SPF (TXT) kaydı varsa yenisini eklemeyin; mevcut kayda
                <code>include:_spf.mx.cloudflare.net</code> ekleyin (alan başına tek SPF).
            </div>
        </div>
    @endif

    {{-- Suggested DMARC (only when missing) --}}
    @if ($dmarc)
        <div class="dnsg-card dnsg-card-suggest">
            <div class="dnsg-card-head">
                <span class="dnsg-badge {{ $badgeClass($dmarc['type']) }}">{{ $dmarc['type'] }}</span>
                <span class="dnsg-suggest-title">Önerilen DMARC</span>
                <span class="dnsg-tag">eksik — ekleyin</span>
            </div>
            <div class="dnsg-grid">
                <x-dns-copy label="Ad" :value="$dmarc['name']" />
                <x-dns-copy label="Değer" :value="$dmarc['value']" />
            </div>
            <div class="dnsg-hint">
                İzlemeye hazır olduğunuzda <code>p=none</code> yerine
                <code>p=quarantine</code> ya da <code>p=reject</code> kullanın.
            </div>
        </div>
    @endif

    <div class="dnsg-note dnsg-note-info">
        <b>Not:</b> Kayıtlar doğruysa ve Gmail teslim ediyor ama Outlook hâlâ reddediyorsa, bu yeni
        domain <b>itibarı</b> kaynaklıdır — düşük hacimle ısıtın ve Microsoft SNDS/JMRP’ye kaydolun.
    </div>
</div>
