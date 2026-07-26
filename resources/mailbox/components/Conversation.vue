<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { t } from '../i18n';
import MessageCard from './MessageCard.vue';

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

const account = computed(() => props.acc || auth.current?.email);
const isSent = computed(() => props.type === 'sent');
const subject = ref('');
const messages = ref([]);
const loading = ref(true);

// Index of the newest message — expanded by default (the rest start collapsed).
const lastIndex = computed(() => messages.value.length - 1);
// The received messages in this thread (used for read/star/trash actions).
const receivedIds = computed(() => messages.value.filter((m) => m.type === 'received').map((m) => m.id));
// Newest message overall — the reply/star target.
const latest = computed(() => messages.value[messages.value.length - 1] || null);
const latestReceived = computed(() => [...messages.value].reverse().find((m) => m.type === 'received') || null);
const starred = computed(() => latestReceived.value?.starred || false);

async function load(retry = 1) {
    loading.value = true;
    messages.value = [];
    try {
        if (isSent.value) {
            const { data } = await auth.api(account.value).get(`/sent/${props.id}`);
            subject.value = data.email.subject;
            messages.value = [{ ...data.email, type: 'sent', mine: true, received_at: data.email.received_at }];
        } else {
            const { data } = await auth.api(account.value).get(`/emails/${props.id}/thread`);
            subject.value = data.subject;
            messages.value = data.messages || [];
            // Opening marks the conversation read server-side; refresh the badge
            // counts. (The list's own read state is updated optimistically by the
            // caller, so no full list reload is needed here.)
            auth.refreshUnread();
        }
    } catch (e) {
        if (retry > 0 && e.response?.status !== 403) {
            await new Promise((r) => setTimeout(r, 600));
            return load(retry - 1);
        }
        messages.value = [];
    } finally {
        loading.value = false;
    }
}
watch(() => [props.id, props.acc, props.type], () => load(), { immediate: true });

function reply(mode) {
    const target = latestReceived.value || latest.value;
    if (!target) return;
    router.push({ path: '/compose', query: { mode, src: target.id, acc: account.value, type: target.type } });
}
async function toggleStar() {
    const m = latestReceived.value;
    if (!m) return;
    m.starred = !m.starred;
    await auth.api(account.value).patch(`/emails/${m.id}`, { starred: m.starred }).catch(() => {});
    emit('changed');
}
async function markUnread() {
    const m = latestReceived.value;
    if (!m) return;
    await auth.api(account.value).patch(`/emails/${m.id}`, { read: false }).catch(() => {});
    auth.refreshUnread();
    emit('changed');
    emit('close');
}
async function trash() {
    const ids = receivedIds.value;
    await Promise.all(ids.map((id) => auth.api(account.value).patch(`/emails/${id}`, { folder: 'trash' }).catch(() => {})));
    auth.refreshUnread();
    emit('changed');
    emit('close');
    ui.toast(t('mail.movedToTrash'), async () => {
        await Promise.all(ids.map((id) => auth.api(account.value).patch(`/emails/${id}`, { folder: 'inbox' }).catch(() => {})));
        auth.refreshUnread();
        emit('changed');
    });
}

defineExpose({ reply, toggleStar, trash, markUnread });
</script>

<template>
    <div class="rd">
        <header class="rd-bar">
            <button class="ghost-ic" :title="embedded ? t('common.close') : t('common.back')" @click="emit('close')">
                <svg v-if="!embedded" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <svg v-else viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>

            <div v-if="messages.length && !isSent" class="rd-acts">
                <button class="tbtn" @click="reply('reply')"><svg viewBox="0 0 24 24"><path d="M9 14 4 9l5-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 9h9a7 7 0 0 1 7 7v3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="tlbl">{{ t('mail.reply') }}</span></button>
                <button class="tbtn" @click="reply('replyAll')"><svg viewBox="0 0 24 24"><path d="M7 14 2 9l5-5M12 14 7 9l5-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 9h8a6 6 0 0 1 6 6v3" fill="none" stroke="currentColor" stroke-width="1.7"/></svg><span class="tlbl">{{ t('mail.replyAll') }}</span></button>
                <button class="tbtn" @click="reply('forward')"><svg viewBox="0 0 24 24"><path d="M15 14l5-5-5-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><path d="M20 9h-9a7 7 0 0 0-7 7v3" fill="none" stroke="currentColor" stroke-width="1.7"/></svg><span class="tlbl">{{ t('mail.forward') }}</span></button>
                <span class="tsep" />
                <button class="tbtn icon" :class="{ starred }" :title="t('mail.star')" @click="toggleStar">{{ starred ? '★' : '☆' }}</button>
                <button class="tbtn icon" :title="t('mail.markUnread')" @click="markUnread"><svg viewBox="0 0 24 24"><path d="M2 8l10 6 10-6" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="2" y="5" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></button>
                <button class="tbtn icon" :title="t('common.delete')" @click="trash"><svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V5h6v2M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </div>
            <h1 v-else class="rd-title">{{ isSent ? t('mail.sentItem') : t('mail.message') }}</h1>
        </header>

        <div v-if="loading" class="mb-empty">{{ t('common.loading') }}</div>
        <div v-else-if="!messages.length" class="mb-empty">{{ t('mail.notFound') }}</div>

        <div v-else class="rd-scroll">
            <h2 class="rd-subject">{{ subject || t('mail.noSubject') }}</h2>
            <div class="cv-thread">
                <MessageCard
                    v-for="(m, i) in messages"
                    :key="m.type + ':' + m.id"
                    :msg="m"
                    :acc="account"
                    :expanded="i === lastIndex"
                    :collapsible="messages.length > 1"
                />
            </div>

            <div class="rd-reply">
                <button class="chip" @click="reply('reply')">↩︎ {{ t('mail.reply') }}</button>
                <button v-if="!isSent" class="chip" @click="reply('replyAll')">{{ t('mail.replyAll') }}</button>
                <button class="chip" @click="reply('forward')">➦ {{ t('mail.forward') }}</button>
            </div>
        </div>
    </div>
</template>
