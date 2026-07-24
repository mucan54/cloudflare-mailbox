# Cloudflare Mailbox — Mimari & Yol Haritası

> Laravel 13 + Filament 5 tabanlı, Cloudflare **Email Service** (Sending + Routing)
> üzerine kurulu, tam teşekküllü bir web mail arayüzü. Bu belge; ürünün nasıl
> çalıştığını, mimariyi, veri modelini, gerekli paketleri ve faz faz uygulama
> planını (roadmap) tanımlar.

İçindekiler:

1. [Ürün gerçekleri: Cloudflare ne sağlar, ne sağlamaz](#1-ürün-gerçekleri)
2. [Yüksek seviye mimari](#2-yüksek-seviye-mimari)
3. [Cloudflare API uç kataloğu](#3-cloudflare-api-uç-kataloğu)
4. [Giden mail: REST API + SMTP (config'den seçilebilir)](#4-giden-mail)
5. [Gelen mail: Email Worker + webhook + depolama](#5-gelen-mail)
6. [Veri modeli (tablolar & migration'lar)](#6-veri-modeli)
7. [Konfigürasyon tasarımı](#7-konfigürasyon-tasarımı)
8. [Filament arayüz yapısı](#8-filament-arayüz-yapısı)
9. [Gerekli & önerilen paketler](#9-paketler)
10. [Güvenlik](#10-güvenlik)
11. [Test & CI](#11-test--ci)
12. [Yol haritası (fazlar)](#12-yol-haritası)
13. [Kararlar](#13-kararlar-netleşti)
14. [Dağıtım (Coolify)](#14-dağıtım-coolify)
15. [Gözden geçirme & iyileştirmeler](#15-gözden-geçirme--iyileştirmeler)

---

## 1. Ürün gerçekleri

Cloudflare, **Email Sending** ve **Email Routing**'i tek "Email Service" ürünü
altında birleştirdi. İki yönü vardır:

| Yön | Ürün | İşlev |
|-----|------|-------|
| Giden | Email Sending | Kendi domain'inden mail gönderir (REST API / SMTP / Worker binding) |
| Gelen | Email Routing | Gelen maili bir adrese **forward** eder veya bir **Worker'a** iletir |

**Kritik kısıtlar — mimariyi bunlar belirliyor:**

- **Cloudflare posta kutusu (mailbox) TUTMAZ.** IMAP/POP yok, "gelen kutusu API'si"
  yok. Gerçek bir inbox istiyorsak gelen maili **kendimiz yakalayıp depolamalıyız**
  → bunun tek yolu bir **Email Worker**'dır.
- Cloudflare'de "mevcut mail adresleri" dediğimiz şey aslında **routing rule**'lardır
  (ör. `support@domain.com → sen@gmail.com`). Şifreli gerçek posta kutusu yoktur;
  her adres bir yönlendirme kuralıdır. Bizim uygulamamız, "gerçek posta kutusu"
  deneyimini **kendi DB'sinde** kurar.
- **Domain Cloudflare DNS'te olmak zorundadır.**
- **Gönderme (Sending) ve Alma (Routing) onboarding'i AYRIDIR** — bir domain için iki
  bağımsız durum:
  - *Sending onboarding:* `cf-bounce` alt alanına MX + SPF + DKIM + DMARC eklenir →
    o domainden **mail gönderilebilir**.
  - *Routing onboarding:* domaine MX eklenir → o domaine **mail alınabilir**
    (forward/worker).
  - Tam mail servisi için **ikisi de** gerekir. Bir domain yalnız gönderme, yalnız
    alma veya ikisi birden aktif olabilir. UI her iki durumu ayrı gösterir; `domains`
    tablosu `sending_enabled` + `routing_enabled` ayrı tutar. Compose formu `from`
    olarak **yalnız sending_enabled** domain adreslerini sunar.
- **Giden mesaj boyutu (ekler dahil) 5 MiB'ı geçemez.**
- Hedef adresler **hesap seviyesinde** paylaşılır; routing kuralları **zone (domain)
  seviyesindedir**. Doğrulanmamış hedefe giden kural otomatik pasifleşir.

**Multi-tenancy:** Uygulama **çok kiracılı (multi-tenant)** kurgulanır. Her
**Cloudflare hesabı = bir tenant**; bu, Cloudflare'in kendi `account_id`-bazlı
kapsama modeliyle birebir örtüşür. Filament v5'in **yerleşik tenancy** desteği
kullanılır: tüm kaynaklar (domain, kural, adres, inbox, giden) aktif tenant'a
scope'lanır, panelde tenant switcher bulunur, kullanıcılar hesaplara pivot tablo
ile üye olur. Ayrıntı: [§8.2](#82-multi-tenancy-filament).

**İki ayrı uygulama yüzeyi:**
- **Admin (`/admin`)** — **Filament** (tenancy'li). Yöneticiler domain/kural/mailbox
  yönetir. Admin işi için Filament ideal.
- **Mailbox portalı** — **headless**: Laravel salt-JSON **mailbox API'si**
  (`mailbox` guard, **Sanctum**) + ayrı bir **Vue SPA** (PWA). Native mail-app
  hissiyatı için Filament kullanılmaz. Son kullanıcı email + şifre ile giriş yapar,
  yalnız kendi mail kutusunu kullanır (tenancy'den bağımsız; mail adresi global
  benzersiz). Her adres kendi başına login olur. Ayrıntı:
  [§8.1](#81-uygulama-yüzeyleri--kimlik) ve [§8.4](#84-mailbox-portalı-headless-spa--pwa).

---

## 2. Yüksek seviye mimari

```
┌──────────────────────────────────────────────────────────────────────┐
│                        Laravel + Filament (bu repo)                    │
│                                                                        │
│  Filament Panel                        Servis katmanı                  │
│  ├─ Domains (zone)                     ├─ CloudflareClient (Saloon)     │
│  ├─ Routing Rules (adresler)     ◄────►│   ├─ Zones                     │
│  ├─ Destination Addresses              │   ├─ Routing (rules/addresses) │
│  ├─ Inbox (gelen kutusu)               │   └─ Sending (send)            │
│  ├─ Compose / Sent (giden)       ────► │  Mailer (SMTP alternatifi)     │
│  ├─ Dashboard widget'ları              │                                │
│  └─ Settings (hesap/token)             └────────────────────────────┐   │
│                                                                     │   │
│  DB:  cloudflare_accounts, domains, routing_rules,                  │   │
│       destination_addresses, emails (inbox), sent_emails, attachments│  │
└─────────────────────────────────────────────────────────────────────┼──┘
                          ▲ imzalı webhook (HMAC)                       │ HTTPS
                          │  POST /api/cf/incoming                      │ Bearer token
                          │                                             ▼
              ┌───────────┴─────────────┐                 ┌──────────────────────────┐
              │  Cloudflare Email Worker │                 │  Cloudflare REST API       │
              │  cf/  (Laravel'den deploy)│                 │  api.cloudflare.com/client │
              │  email() { postal-mime } │◄── MX ── mail   │  /v4/accounts|zones/...     │
              └──────────────────────────┘   gelen         └──────────────────────────┘
```

**Akış özetleri:**

- **Giden:** Filament Compose formu → `SendEmail` job → `CloudflareClient::send()`
  (REST) **veya** `Mail::mailer('cloudflare')` (SMTP) → `sent_emails` log +
  delivered/queued/bounced durumu.
- **Gelen:** mail → Cloudflare MX → routing rule (**catch-all → Worker**, §5.0) →
  Email Worker ham MIME'yi `postal-mime` ile parse eder, küçük ekleri base64 gövdede /
  büyükleri R2'ye koyar, Laravel'e HMAC imzalı JSON POST atar → `StoreIncomingEmail`
  job (idempotent) → `emails` tablosu → **Web Push bildirimi** → **admin: Filament
  Inbox / son kullanıcı: Vue SPA (PWA)**.
- **Yönetim:** Filament Resource'ları `CloudflareClient` üzerinden Cloudflare'deki
  zone/rule/address kaynaklarını okur/yazar; yerel DB senkron kopya (cache) tutar.

---

## 3. Cloudflare API uç kataloğu

Taban URL: `https://api.cloudflare.com/client/v4`
Auth: `Authorization: Bearer <API_TOKEN>`

| Amaç | Method & Path | Kapsam |
|------|---------------|--------|
| Token doğrula (onboarding) | `GET /user/tokens/verify` | kullanıcı |
| İzin gruplarını listele (template key pinleme) | `GET /user/tokens/permission_groups` | kullanıcı |
| Hesapları listele (otomatik tespit) | `GET /accounts` | kullanıcı |
| Domainleri listele | `GET /zones` | hesap |
| Zone detay | `GET /zones/{zone_id}` | zone |
| Routing ayarları (aç/kapat/durum) | `GET·POST·PATCH·DELETE /zones/{zone_id}/email/routing` | zone |
| Hedef adres listele/oluştur | `GET·POST /accounts/{account_id}/email/routing/addresses` | **hesap** |
| Hedef adres getir/sil | `GET·DELETE /accounts/{account_id}/email/routing/addresses/{id}` | hesap |
| Routing kuralları listele/oluştur | `GET·POST /zones/{zone_id}/email/routing/rules` | zone |
| Kural getir/güncelle/sil | `GET·PUT·DELETE /zones/{zone_id}/email/routing/rules/{rule_id}` | zone |
| Catch-all kural | `GET·PUT /zones/{zone_id}/email/routing/rules/catch_all` | zone |
| **Mail gönder** | `POST /accounts/{account_id}/email/sending/send` | hesap |

**API Token scope'ları:** `Zone → Read`, `Email Routing → Edit`,
`Email Sending → Edit`. (SMTP için de aynı token kullanılır: user=`api_token`,
pass=`<token>`.)

### Send API şeması (REST)

`POST /accounts/{account_id}/email/sending/send`

```json
{
  "from": "welcome@domain.com",
  "to": "recipient@example.com",
  "cc": ["a@example.com"],
  "bcc": ["b@example.com"],
  "reply_to": "support@domain.com",
  "subject": "Konu",
  "html": "<h1>Merhaba</h1>",
  "text": "Merhaba",
  "headers": { "X-Entity": "..." },
  "attachments": [
    { "content": "<base64>", "filename": "fatura.pdf",
      "type": "application/pdf", "disposition": "attachment" }
  ]
}
```

Başarılı yanıt:

```json
{ "success": true, "errors": [], "messages": [],
  "result": { "delivered": ["..."], "permanent_bounces": [], "queued": ["..."] } }
```

Hata: `success: false`, `errors: [{ "code": 10001, "message": "email.sending.error.invalid_request_schema" }]`
(kodlar 10001–10203). **Toplam boyut (ekler dahil) ≤ 5 MiB.**

### SMTP

- Sunucu: `smtps://smtp.mx.cloudflare.net:465`
- Kullanıcı: `api_token`  ·  Şifre: `<API_TOKEN>`  ·  TLS (465).

---

## 4. Giden mail

Her iki yöntem de desteklenir, `config/cloudflare.php` içindeki
`sending.driver` (`api` | `smtp`) ile seçilir.

- **REST API (varsayılan/önerilen):** `delivered/queued/permanent_bounces` durumunu
  senkron döner → Filament'te durum rozetleri gösterilebilir. Ekler base64 ile
  gönderilir.
- **SMTP:** `config/mail.php` içine `cloudflare` adında bir mailer eklenir;
  Laravel'in yerleşik `Mail::`/`Mailable` ekosistemiyle çalışır (kuyruk,
  markdown mailable vb.). Ekstra paket yok. Durum takibi zayıftır (sadece
  SMTP kabul/hata).

Uygulama içinde tek bir `MailSender` arayüzü; iki implementasyon
(`ApiMailSender`, `SmtpMailSender`) config'e göre bağlanır. Her gönderim
`sent_emails` tablosuna log'lanır.

---

## 5. Gelen mail

Cloudflare inbox tutmadığından, tam posta kutusu deneyimi şu zincirle kurulur:

### 5.0 Gelen yakalama stratejisi (catch-all → Worker)

**Tam mail servisi için önerilen varsayılan: domainin catch-all kuralını Worker'a
bağlamak.** Böylece o domaine gelen **her adres** (bilinen mailbox'lar + rastgele
adresler) tek Worker'dan yakalanır; Laravel `to_email` ile mailbox'a çözer.

- **`inbound_capture` modları** (`domains.inbound_capture`):
  - `catch_all` *(önerilen)* — domaine gelen her şey Worker'a; en az yönetim, tam
    kapsama. Bilinmeyen adres → `emails.mailbox_id = null` (admin inbox'ta görünür).
  - `per_address` — yalnız seçili adresler Worker'a (daha dar; her mailbox için ayrı
    "Send to Worker" kuralı). Belirli adresleri klasik forward'da bırakmak isteyen
    için.
  - `none` — bu domainde gelen yakalama kapalı.
- **Klasik forward'la birlikte:** İstenirse Worker aynı anda `message.forward()` ile
  eski yönlendirmeyi de sürdürür (hem inbox'a düşer hem eski adrese iletir).
- Deploy sırasında (`cf:deploy-worker`) seçilen moda göre catch-all veya per-address
  kuralları API ile kurulur.

### 5.1 Email Worker (`cf/` klasörü)

Worker kaynağı ve deploy araçları repoda **`cf/`** klasöründe tutulur (küçük bir
Wrangler projesi). Yapı:

```
cf/
├── package.json          # wrangler, postal-mime, mimetext
├── wrangler.toml.stub    # şablon — {{PLACEHOLDER}}'lar Laravel'den doldurulur
├── src/
│   └── inbound-email.js  # email() handler
├── .gitignore            # üretilen wrangler.toml, .dev.vars, node_modules
└── README.md             # manuel deploy notları
```

`src/inbound-email.js` — `email()` handler'ı (özet):

```js
import PostalMime from "postal-mime";

export default {
  async email(message, env, ctx) {
    const parsed = await PostalMime.parse(message.raw);   // MIME → yapısal
    const payload = {
      message_id: message.headers.get("Message-ID"),
      envelope_from: message.from,
      envelope_to: message.to,
      subject: parsed.subject, html: parsed.html, text: parsed.text,
      from: parsed.from, to: parsed.to, cc: parsed.cc, date: parsed.date,
      headers: [...message.headers],
      attachments: parsed.attachments.map(a => ({
        filename: a.filename, mimeType: a.mimeType, contentId: a.contentId,
        size: a.content.byteLength,
        content: btoa(String.fromCharCode(...new Uint8Array(a.content))), // base64
      })),
      raw_size: message.rawSize,
    };

    const body = JSON.stringify(payload);
    const ts = `${Date.now()}`;
    const sig = await hmacSha256(env.WEBHOOK_SECRET, `${ts}.${body}`); // timestamp+body imzalı
    try {
      const res = await fetch(env.WEBHOOK_URL, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CF-Account": env.ACCOUNT_ID,        // imzasız; Laravel hesabı bununla bulur
          "X-CF-Signature": sig,
          "X-CF-Timestamp": ts,
        },
        body,
      });
      if (!res.ok) throw new Error(`webhook ${res.status}`);
    } catch (e) {
      // Laravel düştüyse mail KAYBOLMASIN: throw etme, yedek adrese ilet
      if (env.FALLBACK_FORWARD_TO) await message.forward(env.FALLBACK_FORWARD_TO);
    }
    // opsiyonel: klasik forward da sürsün → await message.forward(env.FORWARD_TO);
  },
};
```

`ForwardableEmailMessage` arayüzü: `from`, `to`, `headers`, `raw` (stream),
`rawSize`, `canBeForwarded`, `setReject(reason)`, `forward(rcptTo, headers?)`,
`reply(EmailMessage)`. Reply/compose için `mimetext` + `nodejs_compat` flag'i.

**Ekler (boyut eşiği):** Küçük ekler (toplam < ~2–3 MiB, config'le ayarlanır) base64
ile webhook gövdesinde gönderilir; Laravel `attachments_disk`'e yazar. **Büyük ekler
webhook'u şişirmesin** diye eşik üstünde Worker eki doğrudan **R2 bucket binding** ile
R2'ye PUT eder, webhook'a yalnız key gönderir. (Email Routing mesajları ~25 MiB'a
kadar olabilir; base64 %33 şişirir + PHP `post_max_size`/`upload_max_filesize`
sınırları vardır — bu yüzden eşik şart.) R2 binding `wrangler.toml.stub`'da
opsiyonel/yorumlu; kapalıysa eşik üstü mail için Worker yalnız key'siz metadata +
"ek çok büyük" işareti gönderir (mail kaybolmaz).

**Güvenilirlik — mail asla kaybolmasın:** Worker webhook'a POST **başarısız olursa
`email()` handler'ı THROW ETMEZ** (throw → mail reddedilir/bounce olur). Bunun yerine:
(a) hata yakalanır ve `ctx.waitUntil` ile kısa retry denenir; (b) yine olmazsa
**fallback forward** (`env.FALLBACK_FORWARD_TO`) ile mail bir yedek adrese iletilir —
böylece Laravel geçici düşse bile mail kaybolmaz. Alternatif dayanıklılık: Cloudflare
**Queue/D1**'e yazıp sonra Laravel'e teslim (ileri opsiyon; `cf/README`'de belgelenir).

### 5.1.1 Laravel-güdümlü konfigürasyon & deploy

Worker ayarları **elle yazılmaz**; Laravel config/env tek kaynaktır. Bir Artisan
komutu şablonu doldurup deploy eder:

```
php artisan cf:deploy-worker {tenant?}      # tenant = cloudflare hesabı (slug/id)
```

Komut adımları:

1. Tenant'ı çözer → `account_id`, `api_token` (şifreli, çözülür), `webhook_secret`.
2. `cf/wrangler.toml.stub`'u render eder:
   - `name = "mailbox-inbound-{tenant-slug}"`
   - `account_id = {account_id}`
   - `[vars] WEBHOOK_URL = "{APP_URL}/api/cf/incoming"`, `ACCOUNT_ID = {account_id}`,
     (ops.) `FORWARD_TO`
   - `compatibility_flags = ["nodejs_compat"]`
   - → `cf/wrangler.toml` (gitignore'lu, üretilen dosya)
3. Wrangler'ı çalıştırır (Laravel `Process` ile), auth için env geçirir:
   `CLOUDFLARE_API_TOKEN={api_token}` → `npx wrangler deploy`
4. Secret'ı güvenli aktarır: `wrangler secret put WEBHOOK_SECRET` (değer stdin'den).
5. (Ops.) İlgili adres(ler) için **"Send to Worker" routing kuralını** API ile
   oluşturur/günceller.

Böylece `.env`/DB'deki `APP_URL`, secret veya hesap değişince tek komutla Worker
yeniden konfigüre edilir; kayma olmaz. Admin panelinde **"Worker'ı deploy et"**
aksiyonu bu komutu tetikler ve çıktısını gösterir.

**Gerekli env/ayarlar:** `APP_URL` (webhook tabanı), tenant başına `account_id` +
`api_token` + `webhook_secret` (DB'de, şifreli), sunucuda `node`/`npx wrangler`
kurulu olmalı.

### 5.1.2 Değişiklikte otomatik redeploy (drift tespiti)

`.env` dosyasını filesystem'de "izlemek" yerine **config drift** yaklaşımı
kullanılır (container/Coolify ortamında tek sağlam yol):

- **Hash:** `worker_config_hash = sha256(APP_URL + account_id + webhook_secret +
  cf/src script sürümü + forward ayarı)`. Başarılı deploy sonrası tenant'a yazılır.
- **`php artisan cf:worker:sync`** — tüm tenant'ları gezer, güncel hash ≠
  `worker_config_hash` olanları yeniden deploy eder. **Idempotent**: kayma yoksa
  hiçbir şey yapmaz (container her restart'ta boşa deploy etmez). `Cache::lock`
  ile tenant başına eşzamanlı deploy engellenir.
- **`php artisan cf:worker:status`** — hangi tenant güncel/kaymış/hiç-deploy-edilmemiş.
- **DB kaynaklı değişiklik:** `CloudflareAccount` observer'ı; `account_id`,
  `webhook_secret` veya sürücü alanları dirty ise `DeployWorkerJob` kuyruğa atılır.
- **Env kaynaklı değişiklik (`APP_URL` vb.):** filesystem izleme yok →
  **Coolify post-deploy hook** `cf:worker:sync` çalıştırır (bkz. §14). Env değişince
  Coolify uygulamayı yeniden deploy eder, hook kaymış Worker'ları toparlar.
- **Admin UI:** her tenant satırında **rozet** (güncel / kaymış / hiç deploy
  edilmemiş) + **"Yeniden deploy"** butonu (`DeployWorkerJob`'ı tetikler, çıktıyı
  gösterir).

### 5.2 Laravel webhook

- Route: `POST /api/cf/incoming` (Filament auth dışı, kendi HMAC doğrulaması).
- Middleware: `X-CF-Account` ile tenant/hesap bulunur → o hesabın `webhook_secret`'ı
  ile imza doğrulanır. **İmza `HMAC(secret, timestamp + "." + body)` üzerinden** —
  timestamp de imzaya dahildir (yakalanan isteğin farklı timestamp'le tekrar
  oynatılması engellenir) + `X-CF-Timestamp` ±5 dk penceresi + rate limit.
  (Hesap-başı secret → hesap izolasyonu.)
- Controller ince tutulur → `StoreIncomingEmail` job'a devreder (kuyruk) → `emails`
  + `attachments` tablolarına yazar, `to_email → mailbox_id` çözer, thread'e bağlar
  (In-Reply-To/References). **`ingest_key` unique → çift teslimatta ikinci kayıt
  sessizce atlanır** (idempotent). Ekler eşik altıysa base64'ten `attachments_disk`'e,
  üstüyse R2 key'inden bağlanır.

### 5.3 Yanıtlama (reply) iki yol

1. **Send API ile** (önerilen): Inbox'tan "Yanıtla" → `from` = alınan domain adresi,
   `to` = gönderen, `In-Reply-To`/`References` header'ları set edilerek normal
   giden akışına girer.
2. Worker `reply()` (DMARC şartı + event başına tek yanıt kısıtı vardır) — bizim
   UI akışımıza uymaz, kullanılmayacak.

---

## 6. Veri modeli

Öngörülen tablolar. **Geliştirmede SQLite, üretimde MySQL** — migration'lar iki
sürücüde de çalışacak şekilde portatif yazılır (bkz. [§11](#11-test--ci)).

> **Tenant kolonu:** `cloudflare_accounts` **tenant** tablosudur. Ona bağlı tüm
> tablolar `cloudflare_account_id` taşır ve Filament tenancy ile otomatik
> scope'lanır. Domain'e bağlı tablolarda (emails, routing_rules) verimli scope
> için `cloudflare_account_id` **doğrudan da** tutulur (domain üzerinden JOIN'e
> gerek kalmadan tenant filtresi).

- **cloudflare_accounts** *(tenant)* — `id, name, slug, account_id,
  api_token (encrypted), webhook_secret (encrypted), sending_driver (api|smtp),
  worker_deployed_at, worker_config_hash, timestamps`
  - `webhook_secret`: hesap-başı; Worker deploy'unda secret olarak aktarılır,
    webhook doğrulamasında kullanılır. `worker_deployed_at`: son deploy zamanı.
  - `worker_config_hash`: en son deploy edilen config'in hash'i (drift tespiti;
    bkz. §5.1.2).
- **users** — mevcut tabloya dokunulmaz; tenancy pivot ile bağlanır
- **cloudflare_account_user** *(pivot, üyelik)* — `cloudflare_account_id, user_id,
  role (owner|member), timestamps`
- **domains** — `id, cloudflare_account_id, zone_id, name, status,
  routing_enabled, sending_enabled, inbound_capture (none|catch_all|per_address),
  dns_records (json: beklenen/mevcut MX/SPF/DKIM/DMARC + verified durumları),
  last_synced_at, timestamps`
  - `sending_enabled`/`routing_enabled` ayrı; `inbound_capture` domainin gelen
    yakalama modu (bkz. §5.0).
- **destination_addresses** — `id, cloudflare_account_id, cf_id, email, verified_at,
  timestamps`
- **routing_rules** — `id, domain_id, cf_id, name, matcher (local part),
  actions (json: forward/worker/drop), enabled, priority, is_catch_all, timestamps`
- **mailboxes** *(Authenticatable — mailbox portal girişi)* —
  `id, cloudflare_account_id, domain_id, email (unique, global), display_name,
  password (hashed, nullable), login_enabled (bool), last_login_at,
  remember_token, timestamps`
  - Admin panelinden oluşturulur; şifre admin tarafından atanır/sıfırlanır.
  - `email` **global benzersiz** (login tenant'tan bağımsız); veri yine
    `cloudflare_account_id`/`domain_id` altında durur.
  - Not: gerçekten mail alması için ilgili adreste "Send to Worker" routing kuralı
    olmalı (mailbox oluştururken garanti edilir).
  - `HasPushSubscriptions` uygular (Web Push hedefi mailbox'tır).
- **push_subscriptions** *(webpush paketi)* — `id, subscribable_type/id (→ mailbox),
  endpoint, public_key, auth_token, content_encoding, timestamps`. SPA'nın kaydettiği
  cihaz abonelikleri; bildirimler buraya gönderilir.
- **emails** (inbox) — `id, cloudflare_account_id, domain_id, mailbox_id (nullable),
  message_id, ingest_key, in_reply_to, references (json), from_name, from_email,
  to_email, cc (json), subject, text_body, html_body, headers (json), raw_size,
  read_at, starred, folder, received_at, timestamps` (+ portatif arama; bkz. §11)
  - `mailbox_id`, webhook ingest'te `to_email` → mailbox eşlemesiyle doldurulur
    (eşleşmezse **null = atanmamış**, sadece admin inbox'ta görünür).
  - `ingest_key` = `sha256(account + message_id + to_email)`, **unique** →
    webhook/Worker retry'de **çift kayıt engellenir** (idempotent ingest).
- **sent_emails** — `id, cloudflare_account_id, domain_id, mailbox_id (nullable),
  driver (api|smtp), from_email, to (json), cc (json), bcc (json), subject,
  html_body, text_body, status (queued/delivered/bounced/failed),
  cf_response (json), error, sent_at, in_reply_to_email_id (null), timestamps`
  - `mailbox_id`: gönderim mailbox portalından yapıldıysa dolar (admin
    gönderimlerinde null).
- **attachments** — `id, attachable_type/id (email|sent_email), filename, mime_type,
  size, storage_disk, storage_path (R2/local), content_id, inline (bool), timestamps`

---

## 7. Konfigürasyon tasarımı

`config/cloudflare.php` (yeni):

```php
return [
    'api_base'   => 'https://api.cloudflare.com/client/v4',
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),   // .env fallback; asıl kaynak DB
    'api_token'  => env('CLOUDFLARE_API_TOKEN'),
    'sending' => [
        'driver' => env('CLOUDFLARE_SEND_DRIVER', 'api'),  // 'api' | 'smtp'
    ],
    // Onboarding token-template deep-link için sabitlenen izin-grubu key'leri
    // (GET /user/tokens/permission_groups ile bir kez doğrulanıp buraya yazılır).
    'token_template' => [
        'permission_groups' => [
            ['key' => 'email_routing', 'type' => 'edit'],   // gerçek key impl. sırasında pinlenir
            ['key' => 'email_sending', 'type' => 'edit'],
            ['key' => 'zone',          'type' => 'read'],
        ],
        'name' => 'Cloudflare Mailbox',
    ],
    'webhook' => [
        'secret'          => env('CLOUDFLARE_WEBHOOK_SECRET'),
        'tolerance_secs'  => 300,
    ],
    // Ek dosya deposu config'den ayarlanabilir; S3 uyumlu (AWS S3 / Cloudflare R2 /
    // MinIO) veya yerel disk. Değer bir Laravel filesystem "disk" adıdır.
    'attachments_disk' => env('CLOUDFLARE_ATTACHMENTS_DISK', 's3'), // 's3' | 'local'
];
```

`config/filesystems.php` → S3 uyumlu disk (aynı disk hem AWS S3 hem R2/MinIO için
kullanılır; sadece `endpoint`/bölge değişir):

```php
'disks' => [
    // ...
    's3' => [
        'driver'   => 's3',
        'key'      => env('AWS_ACCESS_KEY_ID'),
        'secret'   => env('AWS_SECRET_ACCESS_KEY'),
        'region'   => env('AWS_DEFAULT_REGION', 'auto'),
        'bucket'   => env('AWS_BUCKET'),
        'endpoint' => env('AWS_ENDPOINT'),          // R2/MinIO için; AWS'de boş
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
        'visibility' => 'private',                  // ekler private; imzalı URL ile indirilir
    ],
],
```

> **Veritabanı:** Geliştirmede **SQLite** (repo varsayılanı), üretimde **MySQL**.
> Kod tek `DB_CONNECTION` env'i ile çalışır; migration'lar iki sürücüde de geçerli
> olacak biçimde yazılır (bkz. [§11](#11-test--ci)). `s3` diski AWS S3, Cloudflare
> R2 ve MinIO ile aynı sürücüyü kullandığından uygulama kodu değişmez.

`config/mail.php` → `mailers.cloudflare` (SMTP sürücüsü):

```php
'cloudflare' => [
    'transport'  => 'smtp',
    'host'       => 'smtp.mx.cloudflare.net',
    'port'       => 465,
    'encryption' => 'tls',
    'username'   => 'api_token',
    'password'   => env('CLOUDFLARE_API_TOKEN'),
],
```

`.env.example` eklenecekler: `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_API_TOKEN`,
`CLOUDFLARE_SEND_DRIVER`, `CLOUDFLARE_WEBHOOK_SECRET`, `CLOUDFLARE_ATTACHMENTS_DISK`
+ S3 uyumlu disk anahtarları (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
`AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`).

> **Not:** `account_id`/`api_token`/`webhook_secret` env değerleri yalnızca
> fallback/ilk kurulum içindir. Multi-tenant modelde asıl kaynak
> **`cloudflare_accounts` tablosudur**; her tenant kendi hesap ID'si, şifreli
> token'ı ve şifreli `webhook_secret`'ını taşır. `cf:deploy-worker` bu değerleri
> DB'den okuyup Worker'a aktarır.

---

## 8. Filament arayüz yapısı

### 8.1 Uygulama yüzeyleri & kimlik

Uygulamanın **iki ayrı yüzeyi** ve **iki ayrı auth guard**'ı vardır — biri Filament,
biri headless:

| Yüzey | Yol | Teknoloji | Guard / provider | Kimler | Kapsam |
|-------|-----|-----------|------------------|--------|--------|
| **Mailbox portalı** | `/` (kök) | **Vue SPA + Laravel API** (headless, PWA) | `mailbox` guard (**Sanctum**) → `mailboxes` | Son kullanıcı (tek mail adresi) | Sadece kendi gelen kutusu + kendi adresinden compose; tenancy YOK |
| **Admin panel** | `/admin` | **Filament** | `web` guard → `users` | Yönetici | Domain/kural/adres/mailbox/gönderim yönetimi; **tenancy'li** |

- **Kök (`/`) mailbox girişi:** kullanıcı email + şifre girer → Sanctum ile oturum →
  global benzersiz `email` ile `mailboxes` çözülür → yalnız o mailbox. Tenant seçmez,
  admin'i göremez.
- **`/admin` yöneticiler için:** Filament tenancy'li panel (§8.2). Ayrı guard →
  mailbox girişi admin'e, admin girişi mailbox'a erişemez.
- **Neden mailbox tarafı Filament değil:** Filament admin/CRUD için tasarlı; native
  mail-app hissiyatı için headless API + SPA daha uygun (tam UX kontrolü, PWA, ileride
  aynı API'yle native mobil). Ayrıntı §8.4.

### 8.2 Multi-tenancy (Filament)

- **Tenant modeli:** `CloudflareAccount`. `AdminPanelProvider`'da
  `->tenant(CloudflareAccount::class, slugAttribute: 'slug')` ile etkinleştirilir.
- **Üyelik:** `User` ↔ `CloudflareAccount` çok-a-çok (`cloudflare_account_user`
  pivot). `User` modeli `HasTenants` sözleşmesini uygular
  (`getTenants()`, `canAccessTenant()`); Panel'e `->tenantMenu()` ve tenant
  switcher eklenir.
- **Kapsama:** Tenant'a ait her Resource'ta Filament otomatik olarak
  `cloudflare_account_id` ile scope uygular (ilişki üzerinden). Doğrudan bu kolonu
  taşımayan tablolarda `getTenantOwnershipRelationshipName()` ile ilişki belirtilir.
- **Tenant kaydı/onboarding:** Yeni Cloudflare hesabı ekleme, Filament
  **tenant registration** sayfası ile yapılır; ayrıntılı akış §8.2.1.
- **Global kaynaklar:** Cloudflare'de hedef adresler hesap seviyesinde olduğundan,
  `DestinationAddressResource` de tenant'a scope'ludur (hesap = tenant).
- **URL yapısı:** `/{tenant-slug}/domains`, `/{tenant-slug}/inbox` … Böylece
  Cloudflare'in account-scoped modeliyle birebir aynı zihinsel model korunur.

### 8.2.1 Cloudflare hesabı bağlama (onboarding)

**"Login with Cloudflare" (OAuth) DEĞİL, manuel scoped API token** kullanılır — en
kolay, en sağlam ve self-host (Coolify) dağıtımına en uygun yol. Sürtünme, token
template deep-link + otomatik hesap tespiti ile en aza indirilir.

**Akış (Filament tenant registration sayfası):**

1. **"Cloudflare'de token oluştur" butonu (tek tık)** → CF token sayfasını
   **gereken izinler önceden seçili** açan template URL:
   ```
   https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=<encoded>&accountId=*&zoneId=all&name=Cloudflare+Mailbox
   ```
   - `permissionGroupKeys` = URL-encoded JSON:
     `[{"key":"<email_routing>","type":"edit"},{"key":"<email_sending>","type":"edit"},{"key":"<zone>","type":"read"}]`
     (Email Routing Edit + Email Sending Edit + Zone Read).
   - **Key'ler tahmin edilmez, sabitlenir:** email permission-group `key`'leri
     Cloudflare public dokümanında listeli değil; implementasyonda bir kez
     `GET /user/tokens/permission_groups` ile gerçek key'ler çekilip **config'e
     pinlenir** (`config/cloudflare.php` → `token_template.permission_groups`).
     Böylece link %100 doğru dolar; Cloudflare key'i değiştirirse tek yerde
     güncellenir.
   - **Kullanıcı deneyimi:** link → (gerekiyorsa CF login) → izinler dolu →
     **"Continue to summary" → "Create Token" → "Copy"**. İzin ayarı yok; Cloudflare
     tarafında ~3 tık. (Token yalnız bir kez gösterildiği için "Create+Copy" adımı
     güvenlik gereği kullanıcıda kalır — bu adım kaldırılamaz.)
2. Kullanıcı token'ı forma **yapıştırır**.
3. Uygulama **doğrular:** `GET /user/tokens/verify` (token geçerli/aktif mi) +
   gereken izinlerin varlığını küçük bir test çağrısıyla (`GET /zones`,
   `.../email/routing`) teyit eder. **Eksik izin varsa** hangi iznin ekleneceğini
   net gösterir (pre-fill bir şeyi kaçırsa bile onboarding sağlam biter).
4. Uygulama **hesabı otomatik bulur:** `GET /accounts` → tek hesap varsa direkt,
   birden fazlaysa kullanıcıya **seçtirir** (account_id elle girilmez).
5. `cloudflare_accounts` tenant kaydı oluşur: `account_id`, `api_token`
   (**encrypted**), otomatik üretilen `webhook_secret` (encrypted); giriş yapan
   kullanıcı pivot ile **owner** olur.
6. `GET /zones` ile bağlantı teyidi + ilk domain senkronu tetiklenir.

**Neden OAuth değil:** Cloudflare self-managed OAuth (2026) mevcut, ama uygulama
sahibinin OAuth client kaydı + public için domain doğrulaması gerektirir; projeyi
başkaları Coolify'da deploy edince her kurulum kendi client'ını kaydetmek zorunda
kalır. Ayrıca email routing/sending scope'larının OAuth ile verilebilirliği doküman
tarafında net değil. Bu yüzden OAuth **opsiyonel/ileri faz** (§13).

### 8.2.2 Ayar sayfası durum akışı (onboarding state machine)

Tenant ayar sayfası, kullanıcıyı adım adım yürüten bir **durum makinesidir**. Her
adım tamamlanınca bir sonraki aksiyon aktifleşir:

| Durum | Koşul | Aktif aksiyon(lar) | O anda yapılabilenler |
|-------|-------|--------------------|------------------------|
| **0 · Boş** | Token yok | **Bağlan** (token yapıştır/validate) | — |
| **1 · Bağlı** | Token validate + hesap tespit edildi | **Full Sync** | (henüz veri yok) |
| **2 · Senkron** | Domain/kural/adres DB'ye çekildi | **Deploy Worker** aktifleşir | Domain/adres/kural görüntüle, **mail gönder** (Faz 2) |
| **3 · Worker aktif** | `cf:deploy-worker` başarılı | (Yeniden deploy / drift rozeti) | **Gelen kutusu** çalışır (Faz 4) |

**Kilit noktalar:**

- **"Yapıştır → Validate → Full Sync → kullan":** 2. durumda kullanıcı domainlerini
  görür ve **mail göndermeye başlayabilir** (send API için token + doğrulanmış domain
  yeter).
- **"Deploy Worker" butonu, Full Sync'ten sonra aktifleşir** — kasıtlı gate. Koşullar:
  (a) token bağlı, (b) **en az bir domain sync'li** (worker, o domaindeki bir adrese
  "Send to Worker" kuralı bağlar; hedefi bilmek için sync şart), (c) `APP_URL` set.
- Buton pasifken **neden pasif olduğu** tooltip'te yazar (ör. "Önce Full Sync yapın").
- **Gelen kutusu (inbox)** yalnız 3. durumdan sonra (worker deploy + routing kuralı)
  gerçekten dolar.

**Full Sync** = `SyncDomains` + `SyncRoutingRules` + `SyncDestinationAddresses`
job'ları sırayla; ilerleme Filament notification/progress ile gösterilir; tekrar
çalıştırılabilir (idempotent upsert).

### 8.2.3 Ayarların saklanması (paket kararı)

- **Per-tenant ayarlar** (token, account_id, webhook_secret, gönderim sürücüsü) →
  **`CloudflareAccount` modeli** + Filament'in modele bağlı ayar sayfası. Tenancy ile
  doğal uyum; **ekstra paket yok**.
- `filament-spatie-settings` (spatie/laravel-settings) **kullanılmaz**: global-singleton
  ayarlar için tasarlıdır, **per-tenant** kimlik bilgilerimize uymaz + Filament v5
  uyumu ayrıca doğrulanmalı. Gelecekte gerçekten global birkaç ayar çıkarsa yeniden
  değerlendirilebilir; şimdilik gereksiz bağımlılık.

### 8.3 Admin panel kaynakları & sayfaları

`app/Filament/Resources/` ve `Pages/`, `Widgets/`:

- **DomainResource** — zone listesi, durum, routing aç/kapa, "Onboard" aksiyonu,
  DNS/SPF/DKIM/DMARC doğrulama rozetleri, "Senkronize et" aksiyonu.
- **RoutingRuleResource** — custom adres (matcher) → aksiyon (forward/worker/drop);
  create/edit/delete/toggle; domain filtresi; catch-all yönetimi.
- **DestinationAddressResource** — hesap seviyesi hedefler; ekle, doğrulama durumu,
  "doğrulama mailini tekrar gönder".
- **MailboxResource** — mailbox portalı hesapları: email seç (domain'e ait bir
  adres), şifre ata/sıfırla, `login_enabled` aç/kapa, son giriş; oluştururken
  ilgili adres için "Send to Worker" routing kuralını garanti etme aksiyonu.
- **InboxResource** — gelen kutusu: liste (okundu/okunmadı, yıldız, domain/klasör
  filtresi, arama), görüntüleme sayfası (HTML güvenli render + ek indirme),
  "Yanıtla / İlet" aksiyonları, thread görünümü.
- **SentEmailResource** — giden log; durum rozetleri (delivered/queued/bounced),
  yeniden gönder.
- **ComposePage** (custom Page) — zengin form: from(domain adresi seçimi), to/cc/bcc,
  konu, HTML editör (RichEditor), ek yükleme (≤5 MiB toplam uyarısı), gönder →
  job.
- **SettingsPage** (custom Page) — Cloudflare hesabı/token yönetimi, gönderim
  sürücüsü seçimi, webhook secret gösterimi, bağlantı testi (`GET /zones`),
  **"Test maili gönder"** butonu (gönderim yolunun uçtan uca sağlığını doğrular).
- **SetupWizard / Onboarding sayfası** (ease-of-use) — durum makinesini (§8.2.2)
  **tek rehberli akışta** toplar: Bağlan → Full Sync → Gelen yakalama modu seç →
  Deploy Worker → İlk mailbox'ı oluştur. Her adım ✓/✗ ve "sıradaki" CTA gösterir.
- **Widget'lar:**
  - **Kurulum kontrol listesi** — token bağlı? domain sync'li? worker deploy'lu (drift?)?
    en az bir sending-enabled domain? en az bir mailbox? → eksikler tıklanabilir CTA.
  - **Sistem sağlığı** — queue çalışıyor mu, `wrangler` mevcut mu, webhook son 24s
    erişildi mi, worker drift durumu.
  - **Metrikler** — son 24s teslim/queue/bounce; okunmamış sayısı; domain sağlık;
    son gelen mailler.
- **Hızlı aksiyonlar (ease-of-use):** RoutingRule/adres satırından **"Bu adres için
  mailbox oluştur"**; DomainResource'ta **"Gönderme'yi etkinleştir" / "Alma'yı
  etkinleştir"** (ayrı onboarding) + **"DNS'i yeniden denetle"**; Inbox'ta hızlı
  **"Yanıtla"**.

Yetkilendirme: Filament auth + **tenant üyeliği** (pivot `role`: owner/member).
Tenant içi ince rol/izin gerekirse `bezhanSalleh/filament-shield` eklenir.

### 8.4 Mailbox portalı (headless SPA + PWA)

Mailbox tarafı **Filament değil**; Laravel headless API + **Vue SPA** (PWA). Native
mail-app hissiyatı hedeflenir.

**Backend — Mailbox API** (`routes/mailbox-api.php`, `mailbox` guard + Sanctum):
tümü aktif mailbox'ın `mailbox_id`'sine zorunlu scope'lu.

| Uç | İşlev |
|----|-------|
| `POST /api/mailbox/login` · `POST /logout` | Sanctum oturum (email+şifre); `login_enabled` + rate-limit |
| `GET /api/mailbox/me` | Profil, sayaçlar (okunmamış) |
| `GET /api/mailbox/emails` | Inbox: sayfalama, arama, filtre (okundu/yıldız/klasör) |
| `GET /api/mailbox/emails/{id}` | Tek mail (güvenli HTML + ek meta) |
| `PATCH /api/mailbox/emails/{id}` | okundu/yıldız/klasör güncelle |
| `GET /api/mailbox/attachments/{id}` | İmzalı geçici indirme |
| `POST /api/mailbox/send` | Gönder (`from` kendi adresine kilitli) → Faz 2 giden akışı |
| `GET /api/mailbox/sent` | Giden log |
| `POST /api/mailbox/push-subscribe` · `DELETE` | Web Push aboneliği kaydet/sil |
| `PUT /api/mailbox/password` | Şifre değiştir |

**Auth modları (web + mobil):** Aynı API iki modu destekler:
- **Web SPA** → Sanctum **SPA oturumu** (same-origin cookie).
- **Mobil (ileri faz)** → Sanctum **bearer token** (login token döndürür). Self-host
  desenine uygun: mobil uygulama önce **CF-Mailbox instance adresi (base URL)** ister,
  sonra email + şifreyle giriş yapar (tek ekstra parametre). API headless olduğundan
  aynı backend'e bağlanır; şimdiden token modu tasarlanır → ileride rework yok.

**Frontend — Vue SPA + PWA** (`resources/mailbox/`, Vite ile ayrı build, kök `/`):
- Vue 3 (Composition) + Pinia + Vue Router; `vite-plugin-pwa` (manifest + service
  worker + "Ana ekrana ekle"). *(React+aynı desen alternatif.)*
- Native-app dokunuşları: unified/klasörlü inbox, hızlı yanıt, optimistic UI, çevrimdışı
  kabuk, pull-to-refresh, koyu tema.
- **Web Push:** SW push event → sistem bildirimi (tarayıcı kapalıyken de). Abonelik
  `mailbox` kimliğine bağlı (bkz. §8.5).
- **filament-pwa kullanılmaz** (o yalnız Filament panelleri için); mailbox PWA'sı
  vite-plugin-pwa ile yapılır. filament-pwa yalnız **admin** panelini kurulabilir
  yapmak istenirse (opsiyonel) kullanılır.

### 8.5 PWA & Web Push (bildirimler)

"Tarayıcı kapalıyken bile bildirim" için **PWA + VAPID Web Push**
(`laravel-notification-channels/webpush`):

- **Abonelik:** `Mailbox` modeli `HasPushSubscriptions` uygular (rehberdeki gibi
  `User`'a değil — bildirim hedefi mailbox'tır). SPA, SW'yi kaydeder, kullanıcı
  hareketiyle izin ister, aboneliği `POST /api/mailbox/push-subscribe`'a gönderir.
- **Tetikleyici:** `StoreIncomingEmail` yeni mail yazınca ilgili mailbox'a
  `IncomingMailNotification` (WebPushChannel, `ShouldQueue`) → "Yeni mailin var"
  push'u; tıklanınca ilgili mail açılır.
- **VAPID:** anahtarlar `.env`'de (`webpush:generate`); public key SPA'ya endpoint/env
  ile verilir (JS'e hardcode edilmez).
- **iOS gerçeği (kritik):** iOS'ta Web Push **yalnız** kullanıcı PWA'yı **Ana Ekrana
  eklerse** (Share → Add to Home Screen), **iOS 16.4+** ve **kullanıcı hareketiyle**
  çalışır. Normal Safari sekmesinde push YOK. SPA iOS'ta önce "Ana ekrana ekle"
  yönlendirmesi gösterir, sonra kurulu modda izin ister.
- **HTTPS zorunlu** (Coolify TLS sağlar).

---

## 9. Paketler

**Laravel (bu repo)**

| Paket | Zorunlu? | Neden |
|-------|----------|-------|
| `saloonphp/saloon` (+ `saloonphp/laravel-plugin`) | Önerilen | Cloudflare API client (request sınıfları, auth, retry, `Http::fake` yerine mock). Alternatif: yerleşik `Http` facade (paketsiz). |
| `laravel/horizon` + Redis | Prod önerilir | Kuyruk (webhook işleme, giden gönderim). Dev'de `database` queue yeter. |
| `zbateson/mail-mime-parser` | Şartlı | Ham MIME'yi Laravel tarafında parse gerekirse (asıl parse Worker'da postal-mime ile yapılırsa gerekmez). |
| `league/flysystem-aws-s3-v3` | **Gerekli** | S3 uyumlu ek depolama (AWS S3 / R2 / MinIO). |
| `laravel/sanctum` | **Gerekli** | Mailbox API auth (SPA cookie + mobil bearer token). |
| `laravel-notification-channels/webpush` | **Gerekli** | VAPID Web Push (mailbox bildirimleri); `push_subscriptions` tablosu. |
| `spatie/laravel-data` | Opsiyonel | DTO'lar / temiz payload tipleri (API yanıtları). |
| `bezhanSalleh/filament-shield` | Opsiyonel | Tenant içi ince rol/izin gerekirse. |
| `tomatophp/filament-pwa` | Opsiyonel | **Yalnız admin** panelini kurulabilir yapmak istenirse (Filament v5 uyumu doğrulanmalı). Mailbox PWA'sı bununla DEĞİL, vite-plugin-pwa ile yapılır. |
| `pestphp/pest` | Önerilen | Test (composer'da plugin izni açık). |

**Mailbox SPA (frontend, `resources/mailbox/`)**

| Paket | Neden |
|-------|-------|
| `vue` (3) + `vue-router` + `pinia` | SPA (React+alternatif) |
| `vite-plugin-pwa` | Manifest + service worker + "Ana ekrana ekle" (mailbox PWA) |
| `axios` | API çağrıları (Sanctum) |

> Filament v5 zaten `composer.json`'da. Notifications/Widgets Filament ile gelir,
> ekstra paket gerekmez. Token şifreleme Laravel yerleşik `encrypted` cast'i ile
> yapılır — paket yok.

**Cloudflare Worker (`cf/` klasörü)**

| Paket | Neden |
|-------|-------|
| `wrangler` (dev dep) | Deploy/dev CLI (`php artisan cf:deploy-worker` bunu çağırır) |
| `postal-mime` | Gelen ham MIME parse |
| `mimetext` | (opsiyonel) Worker'dan compose/reply |
| `nodejs_compat` flag | mimetext/kripto için |

> Deploy Laravel'den yönetilir: `cf/wrangler.toml` şablondan üretilir, ayarlar
> Laravel config/DB'den gelir (bkz. §5.1.1). Sunucuda `node` + `npx wrangler`
> gerekir.

---

## 10. Güvenlik

- **Token'lar DB'de `encrypted` cast ile** şifreli; loglara sızmaz; Filament'te
  maskeli gösterim.
- **En dar API token scope'u** (Zone:Read, Email Routing:Edit, Email Sending:Edit).
- **Webhook:** **hesap-başı** HMAC-SHA256 imza (`webhook_secret` her tenant'ta
  şifreli), imza **`timestamp + "." + body`** üzerinden (replay/gövde-oynatma
  koruması) + timestamp toleransı + rate limit; `X-CF-Account` ile hesap bulunup o
  secret'la doğrulanır; imzasız/eşleşmeyen istek reddedilir. `ingest_key` unique ile
  idempotent (çift teslimat zararsız). Secret'lar loglara yazılmaz; deploy sırasında
  `wrangler secret put` ile stdin'den aktarılır (komut satırında görünmez).
- **HTML mail render:** inbox'ta gelen HTML **sanitize** edilir (XSS/remote content),
  `Content-Security-Policy` ile izole iframe render.
- **Ek dosyalar:** doğrudan public değil; imzalı geçici URL ile indirilir.
- **Domain doğrulama:** SPF/DKIM/DMARC "verified" değilse giden mailde uyarı.
- Filament paneli auth zorunlu; prod'da 2FA önerilir.
- **Mailbox portalı izolasyonu:** `mailbox` guard'ı `web` (admin) guard'ından
  tamamen ayrıdır; mailbox girişi admin paneline **erişemez**. Şifreler `bcrypt`
  ile hash'lenir, admin atar/sıfırlar; login rate-limit'li; `login_enabled=false`
  ise giriş kapalı. Mailbox API'sindeki tüm sorgular `mailbox_id` ile zorunlu
  scope'ludur (global scope ile sızıntı engellenir).
- **Mailbox API (Sanctum):** Web SPA same-origin cookie + CSRF; mobil bearer token
  (iptal edilebilir, dar yetkili). CORS yalnız güvenilen origin'lere. Push public
  key hariç VAPID private key sunucuda kalır. Ek indirme imzalı geçici URL.

---

## 11. Test & CI

- **Pest** ile: `CloudflareClient` (Saloon mock / `Http::fake`), webhook imza
  doğrulama, StoreIncomingEmail job, MailSender (api & smtp) birim testleri;
  Filament Resource smoke testleri (Livewire test helper'ları) + **tenant izolasyon
  testi** (bir tenant başka tenant'ın verisini görmemeli).
- `composer test` mevcut script'e bağlanır (geliştirmede SQLite ile).
- Lint: `laravel/pint` (zaten dev dep).
- (Opsiyonel) GitHub Actions: pint + pest.

**SQLite (dev) ↔ MySQL (prod) portatifliği — dikkat edilecekler:**

- JSON kolonlar için Laravel'in `json` kolon tipi + `array`/`AsCollection` cast'i
  (her iki sürücüde çalışır).
- **Arama** sürücü-farkından etkilenmesin: MySQL `FULLTEXT` vs SQLite `FTS5`
  taşınabilir değil. Bu yüzden inbox aramasında **sürücü-bağımsız** yaklaşım:
  varsayılan `LIKE`/`where` tabanlı arama; prod'da performans gerekirse
  **Laravel Scout (database driver)** veya MySQL `FULLTEXT` migration'ı sürücü
  kontrolüyle (`DB::connection()->getDriverName()`) koşullu eklenir.
- Migration'larda sürücüye özel ham SQL'den kaçınılır; gerekirse
  `Schema::hasColumn`/driver kontrolü ile koşullandırılır.
- Boolean/tarih varsayılanları framework helper'larıyla verilir (ham SQL default
  yerine).

---

## 12. Yol haritası

Her faz bağımsız test edilebilir ve commit'lenebilir çıktı üretir.

### Faz 0 — İskele, multi-tenancy & bağlantı
- `config/cloudflare.php`, `.env.example` güncelle, `config/mail.php` `cloudflare`
  mailer, `config/filesystems.php` `s3` diski.
- Migration + Model: `cloudflare_accounts` (tenant, encrypted token) +
  `cloudflare_account_user` pivot.
- **Filament tenancy'yi etkinleştir:** `AdminPanelProvider`'da tenant + tenant menü;
  `User` modeline `HasTenants`.
- **Onboarding (§8.2.1):** token template deep-link butonu → token yapıştır →
  `GET /user/tokens/verify` + `GET /accounts` ile hesabı otomatik bul → tenant kaydı
  (encrypted token + webhook_secret; kullanıcı owner). **OAuth yok.**
- `CloudflareClient` servisi (Saloon), aktif tenant'ın token'ıyla çalışır;
  `GET /zones` "bağlantı testi".
- Filament **SettingsPage**: gönderim sürücüsü seç, token güncelle, "Test et".
- **Çıktı:** kullanıcı bir Cloudflare hesabı (tenant) ekliyor, panelden bağlanıp
  zone sayısını görüyor; tenant switcher çalışıyor.

### Faz 1 — Domain & adres/kural okuma (read-only senkron)
- Migration+Model: `domains`, `destination_addresses`, `routing_rules`.
- `SyncDomains`, `SyncRoutingRules`, `SyncDestinationAddresses` job'ları.
- Filament **DomainResource**, **DestinationAddressResource**, **RoutingRuleResource**
  (listeleme + "Senkronize et").
- Ayar sayfasında **Full Sync** aksiyonu (state machine §8.2.2): üç sync job'ını
  sırayla çalıştırır, ilerleme gösterir; tamamlanınca "Deploy Worker" gate koşulu
  sağlanır.
- **Çıktı:** validate → Full Sync sonrası domainler, hedef adresler ve mevcut mail
  adresleri (kurallar) arayüzde listeleniyor; kullanıcı mail göndermeye hazır.

### Faz 2 — Giden mail (API + SMTP)
- `MailSender` arayüzü + `ApiMailSender` (send API) + `SmtpMailSender`.
- Migration+Model: `sent_emails`, `attachments`.
- `SendEmail` job; durum eşleme (delivered/queued/bounced).
- Filament **ComposePage** (RichEditor, ek yükleme, ≤5 MiB) — `from` yalnız
  **sending_enabled** domain adresleri + **SentEmailResource** (durum rozetleri,
  yeniden gönder). Ayarlarda "Test maili gönder".
- 429/rate-limit'te Saloon retry + backoff; hata kodları (10001–10203) kullanıcıya
  anlaşılır mesaja çevrilir.
- **Çıktı:** arayüzden mail gönderiliyor, log + durum görünüyor; sürücü config'den
  değişiyor.

### Faz 3 — Adres/kural yazma + domain onboarding (yönetim)
- RoutingRule create/edit/delete/toggle → Cloudflare `PUT/POST/DELETE`.
- DestinationAddress ekle/sil + doğrulama maili tetikleme.
- **Ayrı onboarding:** "Gönderme'yi etkinleştir" (cf-bounce/DKIM) ve "Alma'yı
  etkinleştir" (MX) — `sending_enabled`/`routing_enabled` ayrı; DNS kayıt durum
  rozetleri + "DNS'i yeniden denetle".
- Catch-all yönetimi; domain routing aç/kapa.
- **Çıktı:** mail adresleri/yönlendirmeler ve gönderme/alma durumları panelden tam
  yönetiliyor.

### Faz 4 — Gelen kutusu (tam mail servisi)
- **`cf/` Worker projesi:** `src/inbound-email.js` (postal-mime parse + HMAC +
  `X-CF-Account`) + `wrangler.toml.stub` + `package.json`.
- **`php artisan cf:deploy-worker`** komutu: şablonu Laravel config/DB'den render
  eder, `wrangler deploy` + `secret put` çalıştırır; admin panelinde "Worker'ı
  deploy et" aksiyonu — **Full Sync tamamlanınca aktifleşir** (gate koşulları §8.2.2).
  `cloudflare_accounts`'a `webhook_secret` + `worker_deployed_at` + `worker_config_hash`.
- **Drift/redeploy:** `cf:worker:sync` (kaymışları redeploy) + `cf:worker:status`;
  `CloudflareAccount` observer → `DeployWorkerJob`; tenant satırında güncel/kaymış
  rozeti (bkz. §5.1.2).
- **Gelen yakalama modu** (§5.0): varsayılan **catch-all → Worker**; deploy sırasında
  ilgili kurallar API ile kurulur.
- Laravel webhook route + `X-CF-Account`→hesap→per-account HMAC (timestamp+body imza)
  middleware + `StoreIncomingEmail` job (doğru tenant + `mailbox_id` çözümü +
  `ingest_key` ile idempotent).
- **Güvenilirlik:** Worker webhook başarısızsa throw etmez → fallback forward; ek
  boyutu eşiği (küçük=base64, büyük=R2).
- Migration+Model: `emails` (portatif arama, `ingest_key` unique), thread bağlama;
  ekler `attachments_disk`/R2'ye.
- Filament **InboxResource**: liste/filtre/arama, güvenli HTML görüntüleme, ek indirme,
  okundu/yıldız, **Yanıtla/İlet** (Faz 2 giden akışına bağlanır).
- **Çıktı:** gelen mailler arayüzde görüntüleniyor, yanıtlanıyor — tam mail servisi.

### Faz 5 — Mailbox portalı (headless SPA + PWA + push)
- Migration+Model: `mailboxes` (Authenticatable, hash'li şifre, `HasPushSubscriptions`);
  `emails`/`sent_emails`'e `mailbox_id`; `push_subscriptions`; webhook ingest'te
  `to_email → mailbox_id` çözümü.
- `mailbox` guard + **Sanctum** (SPA cookie + mobil bearer token); login rate-limit.
- **Mailbox API** (§8.4): login/me/emails/send/attachments/push-subscribe…; hepsi
  `mailbox_id`'ye zorunlu scope.
- **Vue SPA + vite-plugin-pwa** kök `/`'te: inbox/mail/compose/sent, native dokunuşlar,
  çevrimdışı kabuk.
- **Web Push:** `webpush` + VAPID; `StoreIncomingEmail` → `IncomingMailNotification`
  (ShouldQueue) → "yeni mail" push. iOS: "ana ekrana ekle" akışı (§8.5).
- Admin panelinde **MailboxResource** (email seç, şifre ata/sıfırla, `login_enabled`,
  "Send to Worker kuralını garanti et").
- **Çıktı:** kullanıcı `/`'te email+şifre ile girip kendi mailbox'ını native-app
  hissiyatıyla kullanıyor, tarayıcı kapalıyken bildirim alıyor; admin `/admin`
  Filament'te. İki yüzey ayrı guard'la izole.

### Faz 6 — Cila & üretim
- **SetupWizard/Onboarding sayfası** + **Kurulum kontrol listesi** & **Sistem sağlığı**
  widget'ları (§8.3); hızlı aksiyonlar.
- Dashboard widget'ları (teslim/bounce/okunmamış/domain sağlık).
- HTML sanitizasyon + CSP iframe render sağlamlaştırma.
- Redis + Horizon; queue izleme.
- Pest test kapsamı + (ops.) GitHub Actions.
- Çok kullanıcı gerekiyorsa `filament-shield` rolleri.
- **Coolify dağıtımı (§14):** node+wrangler içeren image (Nixpacks/Dockerfile),
  queue+scheduler process'leri, post-deploy hook (`migrate --force && cf:worker:sync`),
  `cf:worker:sync`/`cf:worker:status` komutları + drift rozeti.
- Dokümantasyon: kurulum (token oluşturma, Worker deploy, DNS, Coolify) rehberi.

### Faz 7 — Mobil uygulama (opsiyonel, ileri faz)
- Mailbox API'sinin **bearer token** modu (Faz 5'te hazır) üzerine native/cross-platform
  mobil uygulama.
- Onboarding: **CF-Mailbox instance adresi** (base URL) → email + şifre.
- Push: native FCM/APNs köprüsü veya aynı VAPID aboneliği (platforma göre).
- Backend'de **rework yok** — aynı headless API.

---

## 13. Kararlar (netleşti)

- ✅ **Ek depolama:** Config'den ayarlanabilir, **S3 uyumlu** (AWS S3 / Cloudflare R2
  / MinIO) — `attachments_disk` = `s3` (varsayılan) veya `local`. Ekler `private`,
  imzalı URL ile indirilir.
- ✅ **Mimari:** **Multi-tenant** — her Cloudflare hesabı bir **tenant** (Filament
  tenancy). Cloudflare'in account-scoped modeliyle birebir. Kullanıcılar hesaplara
  pivot ile üye; tüm veriler tenant'a scope'lu.
- ✅ **Veritabanı:** Geliştirmede **SQLite**, üretimde **MySQL**. Migration'lar iki
  sürücüde de çalışacak biçimde portatif (bkz. §11).
- ✅ **Reply stratejisi:** **Send API ile** yanıt; Worker `reply()` (DMARC/tek-yanıt
  kısıtları) kullanılmayacak.
- ✅ **Spam/filtre:** Şimdilik **kapsam dışı** (ileride Worker tarafında eklenebilir).
- ✅ **İki kimlik yüzeyi & panel:** Kök `/` = **mailbox portalı** (`mailbox` guard,
  email+şifre, tek mailbox, tenancy'siz); `/admin` = **tenancy'li admin paneli**
  (`web` guard). `mailboxes` tablosu Authenticatable; şifreyi admin atar. İki panel
  ayrı guard'larla tamamen izole (Faz 5).
- ✅ **Worker `cf/` klasöründe, Laravel-güdümlü deploy:** Worker kaynağı repoda
  `cf/`; ayarlar (webhook URL/secret/hesap) elle yazılmaz, `php artisan
  cf:deploy-worker` ile Laravel config/DB'den render edilip `wrangler deploy`
  edilir. Hesap-başı `webhook_secret`; `X-CF-Account` ile doğrulama (Faz 4).
- ✅ **Env değişince Worker redeploy:** Filesystem izleme yok; **config drift hash**
  + `cf:worker:sync` (idempotent) + Coolify post-deploy hook. Bkz. §5.1.2 ve §14.
- ✅ **Cloudflare hesabı bağlama:** **Login with Cloudflare (OAuth) DEĞİL** — manuel
  **scoped API token**; token template deep-link (izinler önceden seçili) +
  `GET /accounts` ile otomatik hesap tespiti. En kolay ve self-host'a uygun.
  OAuth ileride opsiyonel. Bkz. §8.2.1.
- ✅ **Onboarding akışı:** Ayar sayfası bir **durum makinesi** — Bağlan → Full Sync →
  Deploy Worker. "Deploy Worker" butonu Full Sync sonrası aktifleşir (§8.2.2).
- ✅ **Ayar saklama:** Per-tenant ayarlar `CloudflareAccount` modelinde (ekstra paket
  yok). `filament-spatie-settings` **kullanılmaz** (global-singleton; per-tenant'a
  uymaz + Filament v5 uyumu belirsiz). Bkz. §8.2.3.
- ✅ **Çoklu mailbox girişi:** DB'deki her mail adresi **tenant-bağımsız, ayrı ayrı**
  login olur (harici Gmail/Outlook DEĞİL). Mevcut `mailboxes` modeliyle uyumlu.
- ✅ **Mailbox tarafı headless:** Filament **değil** — Laravel salt-JSON mailbox API
  (`mailbox` guard + **Sanctum**) + **Vue SPA (PWA)**. Native mail-app hissiyatı.
  Admin tarafı Filament kalır. Bkz. §8.1, §8.4.
- ✅ **PWA + Web Push (Faz 5):** `vite-plugin-pwa` (mailbox PWA) +
  `laravel-notification-channels/webpush` (VAPID). `HasPushSubscriptions` **`Mailbox`**
  modelinde. iOS: yalnız "ana ekrana ekli" PWA'da push. `filament-pwa` sadece admin
  için opsiyonel. Bkz. §8.5.
- ✅ **Mobil'e hazır API:** Sanctum bearer token modu Faz 5'te tasarlanır; mobil
  uygulama (ileri **Faz 7**) "instance adresi + email + şifre" ile aynı API'ye bağlanır,
  backend rework yok. Bkz. §8.4, Faz 7.

### Sonraki adım
Faz 0'dan (config + Filament tenancy + `CloudflareAccount` modeli + bağlantı testi)
koda başlamaya hazır. Mailbox portalı Faz 5'te gelir (Inbox/Compose'a bağımlı).

---

## 14. Dağıtım (Coolify)

Proje Coolify üzerinde (self-host PaaS) çalışacak şekilde tasarlanır. Ana Laravel
uygulamasının yanında, gelen mail Worker'ı **uygulama container'ından** deploy
edilir — bu yüzden runtime image'ın birkaç ek gereksinimi vardır.

### 14.1 Runtime gereksinimleri (image)
- PHP 8.3 + Laravel gereksinimleri.
- **Node.js + `wrangler`** (Worker deploy için; `cf/` içinden `npx wrangler`).
  Nixpacks'te `NIXPACKS_PKGS="nodejs"` veya özel Dockerfile ile eklenir.
- Cloudflare API'ye **giden HTTPS** erişimi (Coolify ağ politikası).
- Kalıcı disk gerekmez (ekler S3'te, DB harici MySQL). SQLite yalnız dev.

### 14.2 Servisler
- **web** (php-fpm/nginx veya `php artisan serve` değil — prod'da FrankenPHP/Octane
  ya da nginx+fpm), **queue worker** (`php artisan queue:work`/Horizon),
  **scheduler** (`php artisan schedule:work` veya cron) — Coolify'da ayrı process
  veya ek "service" olarak.
- **MySQL** ve (ops.) **Redis** Coolify managed servisleri.

### 14.3 Env (Coolify secrets)
`APP_KEY`, `APP_URL` (webhook tabanı — **doğru domain kritik**), `DB_*` (MySQL),
`QUEUE_CONNECTION=redis` (ops.), `CLOUDFLARE_SEND_DRIVER`, S3 anahtarları
(`AWS_*`, `AWS_ENDPOINT`), `CLOUDFLARE_ATTACHMENTS_DISK`. Tenant token/secret'ları
DB'de (env değil).

### 14.4 Deploy akışı & Worker senkronu
Coolify **Post-deployment Command** (migration'la aynı yer):
```
php artisan migrate --force && php artisan cf:worker:sync
```
- Env'i Coolify'da değiştirince → Coolify uygulamayı **yeniden deploy eder** →
  post-deploy hook `cf:worker:sync`'i çalıştırır → `APP_URL`/secret değişmiş tenant'
  ların Worker'ı otomatik yeniden deploy olur (drift yoksa no-op).
- İlk kurulumda / yeni tenant eklendiğinde admin panelden **"Worker'ı deploy et"**.
- `wrangler` container'da yoksa `cf:worker:sync` net bir uyarı verir ve manuel
  komutu (`cf/README.md`) gösterir — sessizce başarısız olmaz.

> **Alternatif (isteğe bağlı):** Worker deploy'unu uygulama container'ından ayırıp
> **GitHub Actions** ile yapmak isteyenler için `cf/` bağımsız çalışır; ama
> env-güdümlü otomatik redeploy senaryosunda Coolify post-deploy hook yolu daha
> düz. İki yol da desteklenir; README ikisini de belgeler.

### 14.5 Yerel geliştirme (inbound)
Worker Cloudflare bulutunda çalışır; `APP_URL=localhost` ise webhook'a **ulaşamaz**.
Dev'de gelen mail için üç yol:
- **`wrangler dev` yerel email testi:** ham maili yerel endpoint'e POST ederek
  `email()` handler'ı çalıştırılır (deploy'suz).
- **Tünel:** `cloudflared tunnel` / ngrok ile local'i public HTTPS'e açıp `APP_URL`'i
  ona set etmek (gerçek uçtan uca test).
- **Webhook fixture:** `POST /api/cf/incoming`'e örnek imzalı payload'larla test
  (Pest); Worker'sız ingest/parse/depolama doğrulanır.

---

## 15. Gözden geçirme & iyileştirmeler

Bu revizyonda tüm isterler ve kullanım kolaylığı gözden geçirildi; bulgular ve
uygulanan iyileştirmeler:

**Doğruluk / kapsama (uygulandı):**
- **Gönderme ≠ Alma onboarding.** Cloudflare'de ayrı işlemler; `domains` artık
  `sending_enabled` + `routing_enabled` ayrı tutuyor, compose `from` yalnız
  sending-enabled adresleri sunuyor (§1, §6, Faz 3).
- **Catch-all → Worker** varsayılan gelen yakalama; tam inbox için en az yönetimli
  yol. `inbound_capture` modu (`catch_all|per_address|none`) (§5.0).
- **Mail kaybını önleme:** Worker webhook başarısızsa throw etmez → `ctx.waitUntil`
  retry + fallback forward (§5.1).
- **Idempotent ingest:** `emails.ingest_key` unique → Worker/webhook retry çift kayıt
  yapmaz (§5.2, §6).
- **Ek boyutu eşiği:** küçük=base64 gövde, büyük=R2 binding; PHP `post_max_size`
  gerçeği göz önünde (§5.1).
- **Webhook imzası sağlamlaştırıldı:** `HMAC(secret, timestamp + "." + body)` (§5.2, §10).
- **Atanmamış mail:** `mailbox_id=null` → yalnız admin inbox'ta görünür (§6).

**Kullanım kolaylığı (uygulandı):**
- **SetupWizard / Onboarding sayfası** + **Kurulum kontrol listesi** ve **Sistem
  sağlığı** widget'ları (queue/wrangler/webhook/drift) (§8.3).
- **Hızlı aksiyonlar:** "Bu adres için mailbox oluştur", "Gönderme/Alma'yı etkinleştir",
  "DNS'i yeniden denetle", "Test maili gönder" (§8.3, Faz 2/3).
- **Yerel geliştirme inbound** rehberi (wrangler dev / tünel / fixture) (§14.5).

**İleride (opsiyonel, kapsam dışı — not edildi):**
- Denetim izi: `spatie/laravel-activitylog` (admin aksiyonları).
- Çok dilli UI (TR/EN) — şu an locale `en`.
- Veri saklama/arşiv politikası: büyük gövdelerin diske taşınması, eski mail temizliği.
- Async bounce/complaint bildirimleri (Cloudflare API sınırlı; izlenecek).
- Spam filtresi (Worker tarafında) — daha önce kapsam dışı bırakıldı.
