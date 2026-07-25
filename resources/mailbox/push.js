import { apiFor } from './api';

/**
 * Web Push for the mailbox PWA. Notifications arrive even when the app is
 * closed (installed PWA) via the browser's push service — no persistent socket.
 *
 * Multi-account: one browser push subscription is registered under every
 * logged-in mailbox's token, so mail to any account triggers a notification.
 */

export function vapidKey() {
    return document.querySelector('meta[name="vapid-key"]')?.content || '';
}

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export function notificationPermission() {
    return pushSupported() ? Notification.permission : 'unsupported';
}

/** Prompt for permission (must run on a user gesture) then subscribe every account. */
export async function requestAndSubscribe(accounts) {
    if (!pushSupported() || !vapidKey()) return false;

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;

    await subscribeForAccounts(accounts);
    return true;
}

/** Subscribe silently only if permission was already granted (e.g. on load). */
export async function enablePushIfGranted(accounts) {
    if (!pushSupported() || !vapidKey()) return;
    if (Notification.permission !== 'granted') return;
    await subscribeForAccounts(accounts);
}

async function subscribeForAccounts(accounts) {
    const key = vapidKey();
    if (!key || !accounts?.length) return;

    const appKey = urlBase64ToUint8Array(key);
    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();

    // A subscription made with a different (rotated) VAPID key is dead: the
    // push service still accepts it locally but the server can never encrypt to
    // it, so notifications silently stop. Drop it and make a fresh one.
    if (sub && !subscriptionMatchesKey(sub, appKey)) {
        try {
            await sub.unsubscribe();
        } catch (_) {
            // ignore
        }
        sub = null;
    }

    if (!sub) {
        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: appKey,
        });
    }

    const json = sub.toJSON();
    // Must be aes128gcm (RFC 8291): Apple's iOS/Safari Web Push ONLY supports
    // this encoding and silently drops legacy "aesgcm" pushes — the reason
    // notifications never arrived on iOS. All current browsers support it.
    const body = { endpoint: sub.endpoint, keys: json.keys, contentEncoding: 'aes128gcm' };

    // Register the same endpoint under each account so any inbox can notify.
    await Promise.all(
        accounts.map((a) => apiFor(a.token).post('/push-subscribe', body).catch(() => {})),
    );
}

/** True when an existing subscription was created with `appKey`. */
function subscriptionMatchesKey(sub, appKey) {
    const cur = sub.options?.applicationServerKey;
    if (!cur) return true; // can't introspect — assume it's fine
    const a = new Uint8Array(cur);
    if (a.length !== appKey.length) return false;
    for (let i = 0; i < a.length; i++) {
        if (a[i] !== appKey[i]) return false;
    }
    return true;
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
    return out;
}
