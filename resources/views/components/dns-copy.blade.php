@props(['label', 'value'])

{{-- A labelled, monospaced value with a one-tap "copy to clipboard" button. --}}
<div
    class="dnsg-field"
    x-data="{
        copied: false,
        copy() {
            const done = () => { this.copied = true; setTimeout(() => this.copied = false, 1500); };
            const text = @js($value);
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(() => this.fallback(text, done));
            } else {
                this.fallback(text, done);
            }
        },
        fallback(text, done) {
            const el = document.createElement('textarea');
            el.value = text; el.style.position = 'fixed'; el.style.opacity = '0';
            document.body.appendChild(el); el.focus(); el.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            document.body.removeChild(el);
        },
    }"
>
    <div class="dnsg-field-head">
        <span class="dnsg-label">{{ $label }}</span>
        <button type="button" class="dnsg-copy" :class="copied && 'is-copied'" x-on:click="copy()">
            <template x-if="!copied">
                <span class="dnsg-copy-in">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                    Kopyala
                </span>
            </template>
            <template x-if="copied">
                <span class="dnsg-copy-in">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    Kopyalandı
                </span>
            </template>
        </button>
    </div>
    <code class="dnsg-value">{{ $value }}</code>
</div>
