<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { t } from '../i18n';
import { requestAndSubscribe, enablePushIfGranted, notificationPermission, pushSupported } from '../push';
import { initials, avatarColor, avatarSrc, avatarFailed, squareJpeg } from '../avatar';

const auth = useAuth();
const ui = useUi();
const router = useRouter();
const route = useRoute();

const email = ref(route.query.acc || auth.current?.email || '');
const displayName = ref('');
const signature = ref('');
const avatarInSignature = ref(false);
const busy = ref(false);

// password
const curPw = ref('');
const newPw = ref('');
const newPw2 = ref('');
const pwBusy = ref(false);
const pwError = ref('');

function loadAccount() {
    const a = auth.accountByEmail(email.value);
    displayName.value = a?.display_name || '';
    signature.value = a?.signature || '';
    avatarInSignature.value = !!a?.avatar_in_signature;
}
watch(email, loadAccount, { immediate: true });

async function saveProfile() {
    busy.value = true;
    try {
        await auth.updateProfile(email.value, {
            display_name: displayName.value,
            signature: signature.value,
            avatar_in_signature: avatarInSignature.value,
        });
        ui.toast(t('profile.saved'));
    } finally {
        busy.value = false;
    }
}

// ----- profile photo -----
const account = computed(() => auth.accountByEmail(email.value));
const photoUrl = computed(() => avatarSrc(account.value?.avatar_url));
const photoInput = ref(null);
const photoBusy = ref(false);
const photoError = ref('');
function pickPhoto() {
    photoInput.value?.click();
}
async function onPhoto(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    photoError.value = '';
    photoBusy.value = true;
    try {
        // Square-crop + shrink in the browser; fall back to the original file.
        const blob = (await squareJpeg(file)) || file;
        await auth.uploadAvatar(email.value, blob);
        ui.toast(t('profile.photoSaved'));
    } catch (err) {
        photoError.value = err.response?.data?.errors?.avatar?.[0] || err.response?.data?.message || t('profile.photoFailed');
    } finally {
        photoBusy.value = false;
    }
}
async function removePhoto() {
    photoError.value = '';
    photoBusy.value = true;
    try {
        await auth.removeAvatar(email.value);
        avatarInSignature.value = false;
        ui.toast(t('profile.photoRemoved'));
    } catch (_) {
        photoError.value = t('profile.photoFailed');
    } finally {
        photoBusy.value = false;
    }
}

