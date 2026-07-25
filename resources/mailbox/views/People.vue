<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { initials, avatarColor } from '../avatar';
import { t } from '../i18n';

const auth = useAuth();
const router = useRouter();
const contacts = ref([]);
const q = ref('');
const selId = ref(null);
const form = ref(null);
let searchTimer = null;

const selected = computed(() => contacts.value.find((c) => c.id === selId.value) || null);

async function load() {
    const params = q.value ? { q: q.value } : {};
    try {
        const batches = await Promise.all(
            auth.scope().map(async (acc) => {
                const { data } = await auth.api(acc.email).get('/contacts', { params });
                return (data.data ?? []).map((c) => ({ ...c, _account: acc.email }));
            }),
        );
        contacts.value = batches.flat().sort((a, b) => a.name.localeCompare(b.name));
        if (!contacts.value.find((c) => c.id === selId.value)) selId.value = contacts.value[0]?.id ?? null;
    } catch (_) {
        contacts.value = [];
    }
}
function openNew() {
    form.value = { name: '', email: '', phone: '', company: '', title: '', notes: '', account: auth.current?.email };
}
function edit(c) {
    form.value = { ...c };
}
async function save() {
    const f = form.value;
    if (!f.name.trim()) return;
    const target = f._account || f.account;
    try {
        if (f.id) await auth.api(target).put(`/contacts/${f.id}`, f);
        else {
            const { data } = await auth.api(target).post('/contacts', f);
            selId.value = data.contact.id;
        }
        form.value = null;
        load();
    } catch (_) { /* ignore */ }
}
async function remove(c) {
    if (!confirm(t('people.deleteConfirm'))) return;
    await auth.api(c._account).delete(`/contacts/${c.id}`).catch(() => {});
    if (selId.value === c.id) selId.value = null;
    load();
}
function mailTo(c) {
    if (c.email) router.push({ path: '/compose', query: { to: c.email } });
}

watch(q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});
watch(() => auth.active, load);
onMounted(load);
</script>

<template>
    <div class="ppl" :class="{ 'has-sel': !!selId }">
        <div class="ppl-list">
            <header class="ppl-head">
                <h1 class="mb-title">{{ t('people.title') }}</h1>
                <button class="mb-filter on" @click="openNew">＋</button>
            </header>
            <div class="mb-search">
                <svg viewBox="0 0 24 24" class="si"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M21 21l-4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                <input v-model="q" type="search" :placeholder="t('people.searchPh')" />
            </div>
            <div class="ppl-rows">
                <div v-if="!contacts.length" class="mb-empty">{{ t('people.empty') }}</div>
                <button v-for="c in contacts" :key="c._account + ':' + c.id" class="ppl-row" :class="{ active: selId === c.id }" @click="selId = c.id">
                    <span class="ava ava-sm" :style="{ background: avatarColor(c.email || c.name) }">{{ initials(c.name) }}</span>
                    <span class="acct-meta">
                        <b>{{ c.name }}</b>
                        <small>{{ c.email || c.company || '' }}<span v-if="auth.isUnified" class="mb-acc" style="margin-left:6px">{{ c._account }}</span></small>
                    </span>
                </button>
            </div>
        </div>

        <div class="ppl-detail">
            <button v-if="selected" class="ghost-ic ppl-back" @click="selId = null">‹</button>
            <div v-if="selected" class="ppl-card">
                <div class="ppl-hero">
                    <span class="ava" :style="{ background: avatarColor(selected.email || selected.name), width: '72px', height: '72px', minWidth: '72px', maxWidth: '72px', fontSize: '26px' }">{{ initials(selected.name) }}</span>
                    <div>
                        <h2 class="ppl-name">{{ selected.name }}</h2>
                        <p class="ppl-title">{{ selected.title || '—' }}</p>
                    </div>
                </div>
                <div class="ppl-actions">
                    <button class="btn" :disabled="!selected.email" @click="mailTo(selected)">✉ {{ t('people.sendMail') }}</button>
                    <button class="btn ghost" @click="edit(selected)">{{ t('people.edit') }}</button>
                    <button class="btn danger" @click="remove(selected)">{{ t('common.delete') }}</button>
                </div>
                <dl class="ppl-dl">
                    <dt>{{ t('people.email') }}</dt><dd>{{ selected.email || '—' }}</dd>
                    <dt>{{ t('people.phone') }}</dt><dd>{{ selected.phone || '—' }}</dd>
                    <dt>{{ t('people.company') }}</dt><dd>{{ selected.company || '—' }}</dd>
                    <dt v-if="selected.notes">{{ t('people.notes') }}</dt><dd v-if="selected.notes">{{ selected.notes }}</dd>
                </dl>
            </div>
            <div v-else class="pane-empty"><div class="pane-empty-icon">👤</div><p class="pane-empty-title">{{ t('people.empty') }}</p></div>
        </div>

        <div v-if="form" class="modal-scrim" @click.self="form = null">
            <div class="modal">
                <h2 class="modal-title">{{ form.id ? t('people.edit') : t('people.newContact') }}</h2>
                <label v-if="auth.isUnified && !form.id" class="fld">
                    <span>{{ t('compose.from') }}</span>
                    <select v-model="form.account" class="cal-acc-sel">
                        <option v-for="a in auth.accounts" :key="a.email" :value="a.email">{{ a.display_name || a.email }}</option>
                    </select>
                </label>
                <label class="fld"><span>{{ t('people.name') }}</span><input v-model="form.name" type="text" /></label>
                <label class="fld"><span>{{ t('people.email') }}</span><input v-model="form.email" type="email" /></label>
                <div class="fld-row">
                    <label class="fld"><span>{{ t('people.phone') }}</span><input v-model="form.phone" type="text" /></label>
                    <label class="fld"><span>{{ t('people.company') }}</span><input v-model="form.company" type="text" /></label>
                </div>
                <label class="fld"><span>{{ t('people.jobTitle') }}</span><input v-model="form.title" type="text" /></label>
                <div class="modal-actions">
                    <span class="spacer" />
                    <button class="btn ghost" @click="form = null">{{ t('common.cancel') }}</button>
                    <button class="btn" @click="save">{{ t('common.save') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
