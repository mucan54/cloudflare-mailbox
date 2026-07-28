<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth, UNIFIED } from './stores/auth';
import { useUi } from './stores/ui';
import { initials, avatarColor } from './avatar';
import { t, i18n, setLocale, AVAILABLE } from './i18n';
import {
    enablePushIfGranted, pushPermission, pushSupported, refreshPushPermission, requestAndSubscribe, vapidKey,
} from './push';
const auth = useAuth();
const ui = useUi();
const route = useRoute();
const router = useRouter();

const isLogin = computed(() => route.path === '/login');
const showChrome = computed(() => auth.isAuthenticated && !isLogin.value);

// ── Overlay navigation (kills the swipe-back blink, generically) ────────────
// Full-screen "pushed" routes — a message, compose, settings — render as an
// OVERLAY on top of the base list, instead of swapping the router-view. The
// base list underneath is never torn down, so returning (back button OR iOS
// swipe-back) just removes the overlay and reveals the exact same, already-
// painted list. There's no async re-render / re-attach a frame after iOS has
// revealed the DOM — which is what caused the blink on every back navigation.
const OVERLAY_ROUTES = ['/mail/', '/compose', '/settings'];
const isOverlayRoute = computed(() => OVERLAY_ROUTES.some((p) => (p.endsWith('/') ? route.path.startsWith(p) : route.path === p)));
const lastBasePath = ref('/f/inbox');
watch(() => route.fullPath, (p) => {
    if (!isOverlayRoute.value && route.path !== '/login') lastBasePath.value = p;
}, { immediate: true });
// While an overlay is open, keep the router-view pinned to the base list (same
// component + keep-alive key → the one instance is held, never deactivated).
const baseRoute = computed(() => (isOverlayRoute.value ? router.resolve(lastBasePath.value) : route));

// Route transition. On a browser BACK gesture (iOS interactive swipe-back) the
// OS already slides the previous page in; running our own fade on top re-shows
// the just-dismissed detail and fades it out — the "blink". So we suppress the
// transition for pop navigations and only fade on forward (push) navigations.
const routeTransition = ref('view');
function onPopState() {
    routeTransition.value = 'novt';
}
router.afterEach(() => {
    if (routeTransition.value === 'novt') {
        // Restore the fade only after this pop navigation has painted.
        requestAnimationFrame(() => { routeTransition.value = 'view'; });
    }
});

const ICONS = {
    inbox: '<svg viewBox="0 0 24 24"><path d="M3 13l2.5-7A2 2 0 0 1 7.4 5h9.2a2 2 0 0 1 1.9 1.3L21 13v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M3 13h5l1 2h6l1-2h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    star: '<svg viewBox="0 0 24 24"><path d="M12 4l2.3 4.7 5.2.8-3.8 3.7.9 5.2L12 16.9 7.4 18.4l.9-5.2L4.5 9.5l5.2-.8L12 4Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    sent: '<svg viewBox="0 0 24 24"><path d="M21 4L3 11l6 2.5L11 20l3-5 7-11Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    trash: '<svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V5h6v2M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    compose: '<svg viewBox="0 0 24 24"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>',
    mail: '<svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M22 7l-10 6L2 7" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
    calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M8 2v4M16 2v4M3 10h18" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
    people: '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" fill="none" stroke="currentColor" stroke-width="1.7"/><circle cx="9.5" cy="7" r="4" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M22 21v-2a4 4 0 0 0-3-3.9" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
    tasks: '<svg viewBox="0 0 24 24"><path d="M9 11l2 2 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="4" width="18" height="16" rx="2" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>',
};
function icon(name) {
    return ICONS[name] || '';
}

const folders = computed(() => [
    { key: 'inbox', label: t('nav.inbox'), icon: 'inbox' },
    { key: 'starred', label: t('nav.starred'), icon: 'star' },
    { key: 'sent', label: t('nav.sent'), icon: 'sent' },
    { key: 'trash', label: t('nav.trash'), icon: 'trash' },
]);
const apps = computed(() => [
    { key: 'mail', label: t('nav.mail'), icon: 'mail', to: '/f/inbox', match: (p) => p.startsWith('/f') || p.startsWith('/mail') || p === '/compose' },
    { key: 'calendar', label: t('nav.calendar'), icon: 'calendar', to: '/calendar', match: (p) => p.startsWith('/calendar') },
    { key: 'people', label: t('nav.people'), icon: 'people', to: '/people', match: (p) => p.startsWith('/people') },
    { key: 'tasks', label: t('nav.tasks'), icon: 'tasks', to: '/tasks', match: (p) => p.startsWith('/tasks') },
]);
const currentApp = computed(() => apps.value.find((a) => a.match(route.path))?.key || 'mail');
const isMail = computed(() => currentApp.value === 'mail');
const currentFolder = computed(() => route.params.folder || 'inbox');
const onFolder = computed(() => route.path.startsWith('/f/'));
const hideNav = computed(() => route.path.startsWith('/mail/') || route.path === '/compose' || route.path === '/settings');

