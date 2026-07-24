<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const auth = useAuth();

const from = ref(route.query.acc || auth.current?.email || '');
const to = ref('');
const cc = ref('');
const bcc = ref('');
const subject = ref('');
const body = ref('');
const showCc = ref(false);
const showBcc = ref(false);
const busy = ref(false);
const error = ref('');
const inReplyTo = ref(null);

function parseList(s) {
    return (s || '').split(/[,;\s]+/).filter(Boolean);
}

function stripPrefix(subj) {
    return (subj || '').replace(/^(re|fwd|fw)\s*:\s*/i, '').trim();
}

function quote(orig) {
    const when = orig.received_at ? new Date(orig.received_at).toLocaleString() : '';
    return (
        `\n\n----- Orijinal ileti -----\n` +
        `Kimden: ${orig.from_name || ''} <${orig.from_email || ''}>\n` +
        `Tarih: ${when}\n` +
        `Konu: ${orig.subject || ''}\n\n` +
        (orig.text_body || (orig.html_body ? orig.html_body.replace(/<[^>]+>/g, '') : ''))
    );
}

onMounted(async () => {
    const { mode, src, acc, type } = route.query;
    if (!mode || !src) return;

    const path = type === 'sent' ? `/sent/${src}` : `/emails/${src}`;
    let orig;
    try {
        const { data } = await auth.api(acc).get(path);
        orig = data.email;
    } catch (_) {
        return;
    }

    from.value = acc || from.value;
    inReplyTo.value = type === 'sent' ? null : Number(src);

    if (mode === 'reply' || mode === 'replyAll') {
        to.value = orig.from_email || '';
        subject.value = `Re: ${stripPrefix(orig.subject)}`;
        if (mode === 'replyAll' && orig.cc?.length) {
            cc.value = orig.cc.filter((a) => a !== from.value).join(', ');
            showCc.value = cc.value.length > 0;
        }
        body.value = quote(orig);
    } else if (mode === 'forward') {
        subject.value = `Fwd: ${stripPrefix(orig.subject)}`;
        body.value = quote(orig);
    }
});

async function send() {
    error.value = '';
    const recipients = parseList(to.value);
    if (!recipients.length) {
        error.value = 'En az bir alıcı girin.';
        return;
    }

    busy.value = true;
    try {
        await auth.api(from.value).post('/send', {
            to: recipients,
            cc: parseList(cc.value),
            bcc: parseList(bcc.value),
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
    <div class="topbar">
        <button class="icon-btn" @click="router.back()">✕</button>
        <h1 class="topbar-title">Yeni ileti</h1>
        <button class="btn send" :disabled="busy" @click="send">{{ busy ? '…' : 'Gönder' }}</button>
    </div>

    <div class="compose">
        <label class="field">
            <span class="flabel">Kimden</span>
            <select v-model="from" class="finput">
                <option v-for="a in auth.accounts" :key="a.email" :value="a.email">
                    {{ a.display_name ? a.display_name + ' — ' : '' }}{{ a.email }}
                </option>
            </select>
        </label>

        <label class="field">
            <span class="flabel">Kime</span>
            <input v-model="to" class="finput" type="text" placeholder="ornek@site.com, ..." />
            <span class="field-toggles">
                <a @click="showCc = !showCc">Cc</a>
                <a @click="showBcc = !showBcc">Bcc</a>
            </span>
        </label>

        <label v-if="showCc" class="field">
            <span class="flabel">Cc</span>
            <input v-model="cc" class="finput" type="text" placeholder="Cc alıcıları" />
        </label>

        <label v-if="showBcc" class="field">
            <span class="flabel">Bcc</span>
            <input v-model="bcc" class="finput" type="text" placeholder="Bcc alıcıları" />
        </label>

        <label class="field">
            <span class="flabel">Konu</span>
            <input v-model="subject" class="finput" type="text" placeholder="Konu" />
        </label>

        <textarea v-model="body" class="compose-body" placeholder="Mesajınızı yazın…" />

        <p v-if="error" class="error">{{ error }}</p>
    </div>
</template>
