<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../api';

const route = useRoute();
const router = useRouter();
const to = ref(route.query.to || '');
const subject = ref(route.query.subject || '');
const body = ref('');
const busy = ref(false);
const error = ref('');

async function send() {
    error.value = '';
    busy.value = true;
    try {
        await api.post('/send', {
            to: to.value.split(/[,;\s]+/).filter(Boolean),
            subject: subject.value,
            html: `<p>${body.value.replace(/\n/g, '<br>')}</p>`,
            text: body.value,
            in_reply_to_email_id: route.query.reply ? Number(route.query.reply) : null,
        });
        router.push('/');
    } catch (e) {
        error.value = e.response?.data?.message || 'Gönderilemedi.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="topbar">
        <button class="btn ghost" @click="router.back()">İptal</button>
        <button class="btn" :disabled="busy" @click="send">{{ busy ? '...' : 'Gönder' }}</button>
    </div>

    <div class="form">
        <input v-model="to" type="text" placeholder="Alıcı (virgülle ayırın)" />
        <input v-model="subject" type="text" placeholder="Konu" />
        <textarea v-model="body" rows="12" placeholder="Mesajınız…" />
        <p v-if="error" class="error">{{ error }}</p>
    </div>
</template>
