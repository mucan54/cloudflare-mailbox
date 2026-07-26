<script>
// Module-scoped so the last-loaded list for each folder survives navigating to
// a message and back (keyed by account + folder).
const listCache = new Map();
</script>

<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { initials, avatarColor } from '../avatar';
import { pushSupported, pushPermission, refreshPushPermission, requestAndSubscribe, vapidKey } from '../push';
import { useIsDesktop } from '../useMedia';
import { t, localeTag } from '../i18n';
import Conversation from '../components/Conversation.vue';

const props = defineProps({ folder: { type: String, default: 'inbox' } });
const auth = useAuth();
const ui = useUi();
const router = useRouter();
const accountSheet = inject('accountSheet');
const isDesktop = useIsDesktop();

const emails = ref([]);
const loading = ref(true);
const newCount = ref(0);
const onlyUnread = ref(false);
const checked = ref(new Set());
let searchTimer = null;
let pollTimer = null;

// Pull-to-refresh (mobile)
const rootEl = ref(null);
const listEl = ref(null);
const pullDist = ref(0);
const refreshing = ref(false);
const isPulling = ref(false);
let ptrStartY = 0;

// Reading pane (desktop)
const selectedId = ref(null);
const selectedAcc = ref('');
const selectedType = ref('received');
const cursor = ref(-1);

// A row's kind. During a cross-folder search the list mixes received and sent,
// so each item carries its own _type; otherwise it follows the current folder.
function typeOf(e) {
    return e._type || (isSent.value ? 'sent' : 'received');
}

const folderKeys = ['inbox', 'starred', 'sent', 'trash'];
const title = computed(() => t(`nav.${props.folder}`) || t('nav.inbox'));
const isSent = computed(() => props.folder === 'sent');
const isTrash = computed(() => props.folder === 'trash');
const paneMode = computed(() => isDesktop.value && ui.readingPane !== 'off');
const greetName = computed(() => auth.current?.display_name || auth.current?.email?.split('@')[0] || '');
const headerAvatar = computed(() => {
    if (auth.isUnified) return { text: '∀', color: '#111827' };
    const a = auth.current;
    return { text: initials(a?.display_name || a?.email), color: avatarColor(a?.email || '') };
});

function keyOf(e) {
    return e._account + ':' + typeOf(e) + ':' + e.id;
}

const visible = computed(() => (onlyUnread.value && !isSent.value ? emails.value.filter((e) => !e.read) : emails.value));

const bySentDesc = (a, b) => new Date(b.received_at || 0) - new Date(a.received_at || 0);

// ----- conversation threading (inbox/starred/trash, not sent or search) -----
function stripRe(s) {
    let out = (s || '').trim();
    let prev;
    do { prev = out; out = out.replace(/^\s*(re|fwd?|ilt|yan|ynt|aw|sv|vs|antw|wg)\s*:\s*/i, ''); } while (out !== prev);
    return out.toLowerCase().trim();
}
function threadKeyOf(e) {
    const norm = stripRe(e.subject);
    // No real subject → don't group (unique per message).
    return norm ? `${e._account}|${norm}` : `${e._account}|__${e.id}`;
}
const threaded = computed(() => !isSent.value && !ui.search);

// Collapse `visible` into one representative row per conversation (the latest
// message, since the list is sorted newest-first), keeping thread metadata
// (count, member items, any-unread) keyed by the representative's row key.
const threadData = computed(() => {
    if (!threaded.value) return { reps: visible.value, meta: new Map() };
    const meta = new Map();
    const reps = [];
    const index = new Map();
    for (const e of visible.value) {
        const tk = threadKeyOf(e);
        if (!index.has(tk)) {
            index.set(tk, e);
            reps.push(e);
            meta.set(keyOf(e), { count: 1, items: [e], unread: !e.read });
        } else {
            const m = meta.get(keyOf(index.get(tk)));
            m.count++;
            m.items.push(e);
            if (!e.read) m.unread = true;
        }
    }
    return { reps, meta };
});
const rows = computed(() => threadData.value.reps);
function threadInfo(e) {
    return threadData.value.meta.get(keyOf(e)) || { count: 1, items: [e], unread: !e.read };
}
function rowUnread(e) {
    return typeOf(e) !== 'sent' && (threaded.value ? threadInfo(e).unread : !e.read);
}

