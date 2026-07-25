<script setup>
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { useUi } from '../stores/ui';
import { t } from '../i18n';
import { requestAndSubscribe, enablePushIfGranted, notificationPermission, pushSupported } from '../push';

const auth = useAuth();
const ui = useUi();
const router = useRouter();
const route = useRoute();

const email = ref(route.query.acc || auth.current?.email || '');
const displayName = ref('');
const signature = ref('');
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
}
watch(email, loadAccount, { immediate: true });

async function saveProfile() {
    busy.value = true;
    try {
        await auth.updateProfile(email.value, { display_name: displayName.value, signature: signature.value });
        ui.toast(t('profile.saved'));
    } finally {
        busy.value = false;
    }
}

// ----- push notifications health check -----
const testBusy = ref(false);
async function testPush() {
    testBusy.value = true;
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
        else if (data.sent > 0) ui.toast(t('profile.testSent', { n: data.sent }));
        else ui.toast(t('profile.testFailed'));
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
                <label class="fld">
                    <span>{{ t('profile.displayName') }}</span>
                    <input v-model="displayName" type="text" :placeholder="t('profile.displayNamePh')" />
                </label>
                <label class="fld">
                    <span>{{ t('profile.signature') }}</span>
                    <textarea v-model="signature" rows="5" class="set-area" :placeholder="t('profile.signaturePh')" />
                </label>
                <div class="settings-actions">
                    <button class="btn" :disabled="busy" @click="saveProfile">{{ t('profile.save') }}</button>
                </div>
            </section>

            <section v-if="pushSupported()" class="card">
                <h2 class="card-title">{{ t('profile.notifications') }}</h2>
                <p class="card-sub">{{ t('profile.notifHint') }}</p>
                <div class="settings-actions">
                    <button class="btn ghost" :disabled="testBusy" @click="testPush">{{ testBusy ? '…' : t('profile.testPush') }}</button>
                </div>
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
