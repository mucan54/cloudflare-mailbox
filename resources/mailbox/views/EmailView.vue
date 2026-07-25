<script setup>
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import Reader from '../components/Reader.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuth();

function close() {
    // Opened from a notification on a cold start there is no history to pop, so
    // router.back() would do nothing. Fall back to the mail's own inbox (in the
    // right account) instead of leaving the user stuck.
    if (window.history.state?.back) {
        router.back();
        return;
    }
    const acc = route.query.acc;
    if (acc && auth.accountByEmail(acc)) auth.setActive(acc);
    router.replace(route.query.type === 'sent' ? '/f/sent' : '/f/inbox');
}
</script>

<template>
    <Reader
        :id="route.params.id"
        :acc="route.query.acc || auth.current?.email || ''"
        :type="route.query.type || 'received'"
        @close="close"
    />
</template>