async function fetchAll() {
    const scope = auth.scope();

    // A search spans BOTH mailboxes (inbox + sent) across every account, no
    // matter which folder is open, so nothing is missed.
    if (ui.search) {
        const batches = await Promise.all(
            scope.flatMap((acc) => [
                auth.api(acc.email).get('/emails', { params: { q: ui.search } })
                    .then(({ data }) => (data.data ?? []).map((e) => ({ ...e, _account: acc.email, _type: 'received' })))
                    .catch(() => []),
                auth.api(acc.email).get('/sent', { params: { q: ui.search } })
                    .then(({ data }) => (data.data ?? []).map((e) => ({ ...e, _account: acc.email, _type: 'sent' })))
                    .catch(() => []),
            ]),
        );
        return batches.flat().sort(bySentDesc);
    }

    const path = isSent.value ? '/sent' : '/emails';
    const type = isSent.value ? 'sent' : 'received';
    const batches = await Promise.all(
        scope.map(async (acc) => {
            const params = isSent.value ? {} : { folder: props.folder };
            const { data } = await auth.api(acc.email).get(path, { params });
            return (data.data ?? []).map((e) => ({ ...e, _account: acc.email, _type: type }));
        }),
    );
    return batches.flat().sort(bySentDesc);
}

async function load() {
    // Show the last list for this folder instantly (no loading flash) when
    // returning from a message, then refresh it in the background. This makes
    // back-from-detail feel smooth instead of a full reload.
    const key = ui.search ? null : `${auth.active}:${props.folder}`;
    const cached = key ? listCache.get(key) : null;
    if (cached && cached.length) {
        emails.value = cached;
        loading.value = false;
    } else {
        loading.value = true;
    }
    try {
        const fresh = await fetchAll();
        emails.value = fresh;
        if (key) listCache.set(key, fresh);
        newCount.value = 0;
    } catch (_) {
        if (!cached) emails.value = [];
    } finally {
        loading.value = false;
    }
}

async function poll() {
    if (ui.search || document.visibilityState !== 'visible') return;
    try {
        const fresh = await fetchAll();
        const seen = new Set(emails.value.map(keyOf));
        const additions = fresh.filter((e) => !seen.has(keyOf(e)));
        if (additions.length) {
            emails.value = [...additions, ...emails.value].sort((a, b) => new Date(b.received_at || 0) - new Date(a.received_at || 0));
            if (!isSent.value) {
                newCount.value += additions.length;
                auth.refreshUnread();
            }
        }
    } catch (_) { /* ignore */ }
}

