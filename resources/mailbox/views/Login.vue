<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';

const auth = useAuth();
const router = useRouter();
const email = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

const adding = computed(() => auth.isAuthenticated);

async function submit() {
    error.value = '';
    busy.value = true;
    try {
        await auth.login(email.value, password.value);
        router.push('/f/inbox');
    } catch (e) {
        error.value = e.response?.data?.message
            || (e.response?.data?.errors?.email?.[0])
            || 'Giriş başarısız.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="center">
        <form class="card form" @submit.prevent="submit">
            <h1 class="brand">📬 Mailbox</h1>
            <p class="sub">
                {{ adding ? 'Başka bir mail hesabı ekleyin.' : 'Mail adresiniz ve şifrenizle giriş yapın.' }}
            </p>
            <input v-model="email" type="email" placeholder="E-posta" autocomplete="username" required />
            <input v-model="password" type="password" placeholder="Şifre" autocomplete="current-password" required />
            <p v-if="error" class="error">{{ error }}</p>
            <button class="btn" :disabled="busy" type="submit">
                {{ busy ? '…' : (adding ? 'Hesabı ekle' : 'Giriş yap') }}
            </button>
            <button v-if="adding" class="btn ghost" type="button" @click="router.back()">İptal</button>
        </form>
    </div>
</template>
