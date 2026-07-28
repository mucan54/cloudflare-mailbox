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
// The sw calls skipWaiting()/clients.claim() itself, so a new version takes
// over on its own — we just register and check for updates once. We do NOT
// reload on controllerchange: on installed iOS PWAs that spirals into a
// refresh loop.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then((reg) => {
            reg.update().catch(() => {});
        }).catch(() => {});
    });
}

createApp(App).use(createPinia()).use(router).mount('#mailbox-app');

// (No resume watchdog: reloading on an "empty root" misfired — Vue's root is
// momentarily a comment node while the router resolves, so it force-reloaded a
// healthy app on the flaky just-resumed network and *caused* white screens. The
// true "iOS killed the web view" case is a fresh page load the service worker
// already handles: network-first shell → cached shell → self-reloading spinner,
// so a navigation never lands on a permanent blank.)