// ----- calendar subscription feed -----
const feedUrl = computed(() => auth.accountByEmail(email.value)?.calendar_feed_url || '');
const webcalUrl = computed(() => feedUrl.value.replace(/^https?:\/\//, 'webcal://'));
function copyFeed() {
    const text = feedUrl.value;
    if (!text) return;
    const done = () => ui.toast(t('profile.feedCopied'));
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(() => {});
    } else {
        const el = document.createElement('textarea');
        el.value = text; document.body.appendChild(el); el.select();
        try { document.execCommand('copy'); done(); } catch (_) { /* ignore */ }
        document.body.removeChild(el);
    }
}

// ----- push notifications health check -----
const testBusy = ref(false);
const testError = ref('');
async function testPush() {
    testBusy.value = true;
    testError.value = '';
    try {
        const perm = notificationPermission();
        if (perm === 'default') {
            const ok = await requestAndSubscribe(auth.accounts);
            if (!ok) { ui.toast(t('profile.notifBlocked')); return; }
        } else if (perm === 'granted') {
            await enablePushIfGranted(auth.accounts);
        } else {
            ui.toast(t('profile.notifBlocked'));
            return;
        }
        const { data } = await auth.api(email.value).post('/push-test');
        if (data.reason === 'no_subscriptions') ui.toast(t('profile.testNoSub'));
        else if (data.reason === 'vapid_not_configured') ui.toast(t('profile.testNoVapid'));
        else if (data.reason === 'exception') { testError.value = data.message; ui.toast(t('profile.testFailed')); }
        else if (data.sent > 0) ui.toast(t('profile.testSent', { n: data.sent }));
        else { testError.value = (data.errors && data.errors.join(' · ')) || ''; ui.toast(t('profile.testFailed')); }
    } catch (_) {
        ui.toast(t('profile.testFailed'));
    } finally {
        testBusy.value = false;
    }
}

async function changePassword() {
    pwError.value = '';
    if (newPw.value !== newPw2.value) {
        pwError.value = t('profile.pwMismatch');
        return;
    }
    pwBusy.value = true;
    try {
        await auth.changePassword(email.value, {
            current_password: curPw.value, password: newPw.value, password_confirmation: newPw2.value,
        });
        curPw.value = newPw.value = newPw2.value = '';
        ui.toast(t('profile.pwChanged'));
    } catch (e) {
        pwError.value = e.response?.data?.message || e.response?.data?.errors?.current_password?.[0] || '—';
    } finally {
        pwBusy.value = false;
    }
}
</script>

<template>
    <div class="settings">
        <header class="rd-bar">
            <button class="ghost-ic" @click="router.back()">
                <svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <h1 class="rd-title">{{ t('profile.title') }}</h1>
        </header>

        <div class="settings-body">
            <section class="card">
                <label v-if="auth.accounts.length > 1" class="fld">
                    <span>{{ t('profile.account') }}</span>
                    <select v-model="email">
                        <option v-for="a in auth.accounts" :key="a.email" :value="a.email">{{ a.email }}</option>
                    </select>
                </label>
                <div class="set-photo">
                    <span class="ava set-photo-ava" :style="{ background: avatarColor(email) }">
                        <img v-if="photoUrl" :src="photoUrl" alt="" class="ava-img" @error="avatarFailed(photoUrl)" />
                        <template v-else>{{ initials(displayName || email) }}</template>
                    </span>
                    <div class="set-photo-meta">
                        <span class="set-photo-title">{{ t('profile.photo') }}</span>
                        <span class="set-photo-hint">{{ t('profile.photoHint') }}</span>
                        <div class="set-photo-actions">
                            <button class="btn ghost" :disabled="photoBusy" @click="pickPhoto">
                                {{ photoBusy ? '…' : (account?.avatar_url ? t('profile.photoChange') : t('profile.photoUpload')) }}
                            </button>
                            <button v-if="account?.avatar_url" class="btn danger" :disabled="photoBusy" @click="removePhoto">{{ t('profile.photoRemove') }}</button>
                        </div>
                    </div>
                    <input ref="photoInput" type="file" accept="image/*" class="cp-file" @change="onPhoto" />
                </div>
                <p v-if="photoError" class="error">{{ photoError }}</p>
                <label class="fld">
                    <span>{{ t('profile.displayName') }}</span>
                    <input v-model="displayName" type="text" :placeholder="t('profile.displayNamePh')" />
                </label>
                <label class="fld">
                    <span>{{ t('profile.signature') }}</span>
                    <textarea v-model="signature" rows="5" class="set-area" :placeholder="t('profile.signaturePh')" />
                </label>
                <label class="set-check" :class="{ disabled: !account?.avatar_url }">
                    <input v-model="avatarInSignature" type="checkbox" :disabled="!account?.avatar_url" />
                    <span>
                        {{ t('profile.photoInSignature') }}
                        <small>{{ account?.avatar_url ? t('profile.photoInSignatureHint') : t('profile.photoInSignatureNeedsPhoto') }}</small>
                    </span>
                </label>
                <div class="settings-actions">
                    <button class="btn" :disabled="busy" @click="saveProfile">{{ t('profile.save') }}</button>
                </div>
            </section>

            <section v-if="feedUrl" class="card">
                <h2 class="card-title">{{ t('profile.calendarSync') }}</h2>
                <p class="card-sub">{{ t('profile.calendarSyncHint') }}</p>
                <code class="set-feed">{{ feedUrl }}</code>
                <div class="settings-actions">
                    <a class="btn" :href="webcalUrl">{{ t('profile.calendarSubscribe') }}</a>
                    <button class="btn ghost" @click="copyFeed">{{ t('profile.copyLink') }}</button>
                </div>
            </section>

            <section v-if="pushSupported()" class="card">
                <h2 class="card-title">{{ t('profile.notifications') }}</h2>
                <p class="card-sub">{{ t('profile.notifHint') }}</p>
                <div class="settings-actions">
                    <button class="btn ghost" :disabled="testBusy" @click="testPush">{{ testBusy ? '…' : t('profile.testPush') }}</button>
                </div>
                <p v-if="testError" class="error" style="margin-top:8px; word-break:break-word">{{ testError }}</p>
            </section>

            <section class="card">
                <h2 class="card-title">{{ t('profile.password') }}</h2>
                <label class="fld"><span>{{ t('profile.currentPassword') }}</span><input v-model="curPw" type="password" autocomplete="current-password" /></label>
                <label class="fld"><span>{{ t('profile.newPassword') }}</span><input v-model="newPw" type="password" autocomplete="new-password" /></label>
                <label class="fld"><span>{{ t('profile.confirmPassword') }}</span><input v-model="newPw2" type="password" autocomplete="new-password" /></label>
                <p v-if="pwError" class="error">{{ pwError }}</p>
                <div class="settings-actions">
                    <button class="btn ghost" :disabled="pwBusy || !curPw || !newPw" @click="changePassword">{{ t('profile.changePassword') }}</button>
                </div>
            </section>
        </div>
    </div>
</template>
