import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from './stores/auth';

import Login from './views/Login.vue';
import Inbox from './views/Inbox.vue';
import EmailView from './views/EmailView.vue';
import Compose from './views/Compose.vue';

const routes = [
    { path: '/login', component: Login, meta: { guest: true } },
    { path: '/', component: Inbox },
    { path: '/mail/:id', component: EmailView, props: true },
    { path: '/compose', component: Compose },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach((to) => {
    const auth = useAuth();
    if (!to.meta.guest && !auth.isAuthenticated) {
        return '/login';
    }
    if (to.meta.guest && auth.isAuthenticated) {
        return '/';
    }
});

export default router;
