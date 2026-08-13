<x-filament-widgets::widget>
    <x-filament::section>
        {{--
            Self-contained styles. This app has no custom Filament theme build,
            so arbitrary Tailwind utilities in a widget blade are NOT compiled
            into Filament's CSS and render unstyled. Scoped CSS keeps the widget
            looking right regardless, in both light and dark mode.
        --}}
        <style>
            .suc-head { display:flex; align-items:center; gap:1rem; }
            .suc-ring { position:relative; width:56px; height:56px; flex:none; }
            .suc-ring span { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; color:#111827; }
            .suc-title { font-size:1rem; font-weight:600; color:#111827; margin:0; }
            .suc-sub { font-size:.875rem; color:#6b7280; margin:.1rem 0 0; }
            .suc-list { margin-top:1.25rem; border:1px solid #e5e7eb; border-radius:.75rem; overflow:hidden; }
            .suc-row { display:flex; align-items:center; gap:.75rem; padding:.75rem .875rem; }
            .suc-row + .suc-row { border-top:1px solid #f0f0f2; }
            .suc-row.is-done { background:#f9fafb; }
            .suc-badge { flex:none; width:1.5rem; height:1.5rem; border-radius:999px; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:#9ca3af; border:1px solid #d1d5db; }
            .suc-badge.ok { border:none; color:#22c55e; }
            .suc-badge svg { width:1.5rem; height:1.5rem; display:block; }
            .suc-main { min-width:0; flex:1; }
            .suc-label { font-size:.875rem; font-weight:500; color:#111827; margin:0; }
            .suc-row.is-done .suc-label { color:#9ca3af; text-decoration:line-through; }
            .suc-hint { font-size:.75rem; color:#6b7280; margin:.15rem 0 0; }
            .suc-go { flex:none; text-decoration:none; font-size:.8rem; font-weight:600; color:#fff; background:#f59e0b; padding:.3rem .7rem; border-radius:.5rem; white-space:nowrap; }
            .suc-go:hover { background:#d97706; }
            .suc-done-icon { flex:none; width:3rem; height:3rem; border-radius:999px; background:#dcfce7; display:flex; align-items:center; justify-content:center; }
            .suc-done-icon svg { width:1.75rem; height:1.75rem; color:#16a34a; }
            /* Dark mode — Filament toggles a .dark class on <html>; also honor OS pref. */
            .dark .suc-ring span, .dark .suc-title, .dark .suc-label { color:#f9fafb; }
            .dark .suc-sub, .dark .suc-hint { color:#9ca3af; }
            .dark .suc-list { border-color:rgba(255,255,255,.1); }
            .dark .suc-row + .suc-row { border-color:rgba(255,255,255,.06); }
            .dark .suc-row.is-done { background:rgba(255,255,255,.04); }
            .dark .suc-row.is-done .suc-label { color:#6b7280; }
            .dark .suc-badge { border-color:rgba(255,255,255,.2); }
            .dark .suc-done-icon { background:rgba(34,197,94,.15); }
        </style>

        @if ($this->isComplete())
            <div class="suc-head">
                <span class="suc-done-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/></svg>
                </span>
                <div>
                    <h3 class="suc-title">{{ __('You’re all set 🎉') }}</h3>
                    <p class="suc-sub">{{ __('Your mail service is fully configured and ready.') }}</p>
                </div>
            </div>
        @else
            <div class="suc-head">
                <div class="suc-ring">
                    <svg width="56" height="56" viewBox="0 0 40 40" style="transform:rotate(-90deg)">
                        <circle cx="20" cy="20" r="15.9155" fill="none" stroke="currentColor" stroke-opacity="0.14" stroke-width="3.5" />
                        <circle cx="20" cy="20" r="15.9155" fill="none" stroke="#f59e0b" stroke-width="3.5" stroke-linecap="round"
                                stroke-dasharray="100" stroke-dashoffset="{{ 100 - $this->getProgress() }}" />
                    </svg>
                    <span>{{ $this->getProgress() }}%</span>
                </div>
                <div>
                    <h3 class="suc-title">{{ __('Finish setting up your mail service') }}</h3>
                    <p class="suc-sub">{{ $this->getDoneCount() }}/{{ $this->getTotalCount() }} {{ __('steps completed') }}</p>
                </div>
            </div>

            <div class="suc-list">
                @foreach ($this->getItems() as $index => $item)
                    <div class="suc-row {{ $item['done'] ? 'is-done' : '' }}">
                        <span class="suc-badge {{ $item['done'] ? 'ok' : '' }}">
                            @if ($item['done'])
                                <svg viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/></svg>
                            @else
                                {{ $index + 1 }}
                            @endif
                        </span>
                        <div class="suc-main">
                            <p class="suc-label">{{ $item['label'] }}</p>
                            @unless ($item['done'])
                                <p class="suc-hint">{{ $item['hint'] }}</p>
                            @endunless
                        </div>
                        @if (! $item['done'] && $item['url'])
                            <a class="suc-go" href="{{ $item['url'] }}">{{ __('Go') }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
