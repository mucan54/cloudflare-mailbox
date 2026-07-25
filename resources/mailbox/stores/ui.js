import { defineStore } from 'pinia';

function load(key, def) {
    const v = localStorage.getItem(key);
    return v === null ? def : v;
}

/**
 * UI preferences (persisted) + a tiny toast system with optional undo.
 * Outlook-style shell settings: theme, density, reading-pane position.
 */
export const useUi = defineStore('ui', {
    state: () => ({
        theme: load('ui_theme', 'system'), // system | light | dark
        density: load('ui_density', 'comfortable'), // comfortable | compact
        readingPane: load('ui_reading', 'right'), // right | bottom | off
        search: '',
        toasts: [],
        _seq: 0,
    }),
    getters: {
        isDark(s) {
            if (s.theme === 'dark') return true;
            if (s.theme === 'light') return false;
            return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
        },
    },
    actions: {
        applyTheme() {
            const root = document.documentElement;
            root.classList.toggle('dark', this.isDark);
            root.dataset.theme = this.theme;
        },
        setTheme(t) {
            this.theme = t;
            localStorage.setItem('ui_theme', t);
            this.applyTheme();
        },
        cycleTheme() {
            const order = ['system', 'light', 'dark'];
            this.setTheme(order[(order.indexOf(this.theme) + 1) % order.length]);
        },
        setDensity(d) {
            this.density = d;
            localStorage.setItem('ui_density', d);
        },
        setReadingPane(p) {
            this.readingPane = p;
            localStorage.setItem('ui_reading', p);
        },
        toast(message, undo = null) {
            const id = ++this._seq;
            this.toasts.push({ id, message, undo });
            setTimeout(() => this.dismiss(id), undo ? 7000 : 4000);
            return id;
        },
        dismiss(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
        runUndo(t) {
            try {
                t.undo?.();
            } finally {
                this.dismiss(t.id);
            }
        },
    },
});
