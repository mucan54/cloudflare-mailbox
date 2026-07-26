<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { initials, avatarColor } from '../avatar';
import { t, localeTag } from '../i18n';

const props = defineProps({
    id: { type: [String, Number], required: true },
    acc: { type: String, default: '' },
    type: { type: String, default: 'received' },
    embedded: { type: Boolean, default: false },
});
const emit = defineEmits(['changed', 'close']);

const router = useRouter();
const auth = useAuth();
const ui = useUi();

const isSent = computed(() => props.type === 'sent');
const account = computed(() => props.acc || auth.current?.email);
const email = ref(null);
const loading = ref(true);
const bodyEl = ref(null);
const hasQuote = ref(false);
const showQuote = ref(false);

async function load(retry = 1) {
    loading.value = true;
    email.value = null;
    const path = isSent.value ? `/sent/${props.id}` : `/emails/${props.id}`;
    try {
        const { data } = await auth.api(account.value).get(path);
        email.value = data.email;
        hasQuote.value = false;
        showQuote.value = false;
        if (email.value?.html_body) {
            await nextTick();
            collapseHtmlQuote();
        }
        if (!isSent.value) auth.refreshUnread();
    } catch (e) {
        // Opened straight from a notification the message may not be readable
        // for a beat (session/replication race) — retry once before giving up.
        if (retry > 0 && e.response?.status !== 403) {
            await new Promise((r) => setTimeout(r, 600));
            return load(retry - 1);
        }
        email.value = null;
    } finally {
        loading.value = false;
    }
}
watch(() => [props.id, props.acc, props.type], () => load(), { immediate: true });

// Fetch the attachment through the authenticated API as a blob so downloads
// work on any storage disk (not just those that mint signed URLs).
async function download(a) {
    try {
        const { data } = await auth.api(account.value).get(`/attachments/${a.id}/download`, { responseType: 'blob' });
        const url = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = url;
        link.download = a.filename || 'attachment';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1500);
    } catch (_) {
        // ignore — download failed
    }
}

async function downloadAll() {
    for (const a of email.value?.attachments || []) {
        await download(a);
    }
}

// --- Quoted-history collapsing (native "•••" behaviour) ------------------

// The nodes we've hidden, so the toggle can show/hide them again.
let quoteNodes = [];

function isForwardBoundary(node) {
    if (node.nodeType !== 1) return false;
    const el = /** @type {HTMLElement} */ (node);
    const tag = el.tagName;
    if (tag === 'BLOCKQUOTE') return true;
    const cls = el.className && typeof el.className === 'string' ? el.className : '';
    const id = el.id || '';
    if (/gmail_quote|gmail_extra|yahoo_quoted|moz-cite|OutlookMessageHeader/i.test(cls)) return true;
    if (/^divRplyFwdMsg/i.test(id)) return true;
    return false;
}

function collapseHtmlQuote() {
    quoteNodes = [];
    const root = bodyEl.value;
    if (!root) return;

    // Find the first boundary anywhere in the tree, in document order.
    let boundary = null;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
    while (walker.nextNode()) {
        if (isForwardBoundary(walker.currentNode)) { boundary = walker.currentNode; break; }
    }
    if (!boundary) return;

    // Hide the boundary and everything visually after it: walk up to the body
    // root, hiding the node itself and each of its following siblings.
    let node = boundary;
    while (node && node !== root) {
        quoteNodes.push(node);
        let sib = node.nextSibling;
        while (sib) { if (sib.nodeType === 1 || sib.nodeType === 3) quoteNodes.push(sib); sib = sib.nextSibling; }
        node = node.parentNode;
    }
    if (!quoteNodes.length) return;

    hasQuote.value = true;
    applyQuoteVisibility();
}

function applyQuoteVisibility() {
    for (const n of quoteNodes) {
        if (n.nodeType === 1) n.style.display = showQuote.value ? '' : 'none';
        else if (n.nodeType === 3) {
            // Wrap bare text nodes so they can be toggled.
            if (!n.__wrap) {
                const span = document.createElement('span');
                n.parentNode?.insertBefore(span, n);
                span.appendChild(n);
                n.__wrap = span;
            }
            n.__wrap.style.display = showQuote.value ? '' : 'none';
        }
    }
}

function toggleQuote() {
    showQuote.value = !showQuote.value;
    if (email.value?.html_body) applyQuoteVisibility();
}

