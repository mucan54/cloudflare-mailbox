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
13. [Açık kararlar](#13-açık-kararlar)

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
- **Domain Cloudflare DNS'te olmak zorundadır.** Onboard sırasında Cloudflare,
  `cf-bounce` alt alanına MX + SPF + DKIM + DMARC kayıtlarını otomatik ekler.
- **Giden mesaj boyutu (ekler dahil) 5 MiB'ı geçemez.**
- Hedef adresler **hesap seviyesinde** paylaşılır; routing kuralları **zone (domain)
  seviyesindedir**. Doğrulanmamış hedefe giden kural otomatik pasifleşir.

**Multi-tenancy:** Uygulama **çok kiracılı (multi-tenant)** kurgulanır. Her
**Cloudflare hesabı = bir tenant**; bu, Cloudflare'in kendi `account_id`-bazlı
kapsama modeliyle birebir örtüşür. Filament v5'in **yerleşik tenancy** desteği
kullanılır: tüm kaynaklar (domain, kural, adres, inbox, giden) aktif tenant'a
scope'lanır, panelde tenant switcher bulunur, kullanıcılar hesaplara pivot tablo
ile üye olur. Ayrıntı: [§8.1](#81-multi-tenancy-filament).

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
              │  workers/inbound-email/  │                 │  api.cloudflare.com/client │
              │  email() { postal-mime } │◄── MX ── mail   │  /v4/accounts|zones/...     │
              └──────────────────────────┘   gelen         └──────────────────────────┘
```

**Akış özetleri:**

- **Giden:** Filament Compose formu → `SendEmail` job → `CloudflareClient::send()`
  (REST) **veya** `Mail::mailer('cloudflare')` (SMTP) → `sent_emails` log +
  delivered/queued/bounced durumu.
- **Gelen:** mail → Cloudflare MX → routing rule ("Send to a Worker") → Email Worker
  ham MIME'yi `postal-mime` ile parse eder, ekleri R2/Storage'a koyar, Laravel'e
  HMAC imzalı JSON POST atar → `StoreIncomingEmail` job → `emails` tablosu → Filament
  Inbox.
- **Yönetim:** Filament Resource'ları `CloudflareClient` üzerinden Cloudflare'deki
  zone/rule/address kaynaklarını okur/yazar; yerel DB senkron kopya (cache) tutar.

---

## 3. Cloudflare API uç kataloğu

Taban URL: `https://api.cloudflare.com/client/v4`
Auth: `Authorization: Bearer <API_TOKEN>`

| Amaç | Method & Path | Kapsam |
|------|---------------|--------|
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

### 5.1 Email Worker (`workers/inbound-email/`)

Ayrı bir mini-proje (wrangler ile deploy). `email()` handler'ı:

```ts
import PostalMime from "postal-mime";

export default {
  async email(message, env, ctx) {
    const parsed = await PostalMime.parse(message.raw);   // MIME → yapısal
    const payload = {
      message_id: message.headers.get("Message-ID"),
      envelope_from: message.from,
      envelope_to: message.to,
      subject: parsed.subject,
      html: parsed.html,
      text: parsed.text,
      from: parsed.from,
      to: parsed.to,
      cc: parsed.cc,
      date: parsed.date,
      headers: [...message.headers],
      attachments: parsed.attachments.map(a => ({
        filename: a.filename, mimeType: a.mimeType,
        contentId: a.contentId, size: a.content.byteLength,
        // içerik: R2'ye koy, key gönder (veya küçükse base64)
      })),
      raw_size: message.rawSize,
    };

    // HMAC imzalı POST
    const body = JSON.stringify(payload);
    const sig = await hmacSha256(env.WEBHOOK_SECRET, body);
    await fetch(env.WEBHOOK_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json",
                 "X-CF-Signature": sig, "X-CF-Timestamp": `${Date.now()}` },
      body,
    });

    // opsiyonel: klasik forward'ı da sürdür
    // await message.forward(env.FORWARD_TO);
  },
};
```

`ForwardableEmailMessage` arayüzü: `from`, `to`, `headers`, `raw` (stream),
`rawSize`, `canBeForwarded`, `setReject(reason)`, `forward(rcptTo, headers?)`,
`reply(EmailMessage)`. Reply/compose için `mimetext` + `nodejs_compat` flag'i
gerekir. Ekler için **R2** önerilir (büyük ekler webhook gövdesini şişirmesin;
Worker eki R2'ye koyar, Laravel key ile indirir/gösterir).

### 5.2 Laravel webhook

- Route: `POST /api/cf/incoming` (Filament auth dışı, kendi HMAC doğrulaması).
- Middleware: `X-CF-Signature` HMAC + `X-CF-Timestamp` replay penceresi (±5 dk).
- Controller ince tutulur → `StoreIncomingEmail` job'a devreder (kuyruk) → `emails`
  + `attachments` tablolarına yazar, thread'e bağlar (In-Reply-To/References).

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

- **cloudflare_accounts** *(tenant)* — `id, name (slug/label), account_id,
  api_token (encrypted), sending_driver (api|smtp), timestamps`
- **users** — mevcut tabloya dokunulmaz; tenancy pivot ile bağlanır
- **cloudflare_account_user** *(pivot, üyelik)* — `cloudflare_account_id, user_id,
  role (owner|member), timestamps`
- **domains** — `id, cloudflare_account_id, zone_id, name, status,
  routing_enabled, dns_verified (spf/dkim/dmarc json), last_synced_at, timestamps`
- **destination_addresses** — `id, cloudflare_account_id, cf_id, email, verified_at,
  timestamps`
- **routing_rules** — `id, domain_id, cf_id, name, matcher (local part),
  actions (json: forward/worker/drop), enabled, priority, is_catch_all, timestamps`
- **emails** (inbox) — `id, cloudflare_account_id, domain_id, message_id,
  in_reply_to, references (json), from_name, from_email, to_email, cc (json),
  subject, text_body, html_body, headers (json), raw_size, read_at, starred,
  folder, received_at, timestamps` (+ portatif arama; bkz. §11)
- **sent_emails** — `id, cloudflare_account_id, domain_id, driver (api|smtp),
  from_email, to (json), cc (json), bcc (json), subject, html_body, text_body,
  status (queued/delivered/bounced/failed), cf_response (json), error, sent_at,
  in_reply_to_email_id (null), timestamps`
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

> **Not:** `account_id`/`api_token` env değerleri yalnızca fallback/ilk kurulum
> içindir. Multi-tenant modelde asıl kaynak **`cloudflare_accounts` tablosudur**;
> her tenant kendi hesap ID'si ve şifreli token'ını taşır.

---

## 8. Filament arayüz yapısı

### 8.1 Multi-tenancy (Filament)

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
  **tenant registration** sayfası ile yapılır (hesap ID + token gir → `GET /zones`
  ile doğrula). Token tenant kaydında `encrypted` saklanır.
- **Global kaynaklar:** Cloudflare'de hedef adresler hesap seviyesinde olduğundan,
  `DestinationAddressResource` de tenant'a scope'ludur (hesap = tenant).
- **URL yapısı:** `/{tenant-slug}/domains`, `/{tenant-slug}/inbox` … Böylece
  Cloudflare'in account-scoped modeliyle birebir aynı zihinsel model korunur.

### 8.2 Kaynaklar & sayfalar

`app/Filament/Resources/` ve `Pages/`, `Widgets/`:

- **DomainResource** — zone listesi, durum, routing aç/kapa, "Onboard" aksiyonu,
  DNS/SPF/DKIM/DMARC doğrulama rozetleri, "Senkronize et" aksiyonu.
- **RoutingRuleResource** — custom adres (matcher) → aksiyon (forward/worker/drop);
  create/edit/delete/toggle; domain filtresi; catch-all yönetimi.
- **DestinationAddressResource** — hesap seviyesi hedefler; ekle, doğrulama durumu,
  "doğrulama mailini tekrar gönder".
- **InboxResource** — gelen kutusu: liste (okundu/okunmadı, yıldız, domain/klasör
  filtresi, arama), görüntüleme sayfası (HTML güvenli render + ek indirme),
  "Yanıtla / İlet" aksiyonları, thread görünümü.
- **SentEmailResource** — giden log; durum rozetleri (delivered/queued/bounced),
  yeniden gönder.
- **ComposePage** (custom Page) — zengin form: from(domain adresi seçimi), to/cc/bcc,
  konu, HTML editör (RichEditor), ek yükleme (≤5 MiB toplam uyarısı), gönder →
  job.
- **SettingsPage** (custom Page) — Cloudflare hesabı/token yönetimi, gönderim
  sürücüsü seçimi, webhook secret gösterimi, bağlantı testi (`GET /zones`).
- **Widget'lar** — son 24s teslim/queue/bounce; okunmamış sayısı; domain sağlık
  durumu; son gelen mailler.

Yetkilendirme: Filament auth + **tenant üyeliği** (pivot `role`: owner/member).
Tenant içi ince rol/izin gerekirse `bezhanSalleh/filament-shield` eklenir.

---

## 9. Paketler

**Laravel (bu repo)**

| Paket | Zorunlu? | Neden |
|-------|----------|-------|
| `saloonphp/saloon` (+ `saloonphp/laravel-plugin`) | Önerilen | Cloudflare API client (request sınıfları, auth, retry, `Http::fake` yerine mock). Alternatif: yerleşik `Http` facade (paketsiz). |
| `laravel/horizon` + Redis | Prod önerilir | Kuyruk (webhook işleme, giden gönderim). Dev'de `database` queue yeter. |
| `zbateson/mail-mime-parser` | Şartlı | Ham MIME'yi Laravel tarafında parse gerekirse (asıl parse Worker'da postal-mime ile yapılırsa gerekmez). |
| `league/flysystem-aws-s3-v3` | **Gerekli** | S3 uyumlu ek depolama (AWS S3 / R2 / MinIO). |
| `spatie/laravel-data` | Opsiyonel | DTO'lar / temiz payload tipleri. |
| `bezhanSalleh/filament-shield` | Opsiyonel | Tenant içi ince rol/izin gerekirse. |
| `pestphp/pest` | Önerilen | Test (composer'da plugin izni açık). |

> Filament v5 zaten `composer.json`'da. Notifications/Widgets Filament ile gelir,
> ekstra paket gerekmez. Token şifreleme Laravel yerleşik `encrypted` cast'i ile
> yapılır — paket yok.

**Cloudflare Worker (`workers/inbound-email/`)**

| Paket | Neden |
|-------|-------|
| `wrangler` (dev dep) | Deploy/dev CLI |
| `postal-mime` | Gelen ham MIME parse |
| `mimetext` | (opsiyonel) Worker'dan compose/reply |
| `nodejs_compat` flag | mimetext/kripto için |

---

## 10. Güvenlik

- **Token'lar DB'de `encrypted` cast ile** şifreli; loglara sızmaz; Filament'te
  maskeli gösterim.
- **En dar API token scope'u** (Zone:Read, Email Routing:Edit, Email Sending:Edit).
- **Webhook:** HMAC-SHA256 imza + timestamp toleransı (replay koruması) + rate
  limit; body imzadan önce doğrulanır; imzasız istek reddedilir.
- **HTML mail render:** inbox'ta gelen HTML **sanitize** edilir (XSS/remote content),
  `Content-Security-Policy` ile izole iframe render.
- **Ek dosyalar:** doğrudan public değil; imzalı geçici URL ile indirilir.
- **Domain doğrulama:** SPF/DKIM/DMARC "verified" değilse giden mailde uyarı.
- Filament paneli auth zorunlu; prod'da 2FA önerilir.

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
  `User` modeline `HasTenants`; tenant registration (hesap ID + token → doğrula).
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
- **Çıktı:** mevcut domainler, hedef adresler ve mevcut mail adresleri (kurallar)
  arayüzde listeleniyor.

### Faz 2 — Giden mail (API + SMTP)
- `MailSender` arayüzü + `ApiMailSender` (send API) + `SmtpMailSender`.
- Migration+Model: `sent_emails`, `attachments`.
- `SendEmail` job; durum eşleme (delivered/queued/bounced).
- Filament **ComposePage** (RichEditor, ek yükleme, ≤5 MiB) + **SentEmailResource**
  (durum rozetleri, yeniden gönder).
- **Çıktı:** arayüzden mail gönderiliyor, log + durum görünüyor; sürücü config'den
  değişiyor.

### Faz 3 — Adres/kural yazma (yönetim)
- RoutingRule create/edit/delete/toggle → Cloudflare `PUT/POST/DELETE`.
- DestinationAddress ekle/sil + doğrulama maili tetikleme.
- Catch-all yönetimi; domain routing aç/kapa; "Onboard" akışı + DNS durum rozetleri.
- **Çıktı:** mail adresleri/yönlendirmeler panelden tam yönetiliyor.

### Faz 4 — Gelen kutusu (tam mail servisi)
- **Email Worker** (`workers/inbound-email/`): postal-mime parse + HMAC webhook +
  ek dosyaları S3/R2'ye yükleme + `wrangler.toml`.
- Laravel webhook route + HMAC middleware + `StoreIncomingEmail` job (gelen maili
  doğru tenant'a `cloudflare_account_id` ile bağlar).
- Migration+Model: `emails` (portatif arama), thread bağlama.
- Filament **InboxResource**: liste/filtre/arama, güvenli HTML görüntüleme, ek indirme,
  okundu/yıldız, **Yanıtla/İlet** (Faz 2 giden akışına bağlanır).
- **Çıktı:** gelen mailler arayüzde görüntüleniyor, yanıtlanıyor — tam mail servisi.

### Faz 5 — Cila & üretim
- Dashboard widget'ları (teslim/bounce/okunmamış/domain sağlık).
- HTML sanitizasyon + CSP iframe render sağlamlaştırma.
- Redis + Horizon; queue izleme.
- Pest test kapsamı + (ops.) GitHub Actions.
- Çok kullanıcı gerekiyorsa `filament-shield` rolleri.
- Dokümantasyon: kurulum (token oluşturma, Worker deploy, DNS) rehberi.

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

### Sonraki adım
Faz 0'dan (config + Filament tenancy + `CloudflareAccount` modeli + bağlantı testi)
koda başlamaya hazır.
