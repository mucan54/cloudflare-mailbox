<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth, UNIFIED } from './stores/auth';
import { initials, avatarColor } from './avatar';
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

const isLogin = computed(() => route.path === '/login');
const showChrome = computed(() => auth.isAuthenticated && !isLogin.value);

const folders = [
    { key: 'inbox', label: 'Gelen', icon: 'inbox' },
    { key: 'starred', label: 'Yıldızlı', icon: 'star' },
    { key: 'sent', label: 'Gönderilen', icon: 'sent' },
    { key: 'trash', label: 'Çöp', icon: 'trash' },
];

// SVG icon set (stroke-based, inherits currentColor).
const ICONS = {
    inbox: '<svg viewBox="0 0 24 24"><path d="M3 13l2.5-7A2 2 0 0 1 7.4 5h9.2a2 2 0 0 1 1.9 1.3L21 13v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M3 13h5l1 2h6l1-2h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    star: '<svg viewBox="0 0 24 24"><path d="M12 4l2.3 4.7 5.2.8-3.8 3.7.9 5.2L12 16.9 7.4 18.4l.9-5.2L4.5 9.5l5.2-.8L12 4Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    sent: '<svg viewBox="0 0 24 24"><path d="M21 4L3 11l6 2.5L11 20l3-5 7-11Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 13.5L21 4" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
    trash: '<svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V5h6v2M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    compose: '<svg viewBox="0 0 24 24"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
};
function icon(name) {
    return ICONS[name] || '';
}
const currentFolder = computed(() => route.params.folder || 'inbox');
const onFolder = computed(() => route.path.startsWith('/f/'));
// Hide the mobile tab bar on full-screen views so it never covers the reply
// bar / compose footer.
const hideNav = computed(() => route.path.startsWith('/mail') || route.path === '/compose');

function go(key) {
    router.push(`/f/${key}`);
}
function compose() {
    router.push('/compose');
}

// ----- Account sheet (shared by mobile header avatar + desktop sidebar) -----
const sheetOpen = ref(false);
provide('accountSheet', { open: () => (sheetOpen.value = true) });

function pickAccount(email) {
    auth.setActive(email);
    sheetOpen.value = false;
    if (!onFolder.value) router.push('/f/inbox');
}
function addAccount() {
    sheetOpen.value = false;
    router.push('/login');
}
async function signOut(email) {
    await auth.logout(email);
    sheetOpen.value = false;
    if (!auth.isAuthenticated) router.push('/login');
}

// ----- Notifications -----
const notifState = ref(notificationPermission());
const canPrompt = computed(
    () => pushSupported() && !!vapidKey() && notifState.value === 'default' && auth.isAuthenticated,
);
async function enableNotifications() {
    await requestAndSubscribe(auth.accounts);
    notifState.value = notificationPermission();
}

// ----- Live updates: SW push message + unread poll + tab title -----
let unreadTimer = null;
function onSwMessage(e) {
    if (e.data?.type === 'new-mail') {
        auth.refreshUnread().catch(() => {});
        window.dispatchEvent(new CustomEvent('mailbox:new-mail'));
    }
}
watch(
    () => auth.totalUnread,
    (n) => { document.title = n > 0 ? `(${n}) Mailbox` : 'Mailbox'; },
    { immediate: true },
);
watch(
    () => auth.accounts.length,
    () => enablePushIfGranted(auth.accounts).catch(() => {}),
);
onMounted(() => {
    if ('serviceWorker' in navigator) navigator.serviceWorker.addEventListener('message', onSwMessage);
    if (auth.isAuthenticated) {
        auth.refreshUnread().catch(() => {});
        enablePushIfGranted(auth.accounts).catch(() => {});
        unreadTimer = setInterval(() => {
            if (auth.isAuthenticated && document.visibilityState === 'visible') auth.refreshUnread().catch(() => {});
        }, 30000);
    }
});
onBeforeUnmount(() => {
    clearInterval(unreadTimer);
    if ('serviceWorker' in navigator) navigator.serviceWorker.removeEventListener('message', onSwMessage);
});
</script>

