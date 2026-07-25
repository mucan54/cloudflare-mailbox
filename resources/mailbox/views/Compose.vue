<script setup>
import { ref, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { t, localeTag } from '../i18n';

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

// Chip helpers are keyed by field name ('to'/'cc'/'bcc'). Passing the ref
// itself through a template expression doesn't work: Vue auto-unwraps refs in
// templates, so the handler would receive the plain array and `.value` would be
// undefined — which is why chip removal silently did nothing.
const lists = { to, cc, bcc };
const inputs = { to: toInput, cc: ccInput, bcc: bccInput };

function addChip(field) {
    const list = lists[field];
    const inputRef = inputs[field];
    const raw = inputRef.value.trim().replace(/[,;]+$/, '');
    if (!raw) return;
    raw.split(/[,;\s]+/).filter(Boolean).forEach((v) => {
        if (!list.value.includes(v)) list.value.push(v);
    });
    inputRef.value = '';
}
function removeChip(field, i) {
    lists[field].value.splice(i, 1);
}
function onBackspace(field) {
    if (!inputs[field].value && lists[field].value.length) lists[field].value.pop();
}
function onKey(e, field) {
    if (e.key === ',' || e.key === ';') {
        e.preventDefault();
        addChip(field);
    }
}

// ----- recipient autocomplete (address book) -----
const suggestions = ref([]);
const sugFor = ref(null);
const sugIndex = ref(-1);
let sugTimer = null;

function queryRecipients(field) {
    sugFor.value = field;
    sugIndex.value = -1;
    const text = inputs[field].value.trim();
    clearTimeout(sugTimer);
    sugTimer = setTimeout(async () => {
        try {
            const { data } = await auth.api(from.value).get('/recipients', { params: { q: text } });
            const taken = new Set([...to.value, ...cc.value, ...bcc.value].map((x) => x.toLowerCase()));
            suggestions.value = (data.data ?? []).filter((s) => !taken.has(String(s.email).toLowerCase()));
        } catch (_) {
            suggestions.value = [];
        }
    }, 160);
}
function pickSug(field, s) {
    const list = lists[field];
    if (!list.value.includes(s.email)) list.value.push(s.email);
    inputs[field].value = '';
    suggestions.value = [];
    sugFor.value = null;
}
function sugNav(field, dir, e) {
    if (sugFor.value !== field || !suggestions.value.length) return;
    e.preventDefault();
    sugIndex.value = (sugIndex.value + dir + suggestions.value.length) % suggestions.value.length;
}
function onEnter(field) {
    if (sugFor.value === field && sugIndex.value >= 0 && suggestions.value[sugIndex.value]) {
        pickSug(field, suggestions.value[sugIndex.value]);
    } else {
        addChip(field);
        suggestions.value = [];
    }
}
function hideSug() {
    setTimeout(() => { suggestions.value = []; sugFor.value = null; }, 150);
}

function stripPrefix(s) {
    return (s || '').replace(/^(re|fwd|fw)\s*:\s*/i, '').trim();
}
function quote(o) {
    const when = o.received_at ? new Date(o.received_at).toLocaleString(localeTag()) : '';
    return `\n\n----- ${t('compose.origMessage')} -----\n${t('compose.from2')}: ${o.from_name || ''} <${o.from_email || ''}>\n${t('compose.date')}: ${when}\n${t('mail.message')}: ${o.subject || ''}\n\n`
        + (o.text_body || (o.html_body ? o.html_body.replace(/<[^>]+>/g, '') : ''));
}

function sigBlock(email) {
    const s = auth.accountByEmail(email)?.signature || '';
    return s ? `\n\n${s}` : '';
}

onMounted(async () => {
    const { mode, src, acc, type } = route.query;
    if (route.query.to) to.value = String(route.query.to).split(/[,;\s]+/).filter(Boolean);
    if (!mode || !src) {
        // brand new message → start with the sender's signature
        body.value = sigBlock(from.value);
        return;
    }
    let orig;
    try {
        const { data } = await auth.api(acc).get(type === 'sent' ? `/sent/${src}` : `/emails/${src}`);
        orig = data.email;
    } catch (_) {
        return;
    }
    from.value = acc || from.value;
    inReplyTo.value = type === 'sent' ? null : Number(src);
    const sig = sigBlock(from.value);

    if (mode === 'reply' || mode === 'replyAll') {
        if (orig.from_email) to.value = [orig.from_email];
        subject.value = `Re: ${stripPrefix(orig.subject)}`;
        if (mode === 'replyAll' && orig.cc?.length) {
            cc.value = orig.cc.filter((a) => a !== from.value);
            showCc.value = cc.value.length > 0;
        }
        body.value = sig + quote(orig);
    } else if (mode === 'forward') {
        subject.value = `Fwd: ${stripPrefix(orig.subject)}`;
        body.value = sig + quote(orig);
    }
});

async function send() {
    addChip('to');
    addChip('cc');
    addChip('bcc');
    error.value = '';
    if (!to.value.length) {
        error.value = t('compose.needRecipient');
        return;
    }
    busy.value = true;
    try {
        await auth.api(from.value).post('/send', {
            to: to.value,
            cc: cc.value,
            bcc: bcc.value,
            subject: subject.value || t('mail.noSubject'),
            html: `<div>${body.value.replace(/\n/g, '<br>')}</div>`,
            text: body.value,
            in_reply_to_email_id: inReplyTo.value,
        });
        router.push('/f/sent');
    } catch (e) {
        error.value = e.response?.data?.message || t('compose.failed');
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
            <h1 class="rd-title">{{ t('compose.title') }}</h1>
            <button class="send-btn" :disabled="busy" @click="send">
                <svg viewBox="0 0 24 24"><path d="M21 4L3 11l6 2.5L11 20l3-5 7-11Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                {{ busy ? '…' : t('compose.send') }}
            </button>
        </header>

        <div class="cp-form">
            <label class="cp-field">
                <span class="cp-lab">{{ t('compose.from') }}</span>
                <select v-model="from" class="cp-sel">
                    <option v-for="a in auth.accounts" :key="a.email" :value="a.email">
                        {{ a.display_name ? a.display_name + ' — ' : '' }}{{ a.email }}
                    </option>
                </select>
            </label>

            <div class="cp-field">
                <span class="cp-lab">{{ t('compose.to') }}</span>
                <div class="chips">
                    <span v-for="(c, i) in to" :key="c" class="chip-tag">{{ c }} <button type="button" @pointerdown.prevent="removeChip('to', i)">×</button></span>
                    <input
                        v-model="toInput"
                        class="chip-in"
                        type="text"
                        inputmode="email"
                        autocapitalize="none"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="t('compose.recipientPh')"
                        @input="queryRecipients('to')"
                        @focus="queryRecipients('to')"
                        @keydown.enter.prevent="onEnter('to')"
                        @keydown.down="sugNav('to', 1, $event)"
                        @keydown.up="sugNav('to', -1, $event)"
                        @keydown="onKey($event, 'to')"
                        @keydown.delete="onBackspace('to')"
                        @blur="addChip('to'); hideSug()"
                    />
                    <div v-if="sugFor === 'to' && suggestions.length" class="sug">
                        <button v-for="(s, i) in suggestions" :key="s.email" class="sug-item" :class="{ on: i === sugIndex }"
                                @mousedown.prevent="pickSug('to', s)" @mouseenter="sugIndex = i">
                            <span class="sug-name">{{ s.name }}</span>
                            <span v-if="s.name !== s.email" class="sug-mail">{{ s.email }}</span>
                        </button>
                    </div>
                </div>
                <button class="cp-ccbtn" @click="showCc = !showCc">{{ t('compose.ccbcc') }}</button>
            </div>

            <template v-if="showCc">
                <div class="cp-field">
                    <span class="cp-lab">Cc</span>
                    <div class="chips">
                        <span v-for="(c, i) in cc" :key="c" class="chip-tag">{{ c }} <button type="button" @pointerdown.prevent="removeChip('cc', i)">×</button></span>
                        <input v-model="ccInput" class="chip-in" type="text" inputmode="email" autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="Cc"
                               @input="queryRecipients('cc')" @focus="queryRecipients('cc')"
                               @keydown.enter.prevent="onEnter('cc')" @keydown.down="sugNav('cc', 1, $event)" @keydown.up="sugNav('cc', -1, $event)"
                               @keydown="onKey($event, 'cc')" @keydown.delete="onBackspace('cc')" @blur="addChip('cc'); hideSug()" />
                        <div v-if="sugFor === 'cc' && suggestions.length" class="sug">
                            <button v-for="(s, i) in suggestions" :key="s.email" class="sug-item" :class="{ on: i === sugIndex }" @mousedown.prevent="pickSug('cc', s)" @mouseenter="sugIndex = i">
                                <span class="sug-name">{{ s.name }}</span><span v-if="s.name !== s.email" class="sug-mail">{{ s.email }}</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="cp-field">
                    <span class="cp-lab">Bcc</span>
                    <div class="chips">
                        <span v-for="(c, i) in bcc" :key="c" class="chip-tag">{{ c }} <button type="button" @pointerdown.prevent="removeChip('bcc', i)">×</button></span>
                        <input v-model="bccInput" class="chip-in" type="text" inputmode="email" autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="Bcc"
                               @input="queryRecipients('bcc')" @focus="queryRecipients('bcc')"
                               @keydown.enter.prevent="onEnter('bcc')" @keydown.down="sugNav('bcc', 1, $event)" @keydown.up="sugNav('bcc', -1, $event)"
                               @keydown="onKey($event, 'bcc')" @keydown.delete="onBackspace('bcc')" @blur="addChip('bcc'); hideSug()" />
                        <div v-if="sugFor === 'bcc' && suggestions.length" class="sug">
                            <button v-for="(s, i) in suggestions" :key="s.email" class="sug-item" :class="{ on: i === sugIndex }" @mousedown.prevent="pickSug('bcc', s)" @mouseenter="sugIndex = i">
                                <span class="sug-name">{{ s.name }}</span><span v-if="s.name !== s.email" class="sug-mail">{{ s.email }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <label class="cp-field">
                <span class="cp-lab">{{ t('compose.subject') }}</span>
                <input v-model="subject" class="cp-sel" type="text" :placeholder="t('compose.subjectPh')" />
            </label>

            <textarea v-model="body" class="cp-body" :placeholder="t('compose.body')" />
            <p v-if="error" class="error">{{ error }}</p>
        </div>
    </div>
</template>