function openSettings(email = null) {
    settingsOpen.value = false;
    sheetOpen.value = false;
    router.push(email ? { path: '/settings', query: { acc: email } } : '/settings');
}

function goApp(a) { router.push(a.to); }
function go(key) { router.push(`/f/${key}`); }
function compose() { router.push('/compose'); }

// account sheet
const sheetOpen = ref(false);
provide('accountSheet', { open: () => (sheetOpen.value = true) });
function pickAccount(email) {
    auth.setActive(email);
    sheetOpen.value = false;
    if (!onFolder.value) router.push('/f/inbox');
}
function addAccount() { sheetOpen.value = false; router.push('/login'); }
async function signOut(email) {
    await auth.logout(email);
    sheetOpen.value = false;
    if (!auth.isAuthenticated) router.push('/login');
}

// settings
const settingsOpen = ref(false);

// notifications — shared permission state so a second (stale) prompt can't show
const canPrompt = computed(() => pushSupported() && !!vapidKey() && pushPermission.value === 'default' && auth.isAuthenticated);
const notifState = pushPermission;
async function enableNotifications() {
    await requestAndSubscribe(auth.accounts);
    refreshPushPermission();
}

// live updates
let unreadTimer = null;
function onSwMessage(e) {
    if (e.data?.type === 'new-mail') {
        // A push just arrived — pull the new mail into the list immediately so
        // it's there whether or not the user taps the notification, and refresh
        // the badge from the real total.
        syncUnread();
        window.dispatchEvent(new CustomEvent('mailbox:new-mail'));
    }
    if (e.data?.type === 'open-mail' && e.data.url) openFromNotification(e.data.url);
}

// Tapping a notification: fetch first, then open the message. The mail may not
// be in the app's list yet, so we switch to its account and trigger a refresh
// before navigating to the detail (the reader also fetches it by id directly).
function openFromNotification(url) {
    try {
        const q = new URL(url, window.location.origin).searchParams;
        const acc = q.get('acc');
        if (acc && auth.accountByEmail(acc)) auth.setActive(acc);
    } catch (_) {
        // ignore malformed url
    }
    auth.refreshUnread().catch(() => {});
    window.dispatchEvent(new CustomEvent('mailbox:new-mail'));
    router.push(url);
}
// Mirror the unread count in the tab title and, on installed PWAs, the app
// icon badge (Badging API — supported on desktop Chrome/Edge and iOS 16.4+).
function setAppBadge(n) {
    try {
        if (n > 0 && navigator.setAppBadge) navigator.setAppBadge(n);
        else if (navigator.clearAppBadge) navigator.clearAppBadge();
    } catch (_) {
        // Badging unsupported or blocked — ignore.
    }
}
// Only touch the badge once we've actually loaded unread counts. Acting on the
// stale initial value (usually 0) would CLEAR a badge the service worker just
// set from an incoming push, making it look like badging doesn't work.
const unreadLoaded = ref(false);
watch(() => auth.totalUnread, (n) => {
    document.title = n > 0 ? `(${n}) Mailbox` : 'Mailbox';
    if (unreadLoaded.value) setAppBadge(n);
});
watch(() => auth.accounts.length, () => enablePushIfGranted(auth.accounts).catch(() => {}));

// Refresh unread counts, then update the badge from the real total.
function syncUnread() {
    return auth.refreshUnread().then(() => {
        unreadLoaded.value = true;
        setAppBadge(auth.totalUnread);
    }).catch(() => {});
}

// Refresh the push subscription when the app is reopened/refocused — push
// endpoints rotate and can go stale while the app is backgrounded, which is a
// common cause of "notifications just stopped".
function onVisible() {
    if (document.visibilityState !== 'visible') return;
    refreshPushPermission();
    if (!auth.isAuthenticated) return;
    syncUnread();
    enablePushIfGranted(auth.accounts).catch(() => {});
}
onMounted(() => {
    ui.applyTheme();
    window.addEventListener('popstate', onPopState);
    if ('serviceWorker' in navigator) navigator.serviceWorker.addEventListener('message', onSwMessage);
    document.addEventListener('visibilitychange', onVisible);
    if (auth.isAuthenticated) {
        syncUnread();
        enablePushIfGranted(auth.accounts).catch(() => {});
        unreadTimer = setInterval(() => {
            if (auth.isAuthenticated && document.visibilityState === 'visible') syncUnread();
        }, 30000);
    }
});
onBeforeUnmount(() => {
    clearInterval(unreadTimer);
    window.removeEventListener('popstate', onPopState);
    document.removeEventListener('visibilitychange', onVisible);
    if ('serviceWorker' in navigator) navigator.serviceWorker.removeEventListener('message', onSwMessage);
});
</script>

