<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useAuth } from '../stores/auth';
import { initials, avatarColor } from '../avatar';
import { t, localeTag } from '../i18n';

const props = defineProps({
    msg: { type: Object, required: true },
    acc: { type: String, default: '' },
    // Collapsed messages show only a header + snippet until tapped. The newest
    // message in a thread is expanded; a lone message is always expanded.
    expanded: { type: Boolean, default: true },
    collapsible: { type: Boolean, default: false },
});

const emit = defineEmits(['expand']);
const auth = useAuth();
const open = ref(props.expanded);
const bodyEl = ref(null);
const hasQuote = ref(false);

// The display label is "Siz" for our own messages, but the AVATAR must reflect
// the real person (their name/email) — not the word "Siz" (which would render
// as "SI"). Colour is seeded from the email so each person is consistent.
const who = computed(() => props.msg.mine ? t('mail.you') : (props.msg.from_name || props.msg.from_email || ''));
const avatarName = computed(() => props.msg.from_name || props.msg.from_email || '?');
const seed = computed(() => props.msg.from_email || '');
const toLine = computed(() => props.msg.to_email || '');

// Sender/recipient details, toggled by tapping the name — so you can see WHO
// actually sent a message (name + real address), who it went to, and when.
const showDetails = ref(false);
function toggleDetails() { showDetails.value = !showDetails.value; }
const fromFull = computed(() => {
    const e = props.msg.from_email || '';
    const n = props.msg.from_name || '';
    return n && n !== e ? `${n} <${e}>` : (e || n);
});
const ccLine = computed(() => {
    const cc = props.msg.cc;
    return Array.isArray(cc) ? cc.join(', ') : (cc || '');
});

// Follow the parent's expanded intent when this card is (re)assigned a message.
watch(() => props.msg.id, () => { open.value = props.expanded; showDetails.value = false; });

