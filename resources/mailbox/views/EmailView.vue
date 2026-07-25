<script setup>
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import Reader from '../components/Reader.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuth();
</script>

<template>
    <Reader
        :id="route.params.id"
        :acc="route.query.acc || auth.current?.email || ''"
        :type="route.query.type || 'received'"
        @close="router.back()"
    />
</template>
