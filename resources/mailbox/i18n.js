import { reactive } from 'vue';
import tr from './locales/tr';
import en from './locales/en';

const messages = { tr, en };
export const AVAILABLE = [
    { code: 'tr', label: 'Türkçe' },
    { code: 'en', label: 'English' },
];

function detect() {
    const saved = localStorage.getItem('ui_locale');
    if (saved && messages[saved]) return saved;
    const nav = (navigator.language || navigator.userLanguage || 'en').slice(0, 2).toLowerCase();
    return messages[nav] ? nav : 'en';
}

export const i18n = reactive({ locale: detect() });

export function setLocale(code) {
    if (!messages[code]) return;
    i18n.locale = code;
    localStorage.setItem('ui_locale', code);
    document.documentElement.lang = code;
}

/** Translate a dotted key with optional {param} interpolation. */
export function t(key, params) {
    const dict = messages[i18n.locale] || messages.en;
    let s = key.split('.').reduce((o, k) => (o == null ? o : o[k]), dict);
    if (s == null) s = key;
    if (params) {
        for (const p in params) s = String(s).replaceAll(`{${p}}`, params[p]);
    }
    return s;
}

/** BCP-47 locale for Intl/toLocale* formatting. */
export function localeTag() {
    return i18n.locale === 'tr' ? 'tr-TR' : 'en-US';
}

document.documentElement.lang = i18n.locale;
