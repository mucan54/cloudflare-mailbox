<script setup>
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { t } from '../i18n';

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
        error.value = e.response?.data?.message || e.response?.data?.errors?.email?.[0] || t('login.failed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="login">
        <form class="lg-card" @submit.prevent="submit">
            <img class="lg-logo" :src="'/icons/logo.svg'" alt="" width="72" height="72" />
            <h1 class="lg-title">{{ adding ? t('login.addTitle') : t('login.title') }}</h1>
            <p class="lg-sub">{{ adding ? t('login.addSub') : t('login.sub') }}</p>

            <input v-model="email" class="lg-in" type="email" :placeholder="t('login.email')" autocomplete="username" required />
            <input v-model="password" class="lg-in" type="password" :placeholder="t('login.password')" autocomplete="current-password" required />
            <p v-if="error" class="error">{{ error }}</p>

            <button class="lg-btn" :disabled="busy" type="submit">{{ busy ? '…' : (adding ? t('login.addAccount') : t('login.signIn')) }}</button>
            <button v-if="adding" class="lg-ghost" type="button" @click="router.back()">{{ t('common.cancel') }}</button>
        </form>
    </div>
</template>
