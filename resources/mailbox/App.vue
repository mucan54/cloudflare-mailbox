<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth, UNIFIED } from './stores/auth';
import { useUi } from './stores/ui';
import { initials, avatarColor } from './avatar';
import {
    enablePushIfGranted,
    notificationPermission,
    pushSupported,
    requestAndSubscribe,
    vapidKey,
} from './push';

const auth = useAuth();
const ui = useUi();
const route = useRoute();
const router = useRouter();

const isLogin = computed(() => route.path === '/login');
const showChrome = computed(() => auth.isAuthenticated && !isLogin.value);

const folders = [
    { key: 'inbox', label: 'Gelen kutusu', icon: 'inbox' },
    { key: 'starred', label: 'Yıldızlı', icon: 'star' },
    { key: 'sent', label: 'Gönderilenler', icon: 'sent' },
    { key: 'trash', label: 'Çöp kutusu', icon: 'trash' },
];
const railTabs = folders;

const ICONS = {
    inbox: '<svg viewBox="0 0 24 24"><path d="M3 13l2.5-7A2 2 0 0 1 7.4 5h9.2a2 2 0 0 1 1.9 1.3L21 13v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M3 13h5l1 2h6l1-2h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    star: '<svg viewBox="0 0 24 24"><path d="M12 4l2.3 4.7 5.2.8-3.8 3.7.9 5.2L12 16.9 7.4 18.4l.9-5.2L4.5 9.5l5.2-.8L12 4Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    sent: '<svg viewBox="0 0 24 24"><path d="M21 4L3 11l6 2.5L11 20l3-5 7-11Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    trash: '<svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V5h6v2M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    compose: '<svg viewBox="0 0 24 24"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
};
function icon(name) {
    return ICONS[name] || '';
}

const currentFolder = computed(() => route.params.folder || 'inbox');
const onFolder = computed(() => route.path.startsWith('/f/'));
const hideNav = computed(() => route.path.startsWith('/mail') || route.path === '/compose');

function go(key) {
    router.push(`/f/${key}`);
}
function compose() {
    router.push('/compose');
}

// ----- account sheet -----
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

// ----- settings menu -----
const settingsOpen = ref(false);
const themeLabel = computed(() => ({ system: 'Sistem', light: 'Açık', dark: 'Koyu' }[ui.theme]));

// ----- notifications -----
const notifState = ref(notificationPermission());
const canPrompt = computed(() => pushSupported() && !!vapidKey() && notifState.value === 'default' && auth.isAuthenticated);
async function enableNotifications() {
    await requestAndSubscribe(auth.accounts);
    notifState.value = notificationPermission();
}

