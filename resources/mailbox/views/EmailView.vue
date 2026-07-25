<script setup>
import { computed, ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { initials, avatarColor } from '../avatar';

const props = defineProps({ id: String });
const route = useRoute();
const router = useRouter();
const auth = useAuth();

const acc = route.query.acc || auth.current?.email;
const type = route.query.type || 'received';
const isSent = computed(() => type === 'sent');

const email = ref(null);
const loading = ref(true);

onMounted(async () => {
    const path = isSent.value ? `/sent/${props.id}` : `/emails/${props.id}`;
    try {
        const { data } = await auth.api(acc).get(path);
        email.value = data.email;
        if (!isSent.value) auth.refreshUnread();
    } catch (_) {
        email.value = null;
    } finally {
        loading.value = false;
    }
});

function back() {
    router.back();
}
function compose(mode) {
    router.push({ path: '/compose', query: { mode, src: props.id, acc, type } });
}
async function toggleStar() {
    email.value.starred = !email.value.starred;
    await auth.api(acc).patch(`/emails/${props.id}`, { starred: email.value.starred }).catch(() => {});
}
async function trash() {
    await auth.api(acc).patch(`/emails/${props.id}`, { folder: 'trash' }).catch(() => {});
    auth.refreshUnread();
    router.push('/f/inbox');
}
function fmt(iso) {
    return iso ? new Date(iso).toLocaleString('tr-TR', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '';
}
function fileKind(a) {
    return (a.mime_type || '').split('/')[1]?.toUpperCase() || 'DOSYA';
}
function fileSize(n) {
    if (!n) return '';
    return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
}
const peer = computed(() => (isSent.value ? email.value?.to_email : (email.value?.from_name || email.value?.from_email)) || '');
const peerEmail = computed(() => (isSent.value ? email.value?.to_email : email.value?.from_email) || '');
</script>

<template>
    <div class="rd">
        <header class="rd-bar">
            <button class="ghost-ic" @click="back">
                <svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <h1 class="rd-title">{{ isSent ? 'Gönderilen' : 'İleti' }}</h1>
            <div class="rd-tools" v-if="email && !isSent">
                <button class="ghost-ic" :class="{ starred: email.starred }" @click="toggleStar">{{ email.starred ? '★' : '☆' }}</button>
                <button class="ghost-ic" title="Sil" @click="trash">
                    <svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V5h6v2M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        </header>

        <div v-if="loading" class="mb-empty">Yükleniyor…</div>
        <div v-else-if="!email" class="mb-empty">İleti bulunamadı.</div>

        <div v-else class="rd-scroll">
            <h2 class="rd-subject">{{ email.subject || '(konu yok)' }}</h2>

            <div class="rd-sender">
                <span class="ava lg" :style="{ background: avatarColor(peerEmail) }">{{ initials(peer) }}</span>
                <div class="rd-sender-meta">
                    <div class="rd-name">{{ peer }}</div>
                    <div class="rd-email">{{ peerEmail }}</div>
                </div>
                <div class="rd-when">{{ fmt(email.received_at) }}</div>
            </div>

            <div v-if="email.html_body" class="rd-body" v-html="email.html_body" />
            <pre v-else class="rd-body text">{{ email.text_body }}</pre>

            <div v-if="email.attachments?.length" class="rd-atts">
                <div class="rd-atts-head">Ekler <span class="pill">{{ email.attachments.length }}</span></div>
                <div class="rd-atts-grid">
                    <a v-for="a in email.attachments" :key="a.id" class="att-card" :href="a.url || '#'" target="_blank">
                        <span class="att-thumb">📄</span>
                        <span class="att-name">{{ a.filename }}</span>
                        <span class="att-meta">{{ fileKind(a) }} {{ fileSize(a.size) }}</span>
                    </a>
                </div>
            </div>
        </div>

        <footer v-if="email" class="rd-actions">
            <button class="chip" @click="compose('reply')">↩︎ Yanıtla</button>
            <button v-if="!isSent" class="chip" @click="compose('replyAll')">Tümünü yanıtla</button>
            <button class="chip" @click="compose('forward')">➦ İlet</button>
        </footer>
    </div>
</template>
