<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useAuth } from '../stores/auth';
import { t, localeTag } from '../i18n';

const auth = useAuth();
const cur = ref(startOfMonth(new Date()));
const events = ref([]);
const form = ref(null); // { id?, title, dateKey, time, location }

function startOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
}
function keyOf(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const monthLabel = computed(() => cur.value.toLocaleDateString(localeTag(), { month: 'long', year: 'numeric' }));
const grid = computed(() => {
    const first = new Date(cur.value);
    const shift = (first.getDay() + 6) % 7; // Monday-first
    const start = new Date(first);
    start.setDate(start.getDate() - shift);
    const todayKey = keyOf(new Date());
    const out = [];
    for (let i = 0; i < 42; i++) {
        const d = new Date(start);
        d.setDate(d.getDate() + i);
        out.push({ key: keyOf(d), n: d.getDate(), out: d.getMonth() !== cur.value.getMonth(), today: keyOf(d) === todayKey });
    }
    return out;
});

async function load() {
    const start = new Date(cur.value);
    start.setDate(start.getDate() - 7);
    const end = new Date(cur.value.getFullYear(), cur.value.getMonth() + 1, 7);
    try {
        const { data } = await auth.api().get('/events', { params: { from: keyOf(start), to: keyOf(end) } });
        events.value = data.data ?? [];
    } catch (_) {
        events.value = [];
    }
}

function eventsOn(dateKey) {
    return events.value.filter((e) => (e.starts_at || '').slice(0, 10) === dateKey);
}
function timeOf(e) {
    return e.all_day ? '' : new Date(e.starts_at).toLocaleTimeString(localeTag(), { hour: '2-digit', minute: '2-digit' });
}

function shiftMonth(n) {
    cur.value = new Date(cur.value.getFullYear(), cur.value.getMonth() + n, 1);
}
function openNew(dateKey) {
    form.value = { title: '', dateKey: dateKey || keyOf(new Date()), time: '09:00', location: '' };
}
function editEvent(e) {
    form.value = {
        id: e.id, title: e.title, dateKey: e.starts_at.slice(0, 10),
        time: e.all_day ? '' : new Date(e.starts_at).toTimeString().slice(0, 5), location: e.location || '',
    };
}
async function save() {
    const f = form.value;
    if (!f.title.trim()) return;
    const startsAt = f.time ? `${f.dateKey}T${f.time}:00` : `${f.dateKey}T00:00:00`;
    const payload = { title: f.title.trim(), starts_at: startsAt, all_day: !f.time, location: f.location || null };
    try {
        if (f.id) await auth.api().put(`/events/${f.id}`, payload);
        else await auth.api().post('/events', payload);
        form.value = null;
        load();
    } catch (_) { /* ignore */ }
}
async function remove() {
    if (!form.value?.id || !confirm(t('calendar.deleteConfirm'))) return;
    await auth.api().delete(`/events/${form.value.id}`).catch(() => {});
    form.value = null;
    load();
}

watch(cur, load);
onMounted(load);
</script>

<template>
    <div class="cal">
        <header class="cal-bar">
            <button class="cal-new" @click="openNew()">＋ {{ t('calendar.newEvent') }}</button>
            <button class="cal-btn" @click="cur = startOfMonth(new Date())">{{ t('calendar.today') }}</button>
            <button class="ghost-ic" @click="shiftMonth(-1)">‹</button>
            <button class="ghost-ic" @click="shiftMonth(1)">›</button>
            <h1 class="cal-title">{{ monthLabel }}</h1>
        </header>

        <div class="cal-week">
            <div v-for="d in t('calendar.days')" :key="d" class="cal-wd">{{ d }}</div>
        </div>
        <div class="cal-grid">
            <div v-for="d in grid" :key="d.key" class="cal-day" :class="{ out: d.out }" @click="openNew(d.key)">
                <span class="cal-num" :class="{ today: d.today }">{{ d.n }}</span>
                <div class="cal-evs">
                    <div v-for="e in eventsOn(d.key)" :key="e.id" class="cal-ev" @click.stop="editEvent(e)">
                        <b v-if="timeOf(e)">{{ timeOf(e) }}</b> {{ e.title }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="form" class="modal-scrim" @click.self="form = null">
            <div class="modal">
                <h2 class="modal-title">{{ form.id ? t('common.save') : t('calendar.newEvent') }}</h2>
                <label class="fld"><span>{{ t('calendar.eventTitle') }}</span><input v-model="form.title" type="text" /></label>
                <div class="fld-row">
                    <label class="fld"><span>{{ t('common.today') }}</span><input v-model="form.dateKey" type="date" /></label>
                    <label class="fld"><span>{{ t('calendar.time') }}</span><input v-model="form.time" type="time" /></label>
                </div>
                <label class="fld"><span>{{ t('calendar.location') }}</span><input v-model="form.location" type="text" /></label>
                <div class="modal-actions">
                    <button v-if="form.id" class="btn danger" @click="remove">{{ t('common.delete') }}</button>
                    <span class="spacer" />
                    <button class="btn ghost" @click="form = null">{{ t('common.cancel') }}</button>
                    <button class="btn" @click="save">{{ t('common.save') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