<template>
    <div v-if="showChrome" class="shell">
        <!-- Desktop sidebar -->
        <aside class="sidebar">
            <div class="brand">📬 <span>Mailbox</span></div>
            <button class="compose-cta" @click="compose">
                <svg viewBox="0 0 24 24" class="ic"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                Yeni ileti
            </button>

            <nav class="side-folders">
                <button
                    v-for="f in folders"
                    :key="f.key"
                    class="side-folder"
                    :class="{ active: currentFolder === f.key && onFolder }"
                    @click="go(f.key)"
                >
                    <span class="fic" v-html="icon(f.icon)" />
                    <span class="flabel">{{ f.label }}</span>
                    <span v-if="f.key === 'inbox' && auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                </button>
            </nav>

            <div class="side-accounts">
                <div class="side-cap">Hesaplar</div>
                <button
                    v-if="auth.accounts.length > 1"
                    class="acct-row"
                    :class="{ active: auth.isUnified }"
                    @click="pickAccount(UNIFIED)"
                >
                    <span class="ava ava-sm" style="background:#111827">∀</span>
                    <span class="acct-meta"><b>Tüm hesaplar</b><small>Birleşik</small></span>
                    <span v-if="auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                </button>
                <button
                    v-for="a in auth.accounts"
                    :key="a.email"
                    class="acct-row"
                    :class="{ active: auth.active === a.email }"
                    @click="pickAccount(a.email)"
                >
                    <span class="ava ava-sm" :style="{ background: avatarColor(a.email) }">{{ initials(a.display_name || a.email) }}</span>
                    <span class="acct-meta"><b>{{ a.display_name || a.email }}</b><small>{{ a.email }}</small></span>
                    <span v-if="a.unread" class="pill">{{ a.unread }}</span>
                </button>
                <button class="acct-add" @click="addAccount">＋ Hesap ekle</button>
                <button v-if="canPrompt" class="acct-add notif" @click="enableNotifications">🔔 Bildirimleri aç</button>
            </div>
        </aside>

        <main class="main">
            <router-view v-slot="{ Component }">
                <transition name="view">
                    <component :is="Component" :key="route.fullPath" />
                </transition>
            </router-view>
        </main>

        <!-- Mobile bottom nav -->
        <nav v-if="!hideNav" class="tabbar">
            <button
                v-for="f in folders"
                :key="f.key"
                class="tab"
                :class="{ active: currentFolder === f.key && onFolder }"
                @click="go(f.key)"
            >
                <span class="tic" v-html="icon(f.icon)" />
                <span class="tlabel">{{ f.label }}</span>
                <span v-if="f.key === 'inbox' && auth.totalUnread" class="tdot" />
            </button>
            <button class="tab compose" @click="compose">
                <span class="tic" v-html="icon('compose')" />
                <span class="tlabel">Yaz</span>
            </button>
        </nav>

        <!-- Account sheet -->
        <transition name="sheet">
            <div v-if="sheetOpen" class="sheet-scrim" @click="sheetOpen = false">
                <div class="sheet" @click.stop>
                    <div class="sheet-grip" />
                    <div class="sheet-cap">Hesaplar</div>
                    <button
                        v-if="auth.accounts.length > 1"
                        class="acct-row"
                        :class="{ active: auth.isUnified }"
                        @click="pickAccount(UNIFIED)"
                    >
                        <span class="ava ava-sm" style="background:#111827">∀</span>
                        <span class="acct-meta"><b>Tüm hesaplar</b><small>Birleşik görünüm</small></span>
                        <span v-if="auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                    </button>
                    <div v-for="a in auth.accounts" :key="a.email" class="acct-row" :class="{ active: auth.active === a.email }">
                        <button class="acct-pick" @click="pickAccount(a.email)">
                            <span class="ava ava-sm" :style="{ background: avatarColor(a.email) }">{{ initials(a.display_name || a.email) }}</span>
                            <span class="acct-meta"><b>{{ a.display_name || a.email }}</b><small>{{ a.email }}</small></span>
                        </button>
                        <button class="acct-signout" title="Çıkış" @click="signOut(a.email)">⏻</button>
                    </div>
                    <button class="acct-add" @click="addAccount">＋ Hesap ekle</button>
                    <button v-if="canPrompt" class="acct-add notif" @click="enableNotifications">🔔 Bildirimleri aç</button>
                    <p v-else-if="notifState === 'denied'" class="notif-hint">Bildirimler engelli — tarayıcı ayarlarından izin verin.</p>
                </div>
            </div>
        </transition>
    </div>

    <router-view v-else v-slot="{ Component }">
        <transition name="view">
            <component :is="Component" :key="route.fullPath" />
        </transition>
    </router-view>
</template>
