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
