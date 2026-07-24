import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './app.css';

// Service worker is served from the root so its scope covers the whole app.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

createApp(App).use(createPinia()).use(router).mount('#mailbox-app');
