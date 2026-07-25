import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './app.css';

// Apply the saved theme before mount to avoid a flash of the wrong theme.
(function applyThemeEarly() {
    const theme = localStorage.getItem('ui_theme') || 'system';
    const dark = theme === 'dark' || (theme === 'system' && window.matchMedia?.('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', !!dark);
})();

// Service worker is served from the root so its scope covers the whole app.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

createApp(App).use(createPinia()).use(router).mount('#mailbox-app');
