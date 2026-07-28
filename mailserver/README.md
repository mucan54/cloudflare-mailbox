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
| `TLS_CERT`, `TLS_KEY` | — | PEM cert + key. Set both in production. **If omitted, a self-signed cert is generated in memory** so the service still starts with TLS (clients warn) — good for a first boot on Coolify. |
| `HEALTH_ADDR` | `:8080` | HTTP `/health` endpoint for Coolify/Docker health checks |
| `ALLOW_INSECURE_AUTH` | `false` | Allow auth without TLS (local testing only) |

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

## Coolify — two ways to deploy

**Recommended: the all-in-one compose.** Deploy the app *and* this bridge from a
single file: point Coolify's **Docker Compose Location** at
[`../docker-compose.native.yaml`](../docker-compose.native.yaml) instead of
`docker-compose.yaml`. It's the full production stack (app + worker + scheduler +
MySQL + Redis) with the `mailserver` service added and wired to the app over the
internal network (`LARAVEL_API_URL=http://app:8080`) — one resource, one deploy.
You only set `MAIL_CLIENT_SERVER_HOST` (and, for real TLS, `TLS_DIR`/`TLS_CERT`/
`TLS_KEY`). Steps 3–6 below (ports, health, TLS, DNS) still apply.

**Advanced: the bridge on its own.** If the app is deployed some other way (a
Dockerfile build pack, or on a different host), run just the bridge as a separate
Coolify resource:

1. **New Resource → Docker Compose**, point it at `docker-compose.mailserver.yml`
   (or **Dockerfile** with *Base Directory* = `mailserver`).
2. **Environment**: set `LARAVEL_API_URL` (your Laravel URL) and `MAIL_DOMAIN`.

Either way:

3. **Ports**: `587` and `993` are mapped to the host. Coolify honours these — no
   HTTP domain/proxy is attached (mail is raw TCP, and Cloudflare's proxy /
   Traefik cannot terminate it). Leave the resource **without a public domain**.
4. **Health check**: the container exposes HTTP `/health` on `8080` and ships a
   Docker `HEALTHCHECK`, so Coolify shows it healthy without an HTTP domain.
5. **TLS**: for production, mount your `mail.<domain>` cert/key via a Coolify
   volume/storage and set `TLS_CERT`/`TLS_KEY`. If you skip this, the service
   boots with a **self-signed** cert (clients warn) so you can verify wiring
   first.
6. **DNS/firewall**: point `mail.<domain>` at the server with a **grey-cloud**
   (DNS-only) record and make sure ports `587`/`993` are open on the host.

> Note: Coolify's built-in proxy is for HTTP(S). It does not route `587`/`993`,
> which is why this service binds those ports directly on the host instead of
> going through the proxy.

## Deployment notes

- **Ports**: expose `587` and `993` on the host/firewall.
- **DNS**: point `mail.mucan.dev` at the server. In Cloudflare, use a **grey
  cloud** (DNS only) record — Cloudflare's proxy does not tunnel IMAP/SMTP.
- **TLS**: use a real certificate for `mail.mucan.dev` (e.g. Let's Encrypt).
- **Client setup**: IMAP host `mail.mucan.dev:993` (SSL), SMTP host
  `mail.mucan.dev:587` (STARTTLS), username = full email, password = mailbox
  password.

## Auto-configuration (autodiscover) & DNS

The Laravel app serves mail-client autoconfig so clients set themselves up from
just the email address:

- Thunderbird / Apple Mail: `autoconfig.<domain>/mail/config-v1.1.xml`
- Outlook: `autodiscover.<domain>/autodiscover/autodiscover.xml`

Enable it with `MAIL_CLIENT_ACCESS=true` and set `MAIL_CLIENT_SERVER_HOST` to
this bridge's host. Then, per domain, create the DNS records — either
automatically (`MAIL_CLIENT_AUTO_DNS=true`, done on domain add) or from the
admin panel's **Domains → "Mail istemci DNS"** action. It provisions, via the
Cloudflare API:

- `autodiscover.<domain>` / `autoconfig.<domain>` CNAME → app host (proxied)
- `mail.<domain>` CNAME → `MAIL_CLIENT_SERVER_HOST` (grey-cloud, raw TCP)
- `_imaps._tcp` / `_submission._tcp` SRV → `mail.<domain>`

## Calendar & Contacts sync (CalDAV / CardDAV) — no bridge needed

Unlike IMAP/SMTP (raw TCP), CalDAV and CardDAV are **HTTP-based**, so the Laravel
app serves them itself at `/dav` (via `sabre/dav`) — behind Coolify/Cloudflare,
over the normal HTTPS port, **with no extra service and no Docker**. This mirrors
each mailbox's `events` and `contacts` into a native Calendar/Contacts account.

Enable with `MAIL_CLIENT_DAV=true` (independent of the IMAP/SMTP bridge — you can
run DAV alone). Then, per domain, the **Mail istemci DNS** action / `auto_dns`
also provisions the RFC 6764 discovery records:

- `_caldavs._tcp` / `_carddavs._tcp` SRV → app host (port 443)
- matching `path=/dav/` TXT records

Client setup:

- **iOS / macOS (native):** install the per-mailbox profile at
  `/mail/profile/<email>.mobileconfig` — one tap adds **Mail + Calendar +
  Contacts** together (the closest a custom domain gets to Gmail's bundled
  add-account). Or add a CalDAV/CardDAV account manually with just email +
  password (SRV auto-discovery fills in the server).
- **Android:** install **DAVx5**, enter email + password — it discovers `/dav`
  via the same SRV/`.well-known` records. (Android has no built-in CalDAV.)
- Auth is HTTP Basic (mailbox email + password); `/.well-known/caldav` and
  `/.well-known/carddav` redirect to `/dav/`.

## Scope / limitations

- IMAP is **read + flags** (mark read/unread, star, move to trash). It is not a
  full server: no server-side threads, partial SEARCH, no server-side sorting.
- Bodies are **reconstructed** from stored parts, so exact original headers are
  not preserved (fine for reading).
- Consider adding per-app passwords instead of the mailbox password later.
