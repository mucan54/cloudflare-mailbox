<script setup>
import { onMounted } from 'vue';
import { useAuth } from './stores/auth';
import { enablePush } from './push';

const auth = useAuth();

onMounted(async () => {
    if (auth.isAuthenticated) {
        try {
            await auth.fetchMe();
            enablePush().catch(() => {});
        } catch (_) {
            // interceptor handles 401
        }
    }
});
</script>

<template>
    <div class="app">
        <router-view />
    </div>
</template>