// ----- live updates -----
let unreadTimer = null;
function onSwMessage(e) {
    if (e.data?.type === 'new-mail') {
        auth.refreshUnread().catch(() => {});
        window.dispatchEvent(new CustomEvent('mailbox:new-mail'));
    }
    if (e.data?.type === 'open-mail' && e.data.url) router.push(e.data.url);
}
watch(() => auth.totalUnread, (n) => { document.title = n > 0 ? `(${n}) Mailbox` : 'Mailbox'; }, { immediate: true });
watch(() => auth.accounts.length, () => enablePushIfGranted(auth.accounts).catch(() => {}));
onMounted(() => {
    ui.applyTheme();
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
    <div v-if="showChrome" class="app">
        <!-- Desktop top bar (Outlook) -->
        <header class="topbar">
            <div class="tb-brand">📬 <span>Mailbox</span></div>
            <div class="tb-search">
                <svg viewBox="0 0 24 24" class="si"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input v-model="ui.search" type="search" placeholder="Ara  ( / )" />
                <button v-if="ui.search" class="tb-x" @click="ui.search = ''">✕</button>
            </div>
            <div class="tb-tools">
                <button class="tb-ic" :title="`Tema: ${themeLabel}`" @click="ui.cycleTheme()">{{ ui.isDark ? '☾' : '☀' }}</button>
                <div class="tb-menuwrap">
                    <button class="tb-ic" title="Ayarlar" @click="settingsOpen = !settingsOpen">⚙</button>
                    <div v-if="settingsOpen" class="tb-menu" @click.outside="settingsOpen = false">
                        <div class="tb-menu-cap">Okuma bölmesi</div>
                        <div class="seg">
                            <button v-for="p in [{v:'right',n:'Sağda'},{v:'bottom',n:'Altta'},{v:'off',n:'Kapalı'}]" :key="p.v"
                                    :class="{ on: ui.readingPane === p.v }" @click="ui.setReadingPane(p.v)">{{ p.n }}</button>
                        </div>
                        <div class="tb-menu-cap">Yoğunluk</div>
                        <div class="seg">
                            <button v-for="d in [{v:'comfortable',n:'Rahat'},{v:'compact',n:'Sıkışık'}]" :key="d.v"
                                    :class="{ on: ui.density === d.v }" @click="ui.setDensity(d.v)">{{ d.n }}</button>
                        </div>
                        <div class="tb-menu-cap">Tema</div>
                        <div class="seg">
                            <button v-for="t in [{v:'system',n:'Sistem'},{v:'light',n:'Açık'},{v:'dark',n:'Koyu'}]" :key="t.v"
                                    :class="{ on: ui.theme === t.v }" @click="ui.setTheme(t.v)">{{ t.n }}</button>
                        </div>
                    </div>
                </div>
                <button class="tb-avatar" :style="{ background: auth.isUnified ? '#111827' : avatarColor(auth.current?.email || '') }"
                        title="Hesaplar" @click="sheetOpen = true">
                    {{ auth.isUnified ? '∀' : initials(auth.current?.display_name || auth.current?.email) }}
                </button>
            </div>
        </header>

        <div class="shell">
            <!-- Desktop sidebar -->
            <aside class="sidebar">
                <button class="compose-cta" @click="compose">
                    <svg viewBox="0 0 24 24" class="ic"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    Yeni ileti
                </button>
                <nav class="side-folders">
                    <button v-for="f in folders" :key="f.key" class="side-folder"
                            :class="{ active: currentFolder === f.key && onFolder }" @click="go(f.key)">
                        <span class="fic" v-html="icon(f.icon)" />
                        <span class="flabel">{{ f.label }}</span>
                        <span v-if="f.key === 'inbox' && auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                    </button>
                </nav>
                <div class="side-accounts">
                    <div class="side-cap">Hesaplar</div>
                    <button v-if="auth.accounts.length > 1" class="acct-row" :class="{ active: auth.isUnified }" @click="pickAccount(UNIFIED)">
                        <span class="ava ava-sm" style="background:#111827">∀</span>
                        <span class="acct-meta"><b>Tüm hesaplar</b><small>Birleşik</small></span>
                        <span v-if="auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                    </button>
                    <button v-for="a in auth.accounts" :key="a.email" class="acct-row" :class="{ active: auth.active === a.email }" @click="pickAccount(a.email)">
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
        </div>

        <!-- Mobile bottom nav -->
        <nav v-if="!hideNav" class="tabbar">
            <button v-for="f in railTabs" :key="f.key" class="tab" :class="{ active: currentFolder === f.key && onFolder }" @click="go(f.key)">
                <span class="tic" v-html="icon(f.icon)" />
                <span class="tlabel">{{ f.label.split(' ')[0] }}</span>
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
                    <button v-if="auth.accounts.length > 1" class="acct-row" :class="{ active: auth.isUnified }" @click="pickAccount(UNIFIED)">
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

        <!-- Toasts -->
        <div class="toasts">
            <div v-for="t in ui.toasts" :key="t.id" class="toast">
                <span>{{ t.message }}</span>
                <button v-if="t.undo" class="toast-undo" @click="ui.runUndo(t)">Geri al</button>
                <button class="toast-x" @click="ui.dismiss(t.id)">✕</button>
            </div>
        </div>
    </div>

    <router-view v-else v-slot="{ Component }">
        <transition name="view">
            <component :is="Component" :key="route.fullPath" />
        </transition>
    </router-view>
</template>
