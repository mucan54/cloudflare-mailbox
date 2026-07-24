import { defineStore } from 'pinia';
import { apiFor } from '../api';

const ACCOUNTS_KEY = 'mailbox_accounts';
const ACTIVE_KEY = 'mailbox_active';
export const UNIFIED = '__all__';

function loadAccounts() {
    try {
        return JSON.parse(localStorage.getItem(ACCOUNTS_KEY)) || [];
    } catch (_) {
        return [];
    }
}

/**
 * Multi-account auth store — Outlook-style. Every logged-in mailbox is one entry
 * in `accounts` (email + its own bearer token). `active` is either a specific
 * email or the special UNIFIED value for the combined "All accounts" view.
 */
export const useAuth = defineStore('auth', {
    state: () => ({
        accounts: loadAccounts(), // [{ email, token, display_name, unread }]
        active: localStorage.getItem(ACTIVE_KEY) || null,
    }),

    getters: {
        isAuthenticated: (s) => s.accounts.length > 0,
        isUnified: (s) => s.active === UNIFIED,
        current: (s) => s.accounts.find((a) => a.email === s.active) || s.accounts[0] || null,
        totalUnread: (s) => s.accounts.reduce((n, a) => n + (a.unread || 0), 0),
    },

    actions: {
        persist() {
            localStorage.setItem(ACCOUNTS_KEY, JSON.stringify(this.accounts));
            if (this.active) {
                localStorage.setItem(ACTIVE_KEY, this.active);
            }
        },

        /** Log into a mailbox and add (or refresh) it in the account list. */
        async login(email, password) {
            const { data } = await apiFor(null).post('/login', { email, password });
            const acc = {
                email: data.mailbox.email,
                token: data.token,
                display_name: data.mailbox.display_name,
                unread: data.mailbox.unread || 0,
            };

            const i = this.accounts.findIndex((a) => a.email === acc.email);
            if (i >= 0) {
                this.accounts[i] = acc;
            } else {
                this.accounts.push(acc);
            }
            this.active = acc.email;
            this.persist();
        },

        setActive(emailOrAll) {
            this.active = emailOrAll;
            localStorage.setItem(ACTIVE_KEY, emailOrAll);
        },

        accountByEmail(email) {
            return this.accounts.find((a) => a.email === email) || null;
        },

        /** An axios instance for a specific account (defaults to the active one). */
        api(email = null) {
            const acc = email ? this.accountByEmail(email) : this.current;
            return apiFor(acc?.token);
        },

        /** The list of accounts to query for a folder view (all when unified). */
        scope() {
            if (this.isUnified) {
                return this.accounts;
            }
            return this.current ? [this.current] : [];
        },

        async refreshUnread() {
            await Promise.all(
                this.accounts.map(async (a) => {
                    try {
                        const { data } = await apiFor(a.token).get('/me');
                        a.unread = data.mailbox.unread || 0;
                        a.display_name = data.mailbox.display_name;
                    } catch (e) {
                        if (e.response?.status === 401) {
                            this.removeByEmail(a.email);
                        }
                    }
                }),
            );
            this.persist();
        },

        removeByEmail(email) {
            this.accounts = this.accounts.filter((a) => a.email !== email);
            if (this.active === email) {
                this.active = this.accounts[0]?.email || null;
            }
            this.persist();
        },

        async logout(email = null) {
            const target = email || this.active;
            const acc = this.accountByEmail(target);
            if (acc) {
                try {
                    await apiFor(acc.token).post('/logout');
                } catch (_) {
                    // ignore network/401
                }
                this.removeByEmail(target);
            }
        },

        async logoutAll() {
            await Promise.all(
                this.accounts.map((a) => apiFor(a.token).post('/logout').catch(() => {})),
            );
            this.accounts = [];
            this.active = null;
            localStorage.removeItem(ACCOUNTS_KEY);
            localStorage.removeItem(ACTIVE_KEY);
        },
    },
});
