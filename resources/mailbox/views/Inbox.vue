<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const router = useRouter();
const emails = ref([]);
const loading = ref(true);

async function load() {
    loading.value = true;
    const { data } = await api.get('/emails');
    emails.value = data.data ?? [];
    loading.value = false;
}

function open(id) {
    router.push(`/mail/${id}`);
}

async function logout() {
    await auth.logout();
    router.push('/login');
}

function fmt(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

onMounted(load);
</script>

<template>
    <div class="topbar">
        <h1>Gelen kutusu</h1>
        <button class="btn ghost" @click="logout">Çıkış</button>
    </div>

    <div class="list">
        <div v-if="loading" class="empty">Yükleniyor…</div>
        <div v-else-if="!emails.length" class="empty">Henüz mail yok.</div>
        <a
            v-for="e in emails"
            :key="e.id"
            class="row"
            :class="{ unread: !e.read }"
            @click="open(e.id)"
        >
            <div class="from">
                <span><span v-if="!e.read" class="dot" />{{ e.from_name || e.from_email }}</span>
                <span class="time">{{ fmt(e.received_at) }}</span>
            </div>
            <div class="subject">{{ e.subject || '(konu yok)' }}</div>
            <div class="snippet">{{ e.snippet }}</div>
        </a>
    </div>

    <button class="btn fab" @click="router.push('/compose')">+</button>
</template>