<template>
    <div v-if="showChrome" class="app">
        <header class="topbar">
            <div class="tb-brand">📬 <span>{{ t('brand') }}</span></div>
            <div class="tb-search">
                <svg viewBox="0 0 24 24" class="si"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input v-model="ui.search" type="search" :placeholder="t('mail.searchTop')" />
                <button v-if="ui.search" class="tb-x" @click="ui.search = ''">✕</button>
            </div>
            <div class="tb-tools">
                <button class="tb-ic" @click="ui.cycleTheme()">{{ ui.isDark ? '☾' : '☀' }}</button>
                <div class="tb-menuwrap">
                    <button class="tb-ic" @click="settingsOpen = !settingsOpen">⚙</button>
                    <div v-if="settingsOpen" class="tb-menu" @click.outside="settingsOpen = false">
                        <button class="tb-menu-link" @click="openSettings">⚙ {{ t('profile.open') }}</button>
                        <div class="tb-menu-cap">{{ t('settings.language') }}</div>
                        <div class="seg">
                            <button v-for="l in AVAILABLE" :key="l.code" :class="{ on: i18n.locale === l.code }" @click="setLocale(l.code)">{{ l.label }}</button>
                        </div>
                        <div class="tb-menu-cap">{{ t('settings.readingPane') }}</div>
                        <div class="seg">
                            <button v-for="p in [{v:'right',n:t('settings.right')},{v:'bottom',n:t('settings.bottom')},{v:'off',n:t('settings.off')}]" :key="p.v" :class="{ on: ui.readingPane === p.v }" @click="ui.setReadingPane(p.v)">{{ p.n }}</button>
                        </div>
                        <div class="tb-menu-cap">{{ t('settings.density') }}</div>
                        <div class="seg">
                            <button v-for="d in [{v:'comfortable',n:t('settings.comfortable')},{v:'compact',n:t('settings.compact')}]" :key="d.v" :class="{ on: ui.density === d.v }" @click="ui.setDensity(d.v)">{{ d.n }}</button>
                        </div>
                        <div class="tb-menu-cap">{{ t('settings.theme') }}</div>
                        <div class="seg">
                            <button v-for="th in [{v:'system',n:t('settings.system')},{v:'light',n:t('settings.light')},{v:'dark',n:t('settings.dark')}]" :key="th.v" :class="{ on: ui.theme === th.v }" @click="ui.setTheme(th.v)">{{ th.n }}</button>
                        </div>
                    </div>
                </div>
                <button class="tb-avatar" :style="{ background: auth.isUnified ? '#111827' : avatarColor(auth.current?.email || '') }" @click="sheetOpen = true">
                    {{ auth.isUnified ? '∀' : initials(auth.current?.display_name || auth.current?.email) }}
                </button>
            </div>
        </header>

        <div class="shell">
            <!-- App rail (desktop) -->
            <nav class="rail">
                <button v-for="a in apps" :key="a.key" class="rail-btn" :class="{ active: currentApp === a.key }" :title="a.label" @click="goApp(a)">
                    <span class="rail-ic" v-html="icon(a.icon)" />
                    <span v-if="a.key === 'mail' && auth.totalUnread" class="rail-badge">{{ auth.totalUnread }}</span>
                </button>
            </nav>

            <!-- Folder sidebar (mail only) -->
            <aside v-if="isMail" class="sidebar">
                <button class="compose-cta" @click="compose">
                    <svg viewBox="0 0 24 24" class="ic"><path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    {{ t('nav.compose') }}
                </button>
                <nav class="side-folders">
                    <button v-for="f in folders" :key="f.key" class="side-folder" :class="{ active: currentFolder === f.key && onFolder }" @click="go(f.key)">
                        <span class="fic" v-html="icon(f.icon)" />
                        <span class="flabel">{{ f.label }}</span>
                        <span v-if="f.key === 'inbox' && auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                    </button>
                </nav>
                <div class="side-accounts">
                    <div class="side-cap">{{ t('account.accounts') }}</div>
                    <button v-if="auth.accounts.length > 1" class="acct-row" :class="{ active: auth.isUnified }" @click="pickAccount(UNIFIED)">
                        <span class="ava ava-sm" style="background:#111827">∀</span>
                        <span class="acct-meta"><b>{{ t('account.all') }}</b><small>{{ t('account.unified') }}</small></span>
                        <span v-if="auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                    </button>
                    <button v-for="a in auth.accounts" :key="a.email" class="acct-row" :class="{ active: auth.active === a.email }" @click="pickAccount(a.email)">
                        <span class="ava ava-sm" :style="{ background: avatarColor(a.email) }">{{ initials(a.display_name || a.email) }}</span>
                        <span class="acct-meta"><b>{{ a.display_name || a.email }}</b><small>{{ a.email }}</small></span>
                        <span v-if="a.unread" class="pill">{{ a.unread }}</span>
                    </button>
                    <button class="acct-add" @click="addAccount">{{ t('account.add') }}</button>
                    <button v-if="canPrompt" class="acct-add notif" @click="enableNotifications">{{ t('notif.enable') }}</button>
                </div>
            </aside>

            <main class="main">
                <!-- Base layer: the current list, or (while an overlay is open)
                     the last list we were on — kept mounted underneath. -->
                <router-view :route="baseRoute" v-slot="{ Component, route: rendered }">
                    <transition :name="routeTransition">
                        <keep-alive :include="['Mailbox']">
                            <component :is="Component" :key="rendered.fullPath" />
                        </keep-alive>
                    </transition>
                </router-view>

                <!-- Overlay layer: the actual full-screen route (message /
                     compose / settings). Slides in on open; removed instantly on
                     back so it reveals the untouched base list in sync with iOS's
                     own swipe-back animation — no blink. -->
                <transition name="mailslide">
                    <div v-if="isOverlayRoute" class="mail-overlay">
                        <router-view v-slot="{ Component }">
                            <component :is="Component" />
                        </router-view>
                    </div>
                </transition>
            </main>
        </div>

        <!-- Mobile bottom nav = apps -->
        <nav v-if="!hideNav" class="tabbar">
            <button v-for="a in apps" :key="a.key" class="tab" :class="{ active: currentApp === a.key }" @click="goApp(a)">
                <span class="tic" v-html="icon(a.icon)" />
                <span class="tlabel">{{ a.label }}</span>
                <span v-if="a.key === 'mail' && auth.totalUnread" class="tdot" />
            </button>
        </nav>

        <!-- Account sheet -->
        <transition name="sheet">
            <div v-if="sheetOpen" class="sheet-scrim" @click="sheetOpen = false">
                <div class="sheet" @click.stop>
                    <div class="sheet-grip" />
                    <div class="sheet-cap">{{ t('account.accounts') }}</div>
                    <button v-if="auth.accounts.length > 1" class="acct-row" :class="{ active: auth.isUnified }" @click="pickAccount(UNIFIED)">
                        <span class="ava ava-sm" style="background:#111827">∀</span>
                        <span class="acct-meta"><b>{{ t('account.all') }}</b><small>{{ t('account.unifiedView') }}</small></span>
                        <span v-if="auth.totalUnread" class="pill">{{ auth.totalUnread }}</span>
                    </button>
                    <div v-for="a in auth.accounts" :key="a.email" class="acct-row" :class="{ active: auth.active === a.email }">
                        <button class="acct-pick" @click="pickAccount(a.email)">
                            <span class="ava ava-sm" :style="{ background: avatarColor(a.email) }">{{ initials(a.display_name || a.email) }}</span>
                            <span class="acct-meta"><b>{{ a.display_name || a.email }}</b><small>{{ a.email }}</small></span>
                        </button>
                        <button class="acct-edit" :title="t('profile.edit')" @click="openSettings(a.email)">
                            <svg viewBox="0 0 24 24"><path d="M4 20h4l10-10-4-4L4 16v4Zm11-13 2 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <button class="acct-signout" :title="t('account.signOut')" @click="signOut(a.email)">⏻</button>
                    </div>
                    <button class="acct-add" @click="openSettings">⚙ {{ t('profile.open') }}</button>
                    <button class="acct-add" @click="addAccount">{{ t('account.add') }}</button>
                    <button v-if="canPrompt" class="acct-add notif" @click="enableNotifications">{{ t('notif.enable') }}</button>
                    <p v-else-if="notifState === 'denied'" class="notif-hint">{{ t('notif.blocked') }}</p>
                    <div class="sheet-cap" style="margin-top:8px">{{ t('settings.language') }}</div>
                    <div class="seg" style="padding:0 8px 4px">
                        <button v-for="l in AVAILABLE" :key="l.code" :class="{ on: i18n.locale === l.code }" @click="setLocale(l.code)">{{ l.label }}</button>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Toasts -->
        <div class="toasts">
            <div v-for="tt in ui.toasts" :key="tt.id" class="toast">
                <span>{{ tt.message }}</span>
                <button v-if="tt.undo" class="toast-undo" @click="ui.runUndo(tt)">{{ t('common.undo') }}</button>
                <button class="toast-x" @click="ui.dismiss(tt.id)">✕</button>
            </div>
        </div>
    </div>

    <router-view v-else v-slot="{ Component }">
        <transition name="view">
            <component :is="Component" :key="route.fullPath" />
        </transition>
    </router-view>
</template>
