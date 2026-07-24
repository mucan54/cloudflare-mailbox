<script setup>
import { computed, ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';

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
        if (!isSent.value) {
            auth.refreshUnread();
        }
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
    router.push({
        path: '/compose',
        query: { mode, src: props.id, acc, type },
    });
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
    if (!iso) return '';
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <div class="topbar">
        <button class="icon-btn" @click="back">‹</button>
        <h1 class="topbar-title">{{ isSent ? 'Gönderilen' : 'İleti' }}</h1>
        <div v-if="email && !isSent" class="topbar-tools">
            <button class="icon-btn" :class="{ starred: email.starred }" title="Yıldızla" @click="toggleStar">
                {{ email.starred ? '★' : '☆' }}
            </button>
            <button class="icon-btn" title="Sil" @click="trash">🗑️</button>
        </div>
    </div>

    <div v-if="loading" class="empty">Yükleniyor…</div>
    <div v-else-if="!email" class="empty">İleti bulunamadı.</div>

    <article v-else class="reader">
        <h2 class="reader-subject">{{ email.subject || '(konu yok)' }}</h2>
        <div class="reader-meta">
            <div><b>{{ email.from_name || email.from_email }}</b> &lt;{{ email.from_email }}&gt;</div>
            <div class="muted">Kime: {{ email.to_email }}</div>
            <div v-if="email.cc && email.cc.length" class="muted">Cc: {{ email.cc.join(', ') }}</div>
            <div class="muted">{{ fmt(email.received_at) }}</div>
        </div>

        <div v-if="email.html_body" class="body" v-html="email.html_body" />
        <pre v-else class="body text">{{ email.text_body }}</pre>

        <div v-if="email.attachments?.length" class="attachments">
            <div class="att-head">Ekler ({{ email.attachments.length }})</div>
            <a
                v-for="a in email.attachments"
                :key="a.id"
                class="att"
                :href="a.url || '#'"
                target="_blank"
            >
                📎 {{ a.filename }}
            </a>
        </div>

        <div class="reader-actions">
            <button class="btn" @click="compose('reply')">↩︎ Yanıtla</button>
            <button v-if="!isSent" class="btn ghost" @click="compose('replyAll')">↩︎ Tümünü yanıtla</button>
            <button class="btn ghost" @click="compose('forward')">➦ İlet</button>
        </div>
    </article>
</template>
