# Changelog

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
