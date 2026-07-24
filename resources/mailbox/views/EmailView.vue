<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../api';

const props = defineProps({ id: String });
const router = useRouter();
const email = ref(null);

onMounted(async () => {
    const { data } = await api.get(`/emails/${props.id}`);
    email.value = data.email;
});

function reply() {
    router.push({ path: '/compose', query: { to: email.value.from_email, subject: `Re: ${email.value.subject || ''}`, reply: email.value.id } });
}
</script>

<template>
    <div class="topbar">
        <button class="btn ghost" @click="router.push('/')">‹ Geri</button>
        <button v-if="email" class="btn ghost" @click="reply">Yanıtla</button>
    </div>

    <div v-if="email" class="reader">
        <h2 style="margin:0 0 6px">{{ email.subject || '(konu yok)' }}</h2>
        <div class="meta">{{ email.from_email }} → {{ email.to_email }}</div>
        <div v-if="email.html_body" class="body" v-html="email.html_body" />
        <pre v-else class="body" style="white-space:pre-wrap">{{ email.text_body }}</pre>

        <div v-if="email.attachments?.length" style="margin-top:18px">
            <strong>Ekler</strong>
            <ul>
                <li v-for="a in email.attachments" :key="a.id">
                    <a v-if="a.url" :href="a.url">{{ a.filename }}</a>
                    <span v-else>{{ a.filename }}</span>
                </li>
            </ul>
        </div>
    </div>
    <div v-else class="empty">Yükleniyor…</div>
</template>
