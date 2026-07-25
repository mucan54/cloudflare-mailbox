# Native-mail bridge (IMAP + SMTP) — **optional**

This is an **optional, standalone** service that lets you use the mailbox from a
native mail client (Apple Mail, Outlook, Thunderbird) over **IMAP** (read) and
**SMTP submission** (send).

> The Laravel app runs perfectly well without this. If you just want the web /
> PWA mailbox, ignore this directory entirely — no Docker, no Go, nothing to
> install.

## How it works

It is a thin **protocol bridge**. It stores nothing and has no database. Every
IMAP/SMTP action is proxied to the existing Laravel mailbox API:

```
Apple Mail ──IMAP 993 / SMTP 587──▶ this service ──HTTPS /api/mailbox/*──▶ Laravel ──▶ Cloudflare
```

- Clients authenticate with the **mailbox email + password**; the bridge
  exchanges them for a Sanctum token via `POST /api/mailbox/login`.
- IMAP folders map to `INBOX`/`Sent`/`Trash`/`Starred`; message UIDs are the
  email IDs; flags map to read/starred/trash; `FETCH BODY[]` streams the
  reconstructed RFC822 from `GET /api/mailbox/emails/{id}/raw`.
- SMTP submission parses the outgoing message and calls `POST /api/mailbox/send`
  (which sends via Cloudflare and records it in Sent).

## Configuration (env)

| Var | Default | Purpose |
|-----|---------|---------|
| `LARAVEL_API_URL` | `http://localhost` | Base URL of the Laravel app |
| `SMTP_ADDR` | `:587` | SMTP submission listen address |
| `IMAP_ADDR` | `:993` | IMAP listen address |
| `MAIL_DOMAIN` | `localhost` | SMTP greeting domain |
| `TLS_CERT`, `TLS_KEY` | — | PEM cert + key. **Set both in production** (SMTP requires STARTTLS before auth; IMAP uses implicit TLS). Without them, auth is allowed in the clear — local testing only. |

## Run it

Plain Go:

```bash
cd mailserver
LARAVEL_API_URL=https://mailbox.mucan.dev \
TLS_CERT=/certs/fullchain.pem TLS_KEY=/certs/privkey.pem \
go run .
```

Docker:

```bash
docker build -t mailbox-mailserver ./mailserver
docker run -p 587:587 -p 993:993 \
  -e LARAVEL_API_URL=https://mailbox.mucan.dev \
  -e TLS_CERT=/certs/fullchain.pem -e TLS_KEY=/certs/privkey.pem \
  -v /certs:/certs:ro mailbox-mailserver
```

Or the optional compose file at the repo root: `docker compose -f
docker-compose.mailserver.yml up -d`.

## Deployment notes

- **Ports**: expose `587` and `993` on the host/firewall.
- **DNS**: point `mail.mucan.dev` at the server. In Cloudflare, use a **grey
  cloud** (DNS only) record — Cloudflare's proxy does not tunnel IMAP/SMTP.
- **TLS**: use a real certificate for `mail.mucan.dev` (e.g. Let's Encrypt).
- **Client setup**: IMAP host `mail.mucan.dev:993` (SSL), SMTP host
  `mail.mucan.dev:587` (STARTTLS), username = full email, password = mailbox
  password.

## Scope / limitations

- IMAP is **read + flags** (mark read/unread, star, move to trash). It is not a
  full server: no server-side threads, partial SEARCH, no server-side sorting.
- Bodies are **reconstructed** from stored parts, so exact original headers are
  not preserved (fine for reading).
- Consider adding per-app passwords instead of the mailbox password later.