// ----- grouping -----
function dayLabel(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    const today0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yest0 = new Date(today0);
    yest0.setDate(yest0.getDate() - 1);
    if (d >= today0) return t('common.today');
    if (d >= yest0) return t('common.yesterday');
    return d.toLocaleDateString(localeTag(), { day: 'numeric', month: 'long' });
}
const groups = computed(() => {
    const out = [];
    let cur = null;
    for (const e of rows.value) {
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
    return typeOf(e) === 'sent' ? (e.to_email || t('mail.noRecipient')) : (e.from_name || e.from_email);
}
function avatarSeed(e) {
    return typeOf(e) === 'sent' ? (e.to_email || '') : (e.from_email || '');
}
function fmt(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString(localeTag(), { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString(localeTag(), { day: 'numeric', month: 'short' });
}

function open(e) {
    cursor.value = rows.value.indexOf(e);
    const type = typeOf(e);
    // Optimistically mark the whole conversation read so the cached list shows
    // it read on return.
    if (type !== 'sent') threadInfo(e).items.forEach((m) => { m.read = true; });
    if (paneMode.value) {
        selectedId.value = e.id;
        selectedAcc.value = e._account;
        selectedType.value = type;
    } else {
        router.push({ path: `/mail/${e.id}`, query: { acc: e._account, type } });
    }
}

// ----- per-row + bulk actions -----
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
    // Trashing a conversation row moves every message in it.
    const items = threaded.value ? threadInfo(e).items.slice() : [e];
    const froms = items.map((m) => ({ m, from: m.folder || (isTrash.value ? 'trash' : 'inbox') }));
    const set = new Set(items);
    emails.value = emails.value.filter((x) => !set.has(x));
    if (items.some((m) => selectedId.value === m.id)) selectedId.value = null;
    try {
        await Promise.all(items.map((m) => auth.api(m._account).patch(`/emails/${m.id}`, { folder: target })));
        auth.refreshUnread();
    } catch (_) {
        load();
        return;
    }
    ui.toast(isTrash.value ? t('mail.restored') : t('mail.movedToTrash'), async () => {
        await Promise.all(froms.map(({ m, from }) => auth.api(m._account).patch(`/emails/${m.id}`, { folder: from }).catch(() => {})));
        auth.refreshUnread();
        load();
    });
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

// bulk
const checkedList = computed(() => emails.value.filter((e) => checked.value.has(keyOf(e))));
function toggleCheck(e) {
    const k = keyOf(e);
    const s = new Set(checked.value);
    s.has(k) ? s.delete(k) : s.add(k);
    checked.value = s;
}
function clearChecks() {
    checked.value = new Set();
}
async function bulkRead(read) {
    const items = checkedList.value;
    await Promise.all(items.map((e) => {
        e.read = read;
        return auth.api(e._account).patch(`/emails/${e.id}`, { read }).catch(() => {});
    }));
    auth.refreshUnread();
    clearChecks();
}
async function bulkTrash() {
    const items = checkedList.value.map((e) => ({ e, from: e.folder || 'inbox' }));
    const keys = new Set(items.map((x) => keyOf(x.e)));
    emails.value = emails.value.filter((e) => !keys.has(keyOf(e)));
    clearChecks();
    await Promise.all(items.map(({ e }) => auth.api(e._account).patch(`/emails/${e.id}`, { folder: isTrash.value ? 'inbox' : 'trash' }).catch(() => {})));
    auth.refreshUnread();
    ui.toast(t('mail.movedN', { n: items.length }), async () => {
        await Promise.all(items.map(({ e, from }) => auth.api(e._account).patch(`/emails/${e.id}`, { folder: from }).catch(() => {})));
        auth.refreshUnread();
        load();
    });
}
async function bulkStar() {
    await Promise.all(checkedList.value.map((e) => {
        e.starred = true;
        return auth.api(e._account).patch(`/emails/${e.id}`, { starred: true }).catch(() => {});
    }));
    clearChecks();
}

// ----- pull-to-refresh (tap-safe) -----
const PTR_COMMIT = 14;
let ptrCandidate = false;
function atTop() {
    const winTop = window.scrollY || document.documentElement.scrollTop || 0;
    const listTop = listEl.value ? listEl.value.scrollTop : 0;
    return winTop <= 0 && listTop <= 0;
}
function ptrStart(e) {
    ptrCandidate = !refreshing.value && e.touches.length === 1 && atTop();
    ptrStartY = ptrCandidate ? e.touches[0].clientY : 0;
    isPulling.value = false;
    pullDist.value = 0;
}
function ptrMove(e) {
    if (!ptrCandidate) return;
    const dy = e.touches[0].clientY - ptrStartY;
    if (dy < PTR_COMMIT) return;
    if (!atTop()) {
        ptrCandidate = false;
        return;
    }
    isPulling.value = true;
    pullDist.value = Math.min(96, (dy - PTR_COMMIT) * 0.5);
    if (e.cancelable) e.preventDefault();
}
async function ptrEnd() {
    ptrCandidate = false;
    if (!isPulling.value) {
        pullDist.value = 0;
        return;
    }
    isPulling.value = false;
    if (pullDist.value >= 52) {
        refreshing.value = true;
        pullDist.value = 48;
        try {
            await load();
        } finally {
            refreshing.value = false;
            pullDist.value = 0;
        }
    } else {
        pullDist.value = 0;
    }
}

// ----- keyboard shortcuts -----
function moveCursor(delta) {
    const list = rows.value;
    if (!list.length) return;
    cursor.value = Math.min(Math.max(cursor.value + delta, 0), list.length - 1);
    const e = list[cursor.value];
    if (paneMode.value && e) open(e);
    // scroll into view
    const el = listEl.value?.querySelector(`[data-k="${keyOf(e)}"]`);
    el?.scrollIntoView({ block: 'nearest' });
}
function onKey(ev) {
    const tag = (ev.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || ev.target.isContentEditable) return;
    const k = ev.key;
    if (k === '/') { ev.preventDefault(); document.getElementById('mb-search-input')?.focus(); return; }
    if (k === 'n' || k === 'N') { ev.preventDefault(); router.push('/compose'); return; }
    if (k === 'j' || k === 'J') { ev.preventDefault(); moveCursor(1); return; }
    if (k === 'k' || k === 'K') { ev.preventDefault(); moveCursor(-1); return; }
    const cur = cursor.value >= 0 ? rows.value[cursor.value] : null;
    if (!cur) return;
    if (k === 'Enter') { open(cur); }
    else if (k === 'u' || k === 'U') { toggleRead(cur); }
    else if (k === 'i' || k === 'I' || k === 's' || k === 'S') { if (!isSent.value) toggleStar(cur); }
    else if ((k === 'e' || k === 'E' || k === 'Delete' || k === '#') && !isSent.value) { trash(cur); }
}

// notification banner (mobile-first) — shares the global permission state so
// it hides the moment notifications are enabled anywhere else.
const notifDismissed = ref(sessionStorage.getItem('notif_dismissed') === '1');
const showNotifBanner = computed(() => props.folder === 'inbox' && pushSupported() && !!vapidKey() && pushPermission.value === 'default' && !notifDismissed.value);
async function enableNotif() {
    await requestAndSubscribe(auth.accounts);
    refreshPushPermission();
}
function dismissNotif() {
    notifDismissed.value = true;
    sessionStorage.setItem('notif_dismissed', '1');
}

function onReaderChanged() {
    load();
}

let searchDep = computed(() => ui.search);
watch(searchDep, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});
watch(() => [props.folder, auth.active], () => {
    selectedId.value = null;
    cursor.value = -1;
    clearChecks();
    load();
}, { immediate: true });

onMounted(() => {
    window.addEventListener('mailbox:new-mail', poll);
    window.addEventListener('keydown', onKey);
    pollTimer = setInterval(poll, 45000);
    const el = rootEl.value;
    if (el) {
        el.addEventListener('touchstart', ptrStart, { passive: true });
        el.addEventListener('touchmove', ptrMove, { passive: false });
        el.addEventListener('touchend', ptrEnd, { passive: true });
        el.addEventListener('touchcancel', ptrEnd, { passive: true });
    }
});
onBeforeUnmount(() => {
    window.removeEventListener('mailbox:new-mail', poll);
    window.removeEventListener('keydown', onKey);
    clearTimeout(searchTimer);
    clearInterval(pollTimer);
    const el = rootEl.value;
    if (el) {
        el.removeEventListener('touchstart', ptrStart);
        el.removeEventListener('touchmove', ptrMove);
        el.removeEventListener('touchend', ptrEnd);
        el.removeEventListener('touchcancel', ptrEnd);
    }
});
</script>

<template>
    <div class="mb" ref="rootEl" :class="{ pane: paneMode, [`d-${ui.density}`]: true }">
        <!-- LIST COLUMN -->
        <div class="mb-listcol">
            <header class="mb-head">
                <div class="mb-hello">
                    <span class="hi" v-if="props.folder === 'inbox' && !isDesktop">{{ t('mail.greeting') }}</span>
                    <h1 class="mb-title">{{ (props.folder === 'inbox' && !isDesktop) ? (greetName || title) : title }}</h1>
                </div>
                <button v-if="!isDesktop" class="mb-avatar" :style="{ background: headerAvatar.color }" :title="t('account.accounts')" @click="accountSheet.open()">
                    {{ headerAvatar.text }}
                </button>
                <button v-if="!isSent" class="mb-filter" :class="{ on: onlyUnread }" @click="onlyUnread = !onlyUnread">
                    {{ onlyUnread ? t('common.unread') : t('common.all') }}
                </button>
            </header>

            <div class="mb-search" v-if="!isDesktop">
                <svg viewBox="0 0 24 24" class="si"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input id="mb-search-input" v-model="ui.search" type="search" :placeholder="t('mail.searchPlaceholder')" />
            </div>

            <!-- mobile folder chips (bottom nav is the app switcher on mobile) -->
            <div class="mb-chips" v-if="!isDesktop">
                <button v-for="fk in folderKeys" :key="fk" class="mb-chip" :class="{ on: props.folder === fk }" @click="router.push(`/f/${fk}`)">
                    {{ t(`nav.${fk}`) }}
                </button>
            </div>

            <!-- bulk bar -->
            <div v-if="checked.size" class="mb-bulk">
                <span class="bulk-n">{{ t('mail.selected', { n: checked.size }) }}</span>
                <button class="bulk-b" @click="bulkRead(true)">📖</button>
                <button class="bulk-b" @click="bulkRead(false)">✉️</button>
                <button v-if="!isSent" class="bulk-b" @click="bulkStar">★</button>
                <button v-if="!isSent" class="bulk-b" @click="bulkTrash">🗑</button>
                <button class="bulk-b close" @click="clearChecks">✕</button>
            </div>

            <div v-if="showNotifBanner" class="notif-banner">
                <span>{{ t('notif.prompt') }}</span>
                <span class="nb-actions">
                    <button class="nb-yes" @click="enableNotif">{{ t('notif.on') }}</button>
                    <button class="nb-no" @click="dismissNotif">×</button>
                </span>
            </div>

            <button v-if="newCount > 0" class="new-mail" @click="newCount = 0">{{ t('mail.newMail', { n: newCount }) }}</button>

            <div class="ptr" :class="{ anim: !isPulling }" :style="{ height: pullDist + 'px' }">
                <span class="ptr-ic" :class="{ spin: refreshing }" :style="{ transform: refreshing ? '' : `rotate(${pullDist * 3}deg)`, opacity: (pullDist > 6 || refreshing) ? 1 : 0 }">↻</span>
            </div>

            <div class="mb-list" ref="listEl">
                <div v-if="loading" class="mb-empty">{{ t('common.loading') }}</div>
                <div v-else-if="!visible.length" class="mb-empty">{{ ui.search ? t('mail.noMatch') : t('mail.empty') }}</div>

                <template v-for="g in groups" :key="g.label">
                    <div class="mb-group">{{ g.label }}</div>
                    <div
                        v-for="e in g.items"
                        :key="keyOf(e)"
                        :data-k="keyOf(e)"
                        class="mb-row"
                        :class="{ unread: rowUnread(e), active: selectedId === e.id && selectedType === typeOf(e), picked: checked.has(keyOf(e)) }"
                        @click="open(e)"
                    >
                        <button class="mb-check" :class="{ on: checked.has(keyOf(e)) }" @click.stop="toggleCheck(e)">
                            <span class="mb-ava" :style="{ background: avatarColor(avatarSeed(e)) }">{{ initials(who(e)) }}</span>
                            <span class="mb-tick">✓</span>
                        </button>
                        <span class="mb-body">
                            <span class="mb-line1">
                                <span class="mb-who"><span v-if="rowUnread(e)" class="dot" />{{ who(e) }}<span v-if="threadInfo(e).count > 1" class="mb-count">{{ threadInfo(e).count }}</span></span>
                                <span class="mb-time">{{ fmt(e.received_at) }}</span>
                            </span>
                            <span class="mb-subject">
                                <span v-if="ui.search && typeOf(e) === 'sent'" class="mb-acc">{{ t('nav.sent') }}</span>
                                {{ e.subject || t('mail.noSubject') }}
                                <span v-if="auth.isUnified" class="mb-acc">{{ e._account }}</span>
                            </span>
                            <span class="mb-snippet">{{ e.snippet }}</span>
                        </span>
                        <span class="mb-side">
                            <span v-if="typeOf(e) !== 'sent'" class="mb-star" :class="{ on: e.starred }" @click.stop="toggleStar(e)">{{ e.starred ? '★' : '☆' }}</span>
                            <span v-if="typeOf(e) !== 'sent'" class="mb-trash" :title="isTrash ? 'Geri al' : 'Sil'" @click.stop="trash(e)">{{ isTrash ? '↩︎' : '🗑' }}</span>
                        </span>
                    </div>
                </template>
            </div>

            <div v-if="isDesktop" class="mb-status">
                <span>{{ t('mail.items', { n: visible.length }) }}</span>
                <span class="dotsep">·</span>
                <span>{{ t('mail.unreadCount', { n: auth.totalUnread }) }}</span>
            </div>
            <button v-if="!isDesktop" class="fab" @click="router.push('/compose')" :title="t('nav.compose')">✏️</button>
        </div>

        <!-- READING PANE (desktop) -->
        <div v-if="paneMode" class="mb-pane">
            <Conversation
                v-if="selectedId"
                :id="selectedId"
                :acc="selectedAcc"
                :type="selectedType"
                embedded
                @changed="onReaderChanged"
                @close="selectedId = null"
            />
            <div v-else class="pane-empty">
                <div class="pane-empty-icon">✉️</div>
                <p class="pane-empty-title">{{ t('mail.selectToRead') }}</p>
                <p class="pane-empty-sub">{{ t('mail.selectSub') }} <button class="linklike" @click="router.push('/compose')">{{ t('mail.composeLink') }}</button>.</p>
            </div>
        </div>
    </div>
</template>
