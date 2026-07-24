import api from './api';

/**
 * Subscribe this device to Web Push. Only proceeds when notification permission
 * is already granted (a real "enable notifications" button should call
 * requestAndSubscribe on a user gesture — required on iOS installed PWAs).
 */
export async function enablePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (Notification.permission !== 'granted') return;
    await subscribe();
}

export async function requestAndSubscribe() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return false;
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return false;
    await subscribe();
    return true;
}

async function subscribe() {
    const key = document.querySelector('meta[name="vapid-key"]')?.content;
    if (!key) return;

    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) {
        sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(key),
        });
    }

    const json = sub.toJSON();
    await api.post('/push-subscribe', {
        endpoint: sub.endpoint,
        keys: json.keys,
        contentEncoding: 'aesgcm',
    });
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; ++i) out[i] = raw.charCodeAt(i);
    return out;
}
