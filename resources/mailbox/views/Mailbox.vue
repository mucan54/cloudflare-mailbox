<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';

const props = defineProps({ folder: { type: String, default: 'inbox' } });
const auth = useAuth();
const router = useRouter();
const drawer = inject('drawer');

const emails = ref([]);
const loading = ref(true);
const search = ref('');
const newCount = ref(0);
let searchTimer = null;
let pollTimer = null;

function keyOf(e) {
    return e._account + ':' + e.id;
}

const titles = {
    inbox: 'Gelen kutusu',
    starred: 'Yıldızlı',
    sent: 'Gönderilenler',
    trash: 'Çöp kutusu',
};
const title = computed(() => titles[props.folder] || 'Gelen kutusu');
const isSent = computed(() => props.folder === 'sent');
const isTrash = computed(() => props.folder === 'trash');

async function load() {
    loading.value = true;
    const scope = auth.scope();
    const path = isSent.value ? '/sent' : '/emails';

    try {
        const batches = await Promise.all(
            scope.map(async (acc) => {
                const params = isSent.value ? {} : { folder: props.folder };
                if (search.value) params.q = search.value;
                const { data } = await auth.api(acc.email).get(path, { params });
                return (data.data ?? []).map((e) => ({ ...e, _account: acc.email }));
            }),
        );
        emails.value = batches
            .flat()
            .sort((a, b) => new Date(b.received_at || 0) - new Date(a.received_at || 0));
        newCount.value = 0;
    } catch (_) {
        emails.value = [];
    } finally {
        loading.value = false;
    }
}

// Silent background poll: fetch the current folder and merge any messages we
// haven't seen to the top, so new mail appears without a manual refresh.
async function poll() {
    if (search.value || document.visibilityState !== 'visible') return;

    const scope = auth.scope();
    const path = isSent.value ? '/sent' : '/emails';

    try {
        const batches = await Promise.all(
            scope.map(async (acc) => {
                const params = isSent.value ? {} : { folder: props.folder };
                const { data } = await auth.api(acc.email).get(path, { params });
                return (data.data ?? []).map((e) => ({ ...e, _account: acc.email }));
            }),
        );

        const seen = new Set(emails.value.map(keyOf));
        const additions = batches.flat().filter((e) => !seen.has(keyOf(e)));

        if (additions.length) {
            emails.value = [...additions, ...emails.value].sort(
                (a, b) => new Date(b.received_at || 0) - new Date(a.received_at || 0),
            );
            if (!isSent.value) {
                newCount.value += additions.length;
                auth.refreshUnread();
            }
        }
    } catch (_) {
        // ignore transient poll failures
    }
}

// Web Push wakes the page via a SW message → refresh instantly. Polling stays
// as a fallback (denied notifications / no VAPID key), at a relaxed interval.
onMounted(() => {
    window.addEventListener('mailbox:new-mail', poll);
    pollTimer = setInterval(poll, 45000);
});
onBeforeUnmount(() => {
    window.removeEventListener('mailbox:new-mail', poll);
    clearInterval(pollTimer);
});

function open(e) {
    router.push({
        path: `/mail/${e.id}`,
        query: { acc: e._account, type: isSent.value ? 'sent' : 'received' },
    });
}

async function toggleStar(e) {
    e.starred = !e.starred;
    try {
        await auth.api(e._account).patch(`/emails/${e.id}`, { starred: e.starred });
    } catch (_) {
        e.starred = !e.starred;
    }
    if (props.folder === 'starred' && !e.starred) {
        emails.value = emails.value.filter((x) => x !== e);
    }
}

async function trash(e) {
    const target = isTrash.value ? 'inbox' : 'trash';
    emails.value = emails.value.filter((x) => x !== e);
    try {
        await auth.api(e._account).patch(`/emails/${e.id}`, { folder: target });
    } catch (_) {
        load();
    }
}

async function toggleRead(e) {
    e.read = !e.read;
    try {
        await auth.api(e._account).patch(`/emails/${e.id}`, { read: e.read });
        auth.refreshUnread();
    } catch (_) {
        e.read = !e.read;
    }
}

function fmt(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) {
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    if (d.getFullYear() === now.getFullYear()) {
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
    return d.toLocaleDateString([], { year: '2-digit', month: 'short', day: 'numeric' });
}

function who(e) {
    return isSent.value ? `Kime: ${e.to_email || ''}` : e.from_name || e.from_email;
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});
watch(() => [props.folder, auth.active], load, { immediate: true });
</script>

<template>
    <div class="topbar">
        <button class="icon-btn menu" @click="drawer.open()">☰</button>
        <h1 class="topbar-title">{{ title }}</h1>
        <button class="icon-btn" title="Yenile" @click="load">⟳</button>
    </div>

    <div class="searchbar">
        <input v-model="search" type="search" placeholder="Ara…" />
    </div>

    <button v-if="newCount > 0" class="new-mail" @click="newCount = 0">
        {{ newCount }} yeni ileti ↑
    </button>

    <div class="list">
        <div v-if="loading" class="empty">Yükleniyor…</div>
        <div v-else-if="!emails.length" class="empty">Bu klasör boş.</div>

        <div
            v-for="e in emails"
            :key="e._account + ':' + e.id"
            class="row"
            :class="{ unread: !e.read && !isSent }"
            @click="open(e)"
        >
            <button
                v-if="!isSent"
                class="star"
                :class="{ on: e.starred }"
                title="Yıldızla"
                @click.stop="toggleStar(e)"
            >
                {{ e.starred ? '★' : '☆' }}
            </button>

            <div class="row-body">
                <div class="row-top">
                    <span class="from">
                        <span v-if="!e.read && !isSent" class="dot" />{{ who(e) }}
                    </span>
                    <span class="time">{{ fmt(e.received_at) }}</span>
                </div>
                <div class="subject">
                    {{ e.subject || '(konu yok)' }}
                    <span v-if="auth.isUnified" class="acc-tag">{{ e._account }}</span>
                </div>
                <div class="snippet">{{ e.snippet }}</div>
            </div>

            <div v-if="!isSent" class="row-actions">
                <button class="icon-btn" :title="e.read ? 'Okunmadı yap' : 'Okundu yap'" @click.stop="toggleRead(e)">
                    {{ e.read ? '✉️' : '📖' }}
                </button>
                <button class="icon-btn" :title="isTrash ? 'Geri al' : 'Sil'" @click.stop="trash(e)">
                    {{ isTrash ? '↩︎' : '🗑️' }}
                </button>
            </div>
        </div>
    </div>

    <button class="fab" title="Yeni ileti" @click="router.push('/compose')">＋</button>
</template>
