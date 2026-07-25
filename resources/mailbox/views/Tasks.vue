<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuth } from '../stores/auth';
import { t, localeTag } from '../i18n';

const auth = useAuth();
const tasks = ref([]);
const newTitle = ref('');

const openCount = computed(() => tasks.value.filter((x) => !x.done).length);

async function load() {
    try {
        const { data } = await auth.api().get('/tasks');
        tasks.value = data.data ?? [];
    } catch (_) {
        tasks.value = [];
    }
}
async function add() {
    const title = newTitle.value.trim();
    if (!title) return;
    newTitle.value = '';
    try {
        const { data } = await auth.api().post('/tasks', { title });
        tasks.value.unshift(data.task);
    } catch (_) { /* ignore */ }
}
async function toggle(task) {
    task.done = !task.done;
    await auth.api().put(`/tasks/${task.id}`, { done: task.done }).catch(() => { task.done = !task.done; });
}
async function remove(task) {
    tasks.value = tasks.value.filter((x) => x.id !== task.id);
    await auth.api().delete(`/tasks/${task.id}`).catch(() => {});
}
function fmtDue(d) {
    return d ? new Date(d).toLocaleDateString(localeTag(), { day: 'numeric', month: 'short' }) : '';
}

onMounted(load);
</script>

<template>
    <div class="tsk">
        <header class="tsk-bar">
            <h1 class="mb-title">{{ t('tasks.title') }}</h1>
            <span class="tsk-count">{{ t('tasks.open', { n: openCount }) }}</span>
        </header>
        <div class="tsk-wrap">
            <div class="tsk-add">
                <input v-model="newTitle" type="text" :placeholder="t('tasks.addPh')" @keydown.enter="add" />
                <button class="btn" @click="add">{{ t('tasks.add') }}</button>
            </div>
            <div v-if="!tasks.length" class="mb-empty">{{ t('tasks.empty') }}</div>
            <ul class="tsk-list">
                <li v-for="task in tasks" :key="task.id" class="tsk-item">
                    <button class="tsk-check" :class="{ on: task.done }" @click="toggle(task)">
                        <span v-if="task.done">✓</span>
                    </button>
                    <span class="tsk-title" :class="{ done: task.done }">{{ task.title }}</span>
                    <span v-if="task.due_on" class="tsk-due">{{ fmtDue(task.due_on) }}</span>
                    <button class="tsk-del" @click="remove(task)">🗑</button>
                </li>
            </ul>
        </div>
    </div>
</template>