// Plain-text emails: split off the quoted original at the first marker.
const textSplit = computed(() => {
    const body = email.value?.text_body || '';
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
const totalSize = computed(() => (email.value?.attachments || []).reduce((s, a) => s + (a.size || 0), 0));

function reply(mode) {
    router.push({ path: '/compose', query: { mode, src: props.id, acc: account.value, type: props.type } });
}
async function toggleStar() {
    email.value.starred = !email.value.starred;
    await auth.api(account.value).patch(`/emails/${props.id}`, { starred: email.value.starred }).catch(() => {});
    emit('changed');
}
async function markUnread() {
    await auth.api(account.value).patch(`/emails/${props.id}`, { read: false }).catch(() => {});
    auth.refreshUnread();
    emit('changed');
    emit('close');
}
async function trash() {
    const from = email.value?.folder || 'inbox';
    await auth.api(account.value).patch(`/emails/${props.id}`, { folder: 'trash' }).catch(() => {});
    auth.refreshUnread();
    emit('changed');
    emit('close');
    ui.toast(t('mail.movedToTrash'), async () => {
        await auth.api(account.value).patch(`/emails/${props.id}`, { folder: from }).catch(() => {});
        auth.refreshUnread();
        emit('changed');
    });
}

function fmt(iso) {
    return iso ? new Date(iso).toLocaleString(localeTag(), { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
}
function fileSize(n) {
    if (!n) return '';
    return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
}
const peer = computed(() => (isSent.value ? email.value?.to_email : (email.value?.from_name || email.value?.from_email)) || '');
const peerEmail = computed(() => (isSent.value ? email.value?.to_email : email.value?.from_email) || '');

defineExpose({ reply, toggleStar, trash, markUnread });
</script>

<template>
    <div class="rd">
        <header class="rd-bar">
            <button class="ghost-ic" :title="embedded ? t('common.close') : t('common.back')" @click="emit('close')">
                <svg v-if="!embedded" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <svg v-else viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>

            <div v-if="email && !isSent" class="rd-acts">
                <button class="tbtn" @click="reply('reply')"><svg viewBox="0 0 24 24"><path d="M9 14 4 9l5-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 9h9a7 7 0 0 1 7 7v3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="tlbl">{{ t('mail.reply') }}</span></button>
                <button class="tbtn" @click="reply('replyAll')"><svg viewBox="0 0 24 24"><path d="M7 14 2 9l5-5M12 14 7 9l5-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 9h8a6 6 0 0 1 6 6v3" fill="none" stroke="currentColor" stroke-width="1.7"/></svg><span class="tlbl">{{ t('mail.replyAll') }}</span></button>
                <button class="tbtn" @click="reply('forward')"><svg viewBox="0 0 24 24"><path d="M15 14l5-5-5-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 9h-9a7 7 0 0 0-7 7v3" fill="none" stroke="currentColor" stroke-width="1.7"/></svg><span class="tlbl">{{ t('mail.forward') }}</span></button>
                <span class="tsep" />
                <button class="tbtn icon" :class="{ starred: email.starred }" :title="t('mail.star')" @click="toggleStar">{{ email.starred ? '★' : '☆' }}</button>
                <button class="tbtn icon" :title="t('mail.markUnread')" @click="markUnread"><svg viewBox="0 0 24 24"><path d="M2 8l10 6 10-6" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="2" y="5" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></button>
                <button class="tbtn icon" :title="t('common.delete')" @click="trash"><svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V5h6v2M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </div>
            <h1 v-else class="rd-title">{{ isSent ? t('mail.sentItem') : t('mail.message') }}</h1>
        </header>

        <div v-if="loading" class="mb-empty">{{ t('common.loading') }}</div>
        <div v-else-if="!email" class="mb-empty">{{ t('mail.notFound') }}</div>

        <div v-else class="rd-scroll">
            <h2 class="rd-subject">{{ email.subject || t('mail.noSubject') }}</h2>
            <div class="rd-sender">
                <span class="ava ava-lg" :style="{ background: avatarColor(peerEmail) }">{{ initials(peer) }}</span>
                <div class="rd-sender-meta">
                    <div class="rd-name">{{ peer }}</div>
                    <div class="rd-email">{{ peerEmail }}</div>
                </div>
                <div class="rd-when">{{ fmt(email.received_at) }}</div>
            </div>

            <div v-if="!isSent && email.to_email" class="rd-to">
                <span class="rd-to-lbl">{{ t('mail.toShort') }}</span>
                <span class="rd-to-val">{{ email.to_email }}</span>
                <span v-if="email.cc?.length" class="rd-to-cc">· Cc {{ email.cc.join(', ') }}</span>
            </div>

            <div v-if="email.attachments?.length" class="rd-atts">
                <div class="rd-atts-head">
                    <span class="rd-atts-sum">
                        <svg viewBox="0 0 24 24" class="rd-atts-clip"><path d="M21 12.5 12 21a5 5 0 0 1-7-7l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7L9 16.4a1.6 1.6 0 0 1-2.3-2.3l7.8-7.8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ email.attachments.length }} {{ t('mail.attachments').toLowerCase() }} · {{ fileSize(totalSize) }}
                    </span>
                    <button type="button" class="rd-atts-all" @click="downloadAll">
                        <svg viewBox="0 0 24 24"><path d="M12 3v11m0 0 4-4m-4 4-4-4M5 20h14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        {{ t('mail.saveAll') }}
                    </button>
                </div>
                <div class="rd-atts-row">
                    <button v-for="a in email.attachments" :key="a.id" type="button" class="att-card" @click="download(a)">
                        <span class="att-badge" :style="{ background: attColor(a) }">{{ attBadge(a) }}</span>
                        <span class="att-info">
                            <span class="att-name">{{ a.filename }}</span>
                            <span class="att-meta">{{ fileSize(a.size) }}</span>
                        </span>
                    </button>
                </div>
            </div>

            <div v-if="email.html_body" ref="bodyEl" class="rd-body" v-html="email.html_body" />
            <div v-else class="rd-body text">{{ textSplit.lead }}<template v-if="textHasQuote"><span v-show="showQuote">{{ '\n' + textSplit.quote }}</span></template></div>

            <button v-if="(email.html_body && hasQuote) || textHasQuote" type="button" class="quote-toggle" :class="{ open: showQuote }" @click="toggleQuote" :title="t('mail.quotedText')">
                <span class="quote-dots">•••</span>
            </button>

            <div class="rd-reply">
                <button class="chip" @click="reply('reply')">↩︎ {{ t('mail.reply') }}</button>
                <button v-if="!isSent" class="chip" @click="reply('replyAll')">{{ t('mail.replyAll') }}</button>
                <button class="chip" @click="reply('forward')">➦ {{ t('mail.forward') }}</button>
            </div>
        </div>
    </div>
</template>
