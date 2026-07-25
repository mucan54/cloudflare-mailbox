<script setup>
import { computed, ref, watch } from 'vue';
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

async function load(retry = 1) {
    loading.value = true;
    email.value = null;
    const path = isSent.value ? `/sent/${props.id}` : `/emails/${props.id}`;
    try {
        const { data } = await auth.api(account.value).get(path);
        email.value = data.email;
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
function fileKind(a) {
    return (a.mime_type || '').split('/')[1]?.toUpperCase() || 'FILE';
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

            <div v-if="email.html_body" class="rd-body" v-html="email.html_body" />
            <pre v-else class="rd-body text">{{ email.text_body }}</pre>

            <div v-if="email.attachments?.length" class="rd-atts">
                <div class="rd-atts-head">{{ t('mail.attachments') }} <span class="pill">{{ email.attachments.length }}</span></div>
                <div class="rd-atts-grid">
                    <button v-for="a in email.attachments" :key="a.id" type="button" class="att-card" @click="download(a)">
                        <span class="att-thumb">📄</span>
                        <span class="att-name">{{ a.filename }}</span>
                        <span class="att-meta">{{ fileKind(a) }} {{ fileSize(a.size) }}</span>
                    </button>
                </div>
            </div>

            <div class="rd-reply">
                <button class="chip" @click="reply('reply')">↩︎ {{ t('mail.reply') }}</button>
                <button v-if="!isSent" class="chip" @click="reply('replyAll')">{{ t('mail.replyAll') }}</button>
                <button class="chip" @click="reply('forward')">➦ {{ t('mail.forward') }}</button>
            </div>
        </div>
    </div>
</template>
