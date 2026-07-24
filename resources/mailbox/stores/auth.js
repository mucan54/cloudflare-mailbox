import { defineStore } from 'pinia';
import api from '../api';

export const useAuth = defineStore('auth', {
    state: () => ({
        mailbox: null,
        token: localStorage.getItem('mailbox_token') || null,
    }),
    getters: {
        isAuthenticated: (s) => !!s.token,
    },
    actions: {
        async login(email, password) {
            const { data } = await api.post('/login', { email, password });
            this.token = data.token;
            this.mailbox = data.mailbox;
            localStorage.setItem('mailbox_token', data.token);
        },
        async fetchMe() {
            const { data } = await api.get('/me');
            this.mailbox = data.mailbox;
        },
        async logout() {
            try {
                await api.post('/logout');
            } catch (_) {
                // ignore
            }
            this.token = null;
            this.mailbox = null;
            localStorage.removeItem('mailbox_token');
        },
    },
});
