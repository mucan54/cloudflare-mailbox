<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { initials, avatarColor } from '../avatar';
import { pushSupported, notificationPermission, requestAndSubscribe, vapidKey } from '../push';

const props = defineProps({ folder: { type: String, default: 'inbox' } });
const auth = useAuth();
const router = useRouter();
const accountSheet = inject('accountSheet');

const emails = ref([]);
const loading = ref(true);
const search = ref('');
const newCount = ref(0);
let searchTimer = null;
let pollTimer = null;

const titles = { inbox: 'Gelen kutusu', starred: 'Yıldızlı', sent: 'Gönderilenler', trash: 'Çöp kutusu' };
const title = computed(() => titles[props.folder] || 'Gelen kutusu');
const isSent = computed(() => props.folder === 'sent');
const isTrash = computed(() => props.folder === 'trash');
const greetName = computed(() => auth.current?.display_name || auth.current?.email?.split('@')[0] || '');
const headerAvatar = computed(() => {
    if (auth.isUnified) return { text: '∀', color: '#111827' };
    const a = auth.current;
    return { text: initials(a?.display_name || a?.email), color: avatarColor(a?.email || '') };
});

function keyOf(e) {
    return e._account + ':' + e.id;
}

async function fetchAll() {
    const scope = auth.scope();
    const path = isSent.value ? '/sent' : '/emails';
    const batches = await Promise.all(
        scope.map(async (acc) => {
            const params = isSent.value ? {} : { folder: props.folder };
            if (search.value) params.q = search.value;
            const { data } = await auth.api(acc.email).get(path, { params });
            return (data.data ?? []).map((e) => ({ ...e, _account: acc.email }));
        }),
    );
    return batches.flat().sort((a, b) => new Date(b.received_at || 0) - new Date(a.received_at || 0));
}

async function load() {
    loading.value = true;
    try {
        emails.value = await fetchAll();
        newCount.value = 0;
    } catch (_) {
        emails.value = [];
    } finally {
        loading.value = false;
    }
}

async function poll() {
    if (search.value || document.visibilityState !== 'visible') return;
    try {
        const fresh = await fetchAll();
        const seen = new Set(emails.value.map(keyOf));
        const additions = fresh.filter((e) => !seen.has(keyOf(e)));
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

// ----- grouping by day -----
function dayLabel(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    const t0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const y0 = new Date(t0);
    y0.setDate(y0.getDate() - 1);
    if (d >= t0) return 'Bugün';
    if (d >= y0) return 'Dün';
    return d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long' });
}

const groups = computed(() => {
    const out = [];
    let cur = null;
    for (const e of emails.value) {
        const label = dayLabel(e.received_at);
        if (!cur || cur.label !== label) {
            cur = { label, items: [] };
            out.push(cur);
        }
        cur.items.push(e);
    }
    return out;
});

function who(e) {
    if (isSent.value) return e.to_email || '(alıcı yok)';
    return e.from_name || e.from_email;
}
function avatarSeed(e) {
    return isSent.value ? (e.to_email || '') : (e.from_email || '');
}
function fmt(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'short' });
}

function open(e) {
    router.push({ path: `/mail/${e.id}`, query: { acc: e._account, type: isSent.value ? 'sent' : 'received' } });
}

async function toggleStar(e) {
    e.starred = !e.starred;
    try {
        await auth.api(e._account).patch(`/emails/${e.id}`, { starred: e.starred });
    } catch (_) {
        e.starred = !e.starred;
    }
    if (props.folder === 'starred' && !e.starred) emails.value = emails.value.filter((x) => x !== e);
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

// Discoverable notification prompt (inbox only).
const notifState = ref(notificationPermission());
const notifDismissed = ref(sessionStorage.getItem('notif_dismissed') === '1');
const showNotifBanner = computed(
    () => props.folder === 'inbox' && pushSupported() && !!vapidKey() && notifState.value === 'default' && !notifDismissed.value,
);
async function enableNotif() {
    await requestAndSubscribe(auth.accounts);
    notifState.value = notificationPermission();
}
function dismissNotif() {
    notifDismissed.value = true;
    sessionStorage.setItem('notif_dismissed', '1');
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});
watch(() => [props.folder, auth.active], load, { immediate: true });

onMounted(() => {
    window.addEventListener('mailbox:new-mail', poll);
    pollTimer = setInterval(poll, 45000);
});
onBeforeUnmount(() => {
    window.removeEventListener('mailbox:new-mail', poll);
    clearTimeout(searchTimer);
    clearInterval(pollTimer);
});
</script>

<template>
    <div class="mb">
        <header class="mb-head">
            <div class="mb-hello">
                <span class="hi" v-if="props.folder === 'inbox'">Merhaba 👋</span>
                <h1 class="mb-title">{{ props.folder === 'inbox' ? (greetName || title) : title }}</h1>
            </div>
            <button class="mb-avatar" :style="{ background: headerAvatar.color }" title="Hesaplar" @click="accountSheet.open()">
                {{ headerAvatar.text }}
            </button>
        </header>

        <div class="mb-search">
            <svg viewBox="0 0 24 24" class="si"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <input v-model="search" type="search" placeholder="Mail içinde ara" />
        </div>

        <div v-if="showNotifBanner" class="notif-banner">
            <span>🔔 Yeni mail bildirimi almak için izin verin.</span>
            <span class="nb-actions">
                <button class="nb-yes" @click="enableNotif">Aç</button>
                <button class="nb-no" @click="dismissNotif">×</button>
            </span>
        </div>

        <button v-if="newCount > 0" class="new-mail" @click="newCount = 0">{{ newCount }} yeni ileti ↑</button>

        <div class="mb-list">
            <div v-if="loading" class="mb-empty">Yükleniyor…</div>
            <div v-else-if="!emails.length" class="mb-empty">Bu klasör boş.</div>

            <template v-for="g in groups" :key="g.label">
                <div class="mb-group">{{ g.label }}</div>
                <button
                    v-for="e in g.items"
                    :key="e._account + ':' + e.id"
                    class="mb-row"
                    :class="{ unread: !e.read && !isSent }"
                    @click="open(e)"
                >
                    <span class="ava" :style="{ background: avatarColor(avatarSeed(e)) }">{{ initials(who(e)) }}</span>
                    <span class="mb-body">
                        <span class="mb-line1">
                            <span class="mb-who">{{ who(e) }}</span>
                            <span class="mb-time">{{ fmt(e.received_at) }}</span>
                        </span>
                        <span class="mb-subject">
                            {{ e.subject || '(konu yok)' }}
                            <span v-if="auth.isUnified" class="mb-acc">{{ e._account }}</span>
                        </span>
                        <span class="mb-snippet">{{ e.snippet }}</span>
                    </span>
                    <span class="mb-side">
                        <span
                            class="mb-star"
                            :class="{ on: e.starred }"
                            @click.stop="!isSent && toggleStar(e)"
                        >{{ e.starred ? '★' : (isSent ? '' : '☆') }}</span>
                        <span v-if="!isSent" class="mb-trash" :title="isTrash ? 'Geri al' : 'Sil'" @click.stop="trash(e)">
                            {{ isTrash ? '↩︎' : '🗑' }}
                        </span>
                    </span>
                </button>
            </template>
        </div>
    </div>
</template>
