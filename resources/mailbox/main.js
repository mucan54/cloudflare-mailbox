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

// Resume watchdog. iOS sometimes brings the PWA back to the foreground showing
// a blank frame after jettisoning its web view — the Vue tree is gone but the
// JS context is alive enough to run this. If we come back visible with an empty
// root, reload once (guarded by a session flag so it can never loop) to rebuild
// the app instead of sitting on a white screen until a full relaunch.
function mailboxRootEmpty() {
    const el = document.getElementById('mailbox-app');
    return !el || el.childElementCount === 0;
}
// A healthy mount clears the guard so the next genuine blank can recover.
if (!mailboxRootEmpty()) sessionStorage.removeItem('mb_reloaded');

function recoverIfBlank() {
    if (document.visibilityState !== 'visible') return;
    if (mailboxRootEmpty()) {
        if (!sessionStorage.getItem('mb_reloaded')) {
            sessionStorage.setItem('mb_reloaded', '1');
            window.location.reload();
        }
    } else {
        sessionStorage.removeItem('mb_reloaded');
    }
}
document.addEventListener('visibilitychange', recoverIfBlank);
window.addEventListener('pageshow', recoverIfBlank);
