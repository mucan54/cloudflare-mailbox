# Changelog

## Unreleased

### Profile photos
- Mailbox users can upload a profile photo in Profile & settings (admins can
  set one on the mailbox form too). Photos are centre-cropped to a 256 px JPEG
  in the browser and again on the server, which also applies the EXIF
  orientation and strips metadata (GPS etc.).
- The photo replaces the initials avatar in the portal: top bar, account list,
  your own messages in Sent and in conversations.
- Mail from another mailbox on this server shows the sender's photo in the
  inbox list and in the conversation, but only when Cloudflare verified the
  From domain (`Authentication-Results: mx.cloudflare.net; … dmarc=pass`).
  A forged From never gets someone else's photo.
- Optional "show my photo next to my signature": outgoing HTML mail gets the
  signature as a small card with the photo, so Gmail, Outlook and other
  external recipients see it too. The photo is served from
  `/avatars/{token}`, a public, unguessable URL that stays the same when the
  photo changes.
- New optional `CLOUDFLARE_AVATARS_DISK` (defaults to the attachments disk).

### Fixes
- Incoming mail now records the sender from the From header instead of the
  envelope sender (MAIL FROM). Cloudflare Email Sending and most bulk senders
  put a bounce address there (`…@cf-bounce.<domain>`), which showed up as the
  sender, received replies, and was suggested as a recipient. The envelope
  sender is only used when the From header is missing. A migration re-derives
  the sender of already-stored mail from its saved headers. In the same pass
  it links DMARC-verified senders on this server, so older mail shows their
  profile photo too.
- "Reply" goes to the Reply-To address when the sender set one (mailing lists,
  no-reply senders, help desks), as in every mail client.

## v0.0 — "First Flight" (İlk Uçuş)

The first tagged release of the Cloudflare Mailbox — a multi-tenant web mail
service on Cloudflare Email (Sending + Routing), with a Laravel 13 + Filament 5
admin and a headless Vue PWA mailbox portal.

### Admin panel (Filament 5, multi-tenant)
- Each Cloudflare account is a tenant; connect with an API token, validate,
  full-sync domains / destination addresses / routing rules.
- One-click inbound Email Worker deploy from the panel (`cf:deploy-worker`,
  `cf:worker:sync`, `cf:worker:status`), with drift detection.
- `cf:doctor` — end-to-end inbound diagnostics (token → worker → catch-all →
  webhook), signed webhook self-test, and explicit-rule conflict detection.
- Domains: catch-all → Worker, and a sender-authentication DNS guide
  (SPF/DKIM/DMARC).
- Sent activity log with delivery status, rejection reasons, and raw
  Cloudflare response.
- Mailboxes CRUD, setup checklist, stats widgets, i18n (TR/EN).

### Mailbox PWA (Vue 3, Outlook-style)
- Multi-account login with a unified "All accounts" view.
- Folders (Inbox / Starred / Sent / Trash), star, read/unread, delete with
  undo, search, bulk actions, keyboard shortcuts.
- Desktop three-pane reading layout + reading-pane/density/theme settings;
  mobile-first with bottom-nav app switcher, folder chips, compose FAB, and
  pull-to-refresh.
- Compose: from-account selector, Cc/Bcc chips, reply / reply-all / forward,
  and recipient autocomplete from history + contacts.
- Calendar, Contacts, and Tasks — real per-mailbox data, aggregated in the
  unified account view (Calendar & Contacts).
- Web Push notifications (deep-link to the message on tap) and live updates.
- Multi-language (TR / EN) with automatic browser-language detection.

### Delivery / infra
- Send via Cloudflare REST API or SMTP (config-selectable).
- Inbound Email Worker (postal-mime) → HMAC-signed webhook → Laravel.
- Coolify-ready: Dockerfile + docker-compose (app, worker, scheduler, MySQL,
  Redis), S3/R2 attachments, VAPID Web Push.

80 passing tests.
