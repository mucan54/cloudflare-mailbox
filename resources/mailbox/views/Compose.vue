<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuth();

const from = ref(route.query.acc || auth.current?.email || '');
const to = ref([]);
const cc = ref([]);
const bcc = ref([]);
const toInput = ref('');
const ccInput = ref('');
const bccInput = ref('');
const subject = ref('');
const body = ref('');
const showCc = ref(false);
const busy = ref(false);
const error = ref('');
const inReplyTo = ref(null);

function addChip(list, inputRef) {
    const raw = inputRef.value.trim().replace(/[,;]+$/, '');
    if (!raw) return;
    raw.split(/[,;\s]+/).filter(Boolean).forEach((v) => {
        if (!list.value.includes(v)) list.value.push(v);
    });
    inputRef.value = '';
}
function removeChip(list, i) {
    list.value.splice(i, 1);
}
function onBackspace(list, inputRef) {
    if (!inputRef.value && list.value.length) list.value.pop();
}
function onKey(e, list, inputRef) {
    if (e.key === ',' || e.key === ';') {
        e.preventDefault();
        addChip(list, inputRef);
    }
}

function stripPrefix(s) {
    return (s || '').replace(/^(re|fwd|fw)\s*:\s*/i, '').trim();
}
function quote(o) {
    const when = o.received_at ? new Date(o.received_at).toLocaleString('tr-TR') : '';
    return `\n\n----- Orijinal ileti -----\nKimden: ${o.from_name || ''} <${o.from_email || ''}>\nTarih: ${when}\nKonu: ${o.subject || ''}\n\n`
        + (o.text_body || (o.html_body ? o.html_body.replace(/<[^>]+>/g, '') : ''));
}

onMounted(async () => {
    const { mode, src, acc, type } = route.query;
    if (!mode || !src) return;
    let orig;
    try {
        const { data } = await auth.api(acc).get(type === 'sent' ? `/sent/${src}` : `/emails/${src}`);
        orig = data.email;
    } catch (_) {
        return;
    }
    from.value = acc || from.value;
    inReplyTo.value = type === 'sent' ? null : Number(src);

    if (mode === 'reply' || mode === 'replyAll') {
        if (orig.from_email) to.value = [orig.from_email];
        subject.value = `Re: ${stripPrefix(orig.subject)}`;
        if (mode === 'replyAll' && orig.cc?.length) {
            cc.value = orig.cc.filter((a) => a !== from.value);
            showCc.value = cc.value.length > 0;
        }
        body.value = quote(orig);
    } else if (mode === 'forward') {
        subject.value = `Fwd: ${stripPrefix(orig.subject)}`;
        body.value = quote(orig);
    }
});

async function send() {
    addChip(to, toInput);
    addChip(cc, ccInput);
    addChip(bcc, bccInput);
    error.value = '';
    if (!to.value.length) {
        error.value = 'En az bir alıcı girin.';
        return;
    }
    busy.value = true;
    try {
        await auth.api(from.value).post('/send', {
            to: to.value,
            cc: cc.value,
            bcc: bcc.value,
            subject: subject.value || '(konu yok)',
            html: `<div>${body.value.replace(/\n/g, '<br>')}</div>`,
            text: body.value,
            in_reply_to_email_id: inReplyTo.value,
        });
        router.push('/f/sent');
    } catch (e) {
        error.value = e.response?.data?.message || 'Gönderilemedi.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="cp">
        <header class="rd-bar">
            <button class="ghost-ic" @click="router.back()">
                <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            </button>
            <h1 class="rd-title">Yeni ileti</h1>
            <button class="send-btn" :disabled="busy" @click="send">
                <svg viewBox="0 0 24 24"><path d="M21 4L3 11l6 2.5L11 20l3-5 7-11Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                {{ busy ? '…' : 'Gönder' }}
            </button>
        </header>

        <div class="cp-form">
            <label class="cp-field">
                <span class="cp-lab">Kimden</span>
                <select v-model="from" class="cp-sel">
                    <option v-for="a in auth.accounts" :key="a.email" :value="a.email">
                        {{ a.display_name ? a.display_name + ' — ' : '' }}{{ a.email }}
                    </option>
                </select>
            </label>

            <div class="cp-field">
                <span class="cp-lab">Kime</span>
                <div class="chips">
                    <span v-for="(c, i) in to" :key="c" class="chip-tag">{{ c }} <button @click="removeChip(to, i)">×</button></span>
                    <input
                        v-model="toInput"
                        class="chip-in"
                        type="text"
                        placeholder="ornek@site.com"
                        @keydown.enter.prevent="addChip(to, toInput)"
                        @keydown="onKey($event, to, toInput)"
                        @keydown.delete="onBackspace(to, toInput)"
                        @blur="addChip(to, toInput)"
                    />
                </div>
                <button class="cp-ccbtn" @click="showCc = !showCc">Cc/Bcc</button>
            </div>

            <template v-if="showCc">
                <div class="cp-field">
                    <span class="cp-lab">Cc</span>
                    <div class="chips">
                        <span v-for="(c, i) in cc" :key="c" class="chip-tag">{{ c }} <button @click="removeChip(cc, i)">×</button></span>
                        <input v-model="ccInput" class="chip-in" type="text" placeholder="Cc" @keydown.enter.prevent="addChip(cc, ccInput)" @keydown.delete="onBackspace(cc, ccInput)" @blur="addChip(cc, ccInput)" />
                    </div>
                </div>
                <div class="cp-field">
                    <span class="cp-lab">Bcc</span>
                    <div class="chips">
                        <span v-for="(c, i) in bcc" :key="c" class="chip-tag">{{ c }} <button @click="removeChip(bcc, i)">×</button></span>
                        <input v-model="bccInput" class="chip-in" type="text" placeholder="Bcc" @keydown.enter.prevent="addChip(bcc, bccInput)" @keydown.delete="onBackspace(bcc, bccInput)" @blur="addChip(bcc, bccInput)" />
                    </div>
                </div>
            </template>

            <label class="cp-field">
                <span class="cp-lab">Konu</span>
                <input v-model="subject" class="cp-sel" type="text" placeholder="Konu" />
            </label>

            <textarea v-model="body" class="cp-body" placeholder="Mesajınızı yazın…" />
            <p v-if="error" class="error">{{ error }}</p>
        </div>
    </div>
</template>
