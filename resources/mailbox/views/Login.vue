<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const router = useRouter();
const email = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

async function submit() {
    error.value = '';
    busy.value = true;
    try {
        await auth.login(email.value, password.value);
        router.push('/');
    } catch (e) {
        error.value = e.response?.data?.message || 'Giriş başarısız.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="center">
        <form class="card form" @submit.prevent="submit">
            <h1 style="margin:0 0 6px">Mailbox</h1>
            <p style="margin:0 0 8px;color:var(--muted);font-size:14px">Mail adresiniz ve şifrenizle giriş yapın.</p>
            <input v-model="email" type="email" placeholder="E-posta" autocomplete="username" required />
            <input v-model="password" type="password" placeholder="Şifre" autocomplete="current-password" required />
            <p v-if="error" class="error">{{ error }}</p>
            <button class="btn" :disabled="busy" type="submit">{{ busy ? '...' : 'Giriş yap' }}</button>
        </form>
    </div>
</template>