function fmt(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString(localeTag(), { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString(localeTag(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
function fmtFull(iso) {
    return iso ? new Date(iso).toLocaleString(localeTag(), { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
}

function toggleOpen() {
    if (props.collapsible) open.value = !open.value;
}

// --- Attachments ---
async function download(a) {
    try {
        const { data } = await auth.api(props.acc).get(`/attachments/${a.id}/download`, { responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = url;
        link.download = a.filename || 'attachment';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1500);
    } catch (_) { /* ignore */ }
}
async function downloadAll() {
    for (const a of props.msg.attachments || []) await download(a);
}
function fileSize(n) {
    if (!n) return '';
    return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
}
const totalSize = computed(() => (props.msg.attachments || []).reduce((s, a) => s + (a.size || 0), 0));

const EXT_STYLE = {
    xls: ['XLS', '#107c41'], xlsx: ['XLS', '#107c41'], csv: ['CSV', '#107c41'], numbers: ['NUM', '#107c41'],
    doc: ['DOC', '#2b579a'], docx: ['DOC', '#2b579a'], rtf: ['RTF', '#2b579a'], pages: ['PGS', '#2b579a'],
    ppt: ['PPT', '#c43e1c'], pptx: ['PPT', '#c43e1c'], key: ['KEY', '#c43e1c'],
    pdf: ['PDF', '#d13438'],
    zip: ['ZIP', '#8a6d00'], rar: ['RAR', '#8a6d00'], '7z': ['7Z', '#8a6d00'], gz: ['GZ', '#8a6d00'], tar: ['TAR', '#8a6d00'],
    png: ['IMG', '#038387'], jpg: ['IMG', '#038387'], jpeg: ['IMG', '#038387'], gif: ['IMG', '#038387'], webp: ['IMG', '#038387'], heic: ['IMG', '#038387'], svg: ['IMG', '#038387'], bmp: ['IMG', '#038387'],
    mp3: ['AUD', '#8764b8'], wav: ['AUD', '#8764b8'], m4a: ['AUD', '#8764b8'],
    mp4: ['VID', '#8764b8'], mov: ['VID', '#8764b8'], webm: ['VID', '#8764b8'],
    txt: ['TXT', '#605e5c'], md: ['TXT', '#605e5c'], ics: ['ICS', '#0f6cbd'],
};
function extOf(a) {
    const name = a.filename || '';
    const dot = name.lastIndexOf('.');
    if (dot > -1 && dot < name.length - 1) return name.slice(dot + 1).toLowerCase();
    return (a.mime_type || '').split('/')[1]?.toLowerCase() || '';
}
function attBadge(a) {
    const ext = extOf(a);
    return EXT_STYLE[ext]?.[0] || (ext ? ext.slice(0, 4).toUpperCase() : 'DOSYA');
}
function attColor(a) {
    return EXT_STYLE[extOf(a)]?.[1] || '#5b5fc7';
}

// --- Quoted-history collapsing (native "•••") ---
let quoteNodes = [];
function isForwardBoundary(node) {
    if (node.nodeType !== 1) return false;
    const el = node;
    if (el.tagName === 'BLOCKQUOTE') return true;
    const cls = el.className && typeof el.className === 'string' ? el.className : '';
    const id = el.id || '';
    if (/gmail_quote|gmail_extra|yahoo_quoted|moz-cite|OutlookMessageHeader/i.test(cls)) return true;
    if (/^divRplyFwdMsg/i.test(id)) return true;
    return false;
}
function collapseHtmlQuote() {
    quoteNodes = [];
    hasQuote.value = false;
    const root = bodyEl.value;
    if (!root) return;
    let boundary = null;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    while (walker.nextNode()) {
        if (isForwardBoundary(walker.currentNode)) { boundary = walker.currentNode; break; }
    }
    if (!boundary) return;
    let node = boundary;
    while (node && node !== root) {
        quoteNodes.push(node);
        let sib = node.nextSibling;
        while (sib) { if (sib.nodeType === 1 || sib.nodeType === 3) quoteNodes.push(sib); sib = sib.nextSibling; }
        node = node.parentNode;
    }
    if (!quoteNodes.length) return;
    hasQuote.value = true;
    // The card always shows only the lead; the full body (incl. quotes) opens
    // in a modal via the "•••" button. So the quote stays hidden here.
    for (const n of quoteNodes) {
        if (n.nodeType === 1) n.style.display = 'none';
        else if (n.nodeType === 3 && !n.__wrap) {
            const span = document.createElement('span');
            span.style.display = 'none';
            n.parentNode?.insertBefore(span, n);
            span.appendChild(n);
            n.__wrap = span;
        }
    }
}

const textSplit = computed(() => {
    const body = props.msg.text_body || '';
    if (!body) return { lead: '', quote: '' };
    const markers = [
        /^-{2,} ?(original message|orijinal ileti|forwarded message|iletilen ileti|weitergeleitete nachricht)/im,
        /^_{10,}\s*$/m,
        /^\s*(on|le|el)\b.{0,120}\b(wrote|schrieb|a écrit)\s*:\s*$/im,
        /^.{0,120}\b(tarihinde).{0,80}\b(yazdı)\s*:?\s*$/im,
        /^\s*>{1,}/m,
    ];
    let idx = -1;
    for (const re of markers) {
        const m = body.match(re);
        if (m && (idx === -1 || m.index < idx)) idx = m.index;
    }
    if (idx <= 0) return { lead: body, quote: '' };
    return { lead: body.slice(0, idx).replace(/\s+$/, ''), quote: body.slice(idx) };
});
const textHasQuote = computed(() => !!textSplit.value.quote);

// Re-run HTML quote detection whenever this card becomes open with an HTML body.
watch([open, () => props.msg.id], async () => {
    if (open.value && props.msg.html_body) {
        await nextTick();
        collapseHtmlQuote();
    }
}, { immediate: true });

defineExpose({ open });
</script>

<template>
    <div class="msg" :class="{ open, collapsible }">
        <div class="msg-head" :class="{ tappable: collapsible }" @click="toggleOpen">
            <span class="ava" :style="{ background: avatarColor(seed) }">{{ initials(avatarName) }}</span>
            <span class="msg-hmeta">
                <span class="msg-line1">
                    <button type="button" class="msg-who" :class="{ open: showDetails }" :title="t('mail.senderDetails')" @click.stop="toggleDetails">
                        <span class="msg-who-name">{{ who }}</span>
                        <svg class="msg-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <span class="msg-time">{{ fmt(msg.received_at) }}</span>
                </span>
                <span v-if="!open" class="msg-snip">{{ msg.snippet }}</span>
                <span v-else-if="!showDetails" class="msg-to">{{ t('mail.toShort') }}: {{ toLine }}</span>
            </span>
        </div>

        <!-- Sender/recipient details, revealed by tapping the name. -->
        <div v-if="open && showDetails" class="msg-details">
            <div class="d-row"><span class="d-k">{{ t('mail.from') }}</span><span class="d-v">{{ fromFull }}</span></div>
            <div class="d-row"><span class="d-k">{{ t('mail.toShort') }}</span><span class="d-v">{{ toLine }}</span></div>
            <div v-if="ccLine" class="d-row"><span class="d-k">Cc</span><span class="d-v">{{ ccLine }}</span></div>
            <div class="d-row"><span class="d-k">{{ t('mail.date') }}</span><span class="d-v">{{ fmtFull(msg.received_at) }}</span></div>
        </div>

        <div v-show="open" class="msg-body-wrap">
            <div v-if="msg.attachments?.length" class="rd-atts">
                <div class="rd-atts-head">
                    <span class="rd-atts-sum">
                        <svg viewBox="0 0 24 24" class="rd-atts-clip"><path d="M21 12.5 12 21a5 5 0 0 1-7-7l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7L9 16.4a1.6 1.6 0 0 1-2.3-2.3l7.8-7.8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ msg.attachments.length }} {{ t('mail.attachments').toLowerCase() }} · {{ fileSize(totalSize) }}
                    </span>
                    <button type="button" class="rd-atts-all" @click="downloadAll">
                        <svg viewBox="0 0 24 24"><path d="M12 3v11m0 0 4-4m-4 4-4-4M5 20h14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ t('mail.saveAll') }}
                    </button>
                </div>
                <div class="rd-atts-row">
                    <button v-for="a in msg.attachments" :key="a.id" type="button" class="att-card" @click="download(a)">
                        <span class="att-badge" :style="{ background: attColor(a) }">{{ attBadge(a) }}</span>
                        <span class="att-info">
                            <span class="att-name">{{ a.filename }}</span>
                            <span class="att-meta">{{ fileSize(a.size) }}</span>
                        </span>
                    </button>
                </div>
            </div>

            <div v-if="msg.html_body" ref="bodyEl" class="rd-body" v-html="msg.html_body" />
            <div v-else class="rd-body text">{{ textSplit.lead }}</div>

            <button v-if="(msg.html_body && hasQuote) || textHasQuote" type="button" class="quote-toggle" @click="emit('expand', msg)" :title="t('mail.quotedText')">
                <span class="quote-dots">•••</span>
            </button>
        </div>
    </div>
</template>
