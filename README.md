# Cloudflare Mailbox

A multi-tenant web mail service built on **Cloudflare Email Service** (Sending +
Routing), with a **Laravel + Filament** admin panel and a **headless Vue SPA (PWA)**
mailbox portal. Send and receive email on your own domains, manage routing, and give
each address its own installable, push-notified inbox.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full design.

## What it does

- **Admin panel** (`/admin`, Filament, multi-tenant — one Cloudflare account = one
  tenant): one-click token onboarding, domain/address/routing-rule sync & management,
  compose & sent log, inbox, inbound-Worker deploy.
- **Mailbox portal** (`/`, Vue SPA + PWA): per-address login (email + password),
  native-feeling inbox, compose, and Web Push "new mail" notifications — installable
  on Android & iOS.
- **Sending**: Cloudflare REST API (default) or SMTP, switchable via config.
- **Receiving**: a Cloudflare **Email Worker** (`cf/`) parses inbound mail and posts
  it to a signed webhook; Laravel stores it and pushes a notification.

## Requirements

- PHP 8.3+, Composer, Node.js 20+ (Node + `wrangler` are needed to deploy the Worker)
- A database (SQLite for dev, MySQL for production)
- An S3-compatible bucket for attachments in production (AWS S3 / Cloudflare R2 / MinIO)
- A domain on **Cloudflare DNS**

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed          # seeds admin@example.com / password
php artisan webpush:vapid        # VAPID keys for Web Push
npm install
npm run build                       # or: npm run dev
php artisan serve
```

- Admin panel: `http://localhost:8000/admin` (`admin@example.com` / `password`)
- Mailbox portal: `http://localhost:8000/`

### Connect Cloudflare

In the admin panel, add a Cloudflare account (tenant). Click **"Cloudflare token
oluştur"** — it opens Cloudflare's token page with the exact permissions pre-selected
(Email Routing: Edit, Email Sending: Edit, Zone: Read). Create the token, paste it,
and the app auto-detects your account. Then **Full Sync**, then **Deploy Worker**.

## Inbound Worker

The inbound Email Worker lives in [`cf/`](cf/) and is deployed from Laravel:

```bash
php artisan cf:deploy-worker {tenant}   # render wrangler.toml + deploy + set secret
php artisan cf:worker:status            # up-to-date / drifted / never deployed
php artisan cf:worker:sync              # redeploy drifted tenants (idempotent)
```

## Production (Coolify)

Deploy the whole stack — app + MySQL + Redis + queue worker + scheduler — from the
included [`docker-compose.yaml`](docker-compose.yaml). It builds the
[`Dockerfile`](Dockerfile) (php-fpm + nginx via serversideup/php, plus Node +
`wrangler` so the inbound Worker deploys from the container).

1. **Build Pack: `Docker Compose`** (Configuration → General). Set Docker Compose
   Location to `/docker-compose.yaml`. (Not Nixpacks — it would ship without
   `wrangler` and break Deploy-Worker.)
2. **Environment Variables** — only the attachment (R2/S3) secrets are needed, and
   even those are optional (mail send/receive works without them; only storing
   attachments needs them):
   ```env
   AWS_ACCESS_KEY_ID=…       AWS_SECRET_ACCESS_KEY=…
   AWS_BUCKET=…              AWS_ENDPOINT=https://<id>.r2.cloudflarestorage.com
   ```
   Everything else is automatic: Coolify generates & persists `APP_KEY`
   (`SERVICE_REALBASE64_APPKEY`), the domain (`SERVICE_URL_APP` → `APP_URL`), and the
   MySQL password (`SERVICE_PASSWORD_MYSQL`); `VAPID_SUBJECT` defaults to the app URL.
   MySQL, Redis, the queue worker and the scheduler come up on their own; migrations
   run on boot (serversideup AUTORUN). Web Push is off until you set a VAPID keypair
   (see below).
3. **Deploy**, then create your admin user from the Coolify **Terminal** with your own
   credentials (don't use the insecure default seeder in production):
   ```bash
   php artisan app:create-admin you@yourdomain.com --name="You"
   ```
   It prompts for a password, then you can log in at `https://<your-domain>/admin`.
4. Deploy the inbound Worker from the admin panel's **Deploy Worker** action (or the
   Coolify Terminal: `php artisan cf:worker:sync`).

`APP_KEY` and `VAPID_SUBJECT` are generated automatically. Attachments need the
`AWS_*` (R2/S3) vars; mail send/receive works without them.

### Enabling Web Push notifications (optional)

Push is off until you set a VAPID keypair. It can't be auto-generated (it's a stable
EC keypair — rotating it breaks every existing subscription), so generate it **once**
and set two env vars:

1. In the Coolify **Terminal**, run (the `--show` flag prints the keys instead of
   writing a `.env`, which the container doesn't have):
   ```bash
   php artisan webpush:vapid --show
   ```
   It prints a `VAPID_PUBLIC_KEY` and `VAPID_PRIVATE_KEY`.
2. Add both to Coolify → **Environment Variables** and redeploy.

**`VAPID_SUBJECT` must be a `mailto:` or `https://` URL** — it identifies you to the
push services (Apple/iOS is strict and silently rejects an invalid value, so no
notification arrives). Set it to a contact address, e.g.:

```
VAPID_SUBJECT=mailto:you@yourdomain.com
```

If you leave it unset it falls back to `APP_URL` (a valid `https://` URL). A bare
value like `mailbox` is invalid; the app normalises an email-looking value to
`mailto:<email>` and otherwise falls back to `APP_URL`, but set it explicitly to be safe.

Keep the keys stable across deploys (rotating them invalidates every existing
subscription — users must re-enable notifications). On iOS (16.4+), push only works
once the user installs the PWA to the Home Screen (Add to Home Screen), opens it from
the Home Screen icon, and grants permission via the in-app "Bildirimleri aç" prompt.

### Notes

- **APP_URL must be your real public domain** — it is both the inbound webhook base
  (`{APP_URL}/api/cf/incoming`) and the SPA service-worker scope. The compose file
  wires it from Coolify's generated domain automatically.
- Tenant Cloudflare tokens and webhook secrets live **encrypted in the database**,
  never in env.
- `php artisan cf:deploy-worker` / `cf:worker:sync` need outbound HTTPS to Cloudflare
  from the container (allowed on standard Coolify networking).
- Prefer a managed/external database? You can instead use the `Dockerfile` build pack
  and add MySQL/Redis as separate Coolify resources, wiring `DB_*`/`REDIS_*` env vars
  yourself — but the compose stack above is simpler and self-contained.

## Testing

```bash
php artisan test        # 54 feature tests
vendor/bin/pint         # code style
```

## License

MIT.
