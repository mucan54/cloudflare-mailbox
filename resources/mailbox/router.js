import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from './stores/auth';

import Login from './views/Login.vue';
import Mailbox from './views/Mailbox.vue';
import EmailView from './views/EmailView.vue';
import Compose from './views/Compose.vue';
import Calendar from './views/Calendar.vue';
import People from './views/People.vue';
import Tasks from './views/Tasks.vue';

const routes = [
    { path: '/login', component: Login, meta: { guest: true } },
    { path: '/', redirect: '/f/inbox' },
    { path: '/f/:folder', component: Mailbox, props: true },
    { path: '/mail/:id', component: EmailView, props: true },
    { path: '/compose', component: Compose },
    { path: '/calendar', component: Calendar },
    { path: '/people', component: People },
    { path: '/tasks', component: Tasks },
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
});

export default router;
