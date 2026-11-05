import { reactive } from 'vue';

// Deterministic avatar initials + colour from a name or email — so every
// sender gets a stable, colourful monogram without storing anything.
const COLORS = [
    '#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444',
    '#ec4899', '#8b5cf6', '#14b8a6', '#f97316', '#3b82f6',
];

export function initials(nameOrEmail = '') {
    const s = (nameOrEmail || '').trim();
    if (!s) return '?';
    const base = s.includes('@') ? s.slice(0, s.indexOf('@')) : s;
    const parts = base.split(/[.\s_+-]+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return base.slice(0, 2).toUpperCase();
}

export function avatarColor(seed = '') {
    let h = 0;
    for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
    return COLORS[h % COLORS.length];
}

// Profile photos. A photo URL that failed to load (removed since the list was
// fetched, offline, …) is remembered so every avatar using it falls back to
// the monogram instead of showing a broken image.
const broken = reactive(new Set());

export function avatarSrc(url) {
    return url && !broken.has(url) ? url : null;
}

export function avatarFailed(url) {
    if (url) broken.add(url);
}

// A failure may only be the network waking up (e.g. a resumed PWA): retry the
// photos once it is back or the app is brought to the foreground again.
if (typeof window !== 'undefined') {
    window.addEventListener('online', () => broken.clear());
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') broken.clear();
    });
}

/**
 * Centre-crop a picked image to a square JPEG (≤ size px) in the browser, so a
 * 10 MB phone photo uploads as a few dozen KB. Browsers apply the EXIF
 * orientation when drawing, so portrait photos come out upright. Returns null
 * when the browser can't decode the file (the original is uploaded instead and
 * the server decides).
 */
export async function squareJpeg(file, size = 512) {
    const url = URL.createObjectURL(file);
    try {
        const img = await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = reject;
            i.src = url;
        });
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        const side = Math.min(w, h);
        if (!side) return null;
        const out = Math.min(size, side);
        const canvas = document.createElement('canvas');
        canvas.width = out;
        canvas.height = out;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff'; // JPEG has no transparency
        ctx.fillRect(0, 0, out, out);
        ctx.drawImage(img, (w - side) / 2, (h - side) / 2, side, side, 0, 0, out, out);
        return await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
    } catch (_) {
        return null;
    } finally {
        URL.revokeObjectURL(url);
    }
}
