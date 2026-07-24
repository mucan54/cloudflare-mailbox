<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth, UNIFIED } from './stores/auth';
import {
    enablePushIfGranted,
    notificationPermission,
    pushSupported,
    requestAndSubscribe,
    vapidKey,
} from './push';

const auth = useAuth();
const route = useRoute();
const router = useRouter();

const drawerOpen = ref(false);
provide('drawer', { open: () => (drawerOpen.value = true) });

const isLogin = computed(() => route.path === '/login');
const showChrome = computed(() => auth.isAuthenticated && !isLogin.value);

const folders = [
    { key: 'inbox', label: 'Gelen kutusu', icon: '📥' },
    { key: 'starred', label: 'Yıldızlı', icon: '⭐' },
    { key: 'sent', label: 'Gönderilenler', icon: '📤' },
    { key: 'trash', label: 'Çöp kutusu', icon: '🗑️' },
];

const currentFolder = computed(() => route.params.folder || 'inbox');

function go(folderKey) {
    drawerOpen.value = false;
    router.push(`/f/${folderKey}`);
}

function pickAccount(email) {
    auth.setActive(email);
    drawerOpen.value = false;
    if (!route.path.startsWith('/f/')) {
        router.push('/f/inbox');
    }
}

function addAccount() {
    drawerOpen.value = false;
    router.push('/login');
}

async function signOut(email) {
    await auth.logout(email);
    if (!auth.isAuthenticated) {
        router.push('/login');
    }
}

function initials(acc) {
    const s = acc.display_name || acc.email || '?';
    return s.trim().charAt(0).toUpperCase();
}

// Notification permission state for the "enable notifications" affordance.
const notifState = ref(notificationPermission());
const canPrompt = computed(
    () => pushSupported() && !!vapidKey() && notifState.value === 'default' && auth.isAuthenticated,
);

async function enableNotifications() {
    await requestAndSubscribe(auth.accounts);
    notifState.value = notificationPermission();
}

// Keep the sidebar unread badges (and the browser tab title) live. Web Push +
// this SW message drive real-time updates; polling is only a fallback for when
// notifications are denied/unsupported.
let unreadTimer = null;

function onSwMessage(e) {
    if (e.data?.type === 'new-mail') {
        auth.refreshUnread().catch(() => {});
        window.dispatchEvent(new CustomEvent('mailbox:new-mail'));
    }
}

watch(
    () => auth.totalUnread,
    (n) => {
        document.title = n > 0 ? `(${n}) Mailbox` : 'Mailbox';
    },
    { immediate: true },
);

// Re-subscribe every account whenever the account set changes (e.g. add account).
watch(
    () => auth.accounts.length,
    () => enablePushIfGranted(auth.accounts).catch(() => {}),
);

onMounted(async () => {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', onSwMessage);
    }
    if (auth.isAuthenticated) {
        auth.refreshUnread().catch(() => {});
        enablePushIfGranted(auth.accounts).catch(() => {});
        unreadTimer = setInterval(() => {
            if (auth.isAuthenticated && document.visibilityState === 'visible') {
                auth.refreshUnread().catch(() => {});
            }
        }, 30000);
    }
});

onBeforeUnmount(() => {
    clearInterval(unreadTimer);
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.removeEventListener('message', onSwMessage);
    }
});
</script>

<template>
    <div v-if="showChrome" class="shell">
        <!-- Sidebar / drawer -->
        <div v-if="drawerOpen" class="scrim" @click="drawerOpen = false" />
        <aside class="sidebar" :class="{ open: drawerOpen }">
            <button class="compose-btn" @click="go('inbox'); router.push('/compose')">
                ✏️ Yeni ileti
            </button>

            <nav class="folders">
                <a
                    v-for="f in folders"
                    :key="f.key"
                    class="folder"
                    :class="{ active: currentFolder === f.key }"
                    @click="go(f.key)"
                >
                    <span class="fi">{{ f.icon }}</span>
                    <span class="fl">{{ f.label }}</span>
                    <span v-if="f.key === 'inbox' && auth.totalUnread" class="badge">
                        {{ auth.totalUnread }}
                    </span>
                </a>
            </nav>

            <div class="acc-head">Hesaplar</div>
            <div class="accounts">
                <a
                    v-if="auth.accounts.length > 1"
                    class="acc"
                    :class="{ active: auth.isUnified }"
                    @click="pickAccount(UNIFIED)"
                >
                    <span class="avatar all">∀</span>
                    <span class="acc-body">
                        <span class="acc-name">Tüm hesaplar</span>
                        <span class="acc-mail">Birleşik görünüm</span>
                    </span>
                    <span v-if="auth.totalUnread" class="badge">{{ auth.totalUnread }}</span>
                </a>

                <a
                    v-for="a in auth.accounts"
                    :key="a.email"
                    class="acc"
                    :class="{ active: auth.active === a.email }"
                    @click="pickAccount(a.email)"
                >
                    <span class="avatar">{{ initials(a) }}</span>
                    <span class="acc-body">
                        <span class="acc-name">{{ a.display_name || a.email }}</span>
                        <span class="acc-mail">{{ a.email }}</span>
                    </span>
                    <span v-if="a.unread" class="badge">{{ a.unread }}</span>
                    <button class="acc-out" title="Çıkış" @click.stop="signOut(a.email)">⏻</button>
                </a>
            </div>

            <button class="add-acc" @click="addAccount">＋ Hesap ekle</button>

            <button v-if="canPrompt" class="notif-btn" @click="enableNotifications">
                🔔 Bildirimleri aç
            </button>
            <p v-else-if="notifState === 'denied'" class="notif-hint">
                Bildirimler engelli — tarayıcı ayarlarından izin verin.
            </p>
        </aside>

        <main class="main">
            <router-view />
        </main>
    </div>

    <router-view v-else />
</template>
