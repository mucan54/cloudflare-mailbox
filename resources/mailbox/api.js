import axios from 'axios';

const ACCOUNTS_KEY = 'mailbox_accounts';
const ACTIVE_KEY = 'mailbox_active';

/**
 * Read the currently active account's token straight from localStorage. Used by
 * the default `api` instance (push subscriptions, etc.) so it always follows the
 * account the user is looking at without importing the pinia store here.
 */
export function activeToken() {
    try {
        const accounts = JSON.parse(localStorage.getItem(ACCOUNTS_KEY)) || [];
        const active = localStorage.getItem(ACTIVE_KEY);
        const acc = accounts.find((a) => a.email === active) || accounts[0];
        return acc?.token || null;
    } catch (_) {
        return null;
    }
}

/**
 * Build an axios instance bound to a specific bearer token. This is the core of
 * multi-account support: every account keeps its own token and we make calls
 * with the token that owns the mailbox in question.
 */
export function apiFor(token) {
    const headers = { Accept: 'application/json' };
    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    return axios.create({ baseURL: '/api/mailbox', headers });
}

// Default instance that always follows the active account (dynamic token).
const api = axios.create({
    baseURL: '/api/mailbox',
    headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
    const token = activeToken();
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

export default api;
