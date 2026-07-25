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
    // When a new worker takes control (e.g. the fixed notification handler),
    // reload once so the page is driven by the latest sw and assets. Guard on
    // a pre-existing controller so a first install doesn't reload.
    let reloading = false;
    const hadController = !!navigator.serviceWorker.controller;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (hadController && !reloading) {
            reloading = true;
            window.location.reload();
        }
    });

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((reg) => {
            // Actively check for a newer sw on every load and push a waiting one
            // straight to active — installed PWAs otherwise keep a stale sw.
            reg.update().catch(() => {});
            if (reg.waiting) reg.waiting.postMessage({ type: 'skip-waiting' });
            reg.addEventListener('updatefound', () => {
                const nw = reg.installing;
                if (!nw) return;
                nw.addEventListener('statechange', () => {
                    if (nw.state === 'installed' && reg.waiting) {
                        reg.waiting.postMessage({ type: 'skip-waiting' });
                    }
                });
            });
        }).catch(() => {});
    });
}

createApp(App).use(createPinia()).use(router).mount('#mailbox-app');
