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
php artisan webpush:generate        # VAPID keys for Web Push
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

A [`Dockerfile`](Dockerfile) is included (PHP + Node + wrangler + FrankenPHP).

1. Point Coolify at this repo; it builds the Dockerfile.
2. Set env: `APP_KEY`, `APP_URL` (your real domain — the webhook base), `DB_*` (MySQL),
   S3 keys (`AWS_*`, `AWS_ENDPOINT`), `CLOUDFLARE_ATTACHMENTS_DISK=s3`, optionally
   `QUEUE_CONNECTION=redis`.
3. Post-deployment command (reconciles Workers when `APP_URL`/secrets change):
   ```bash
   php artisan migrate --force && php artisan cf:worker:sync
   ```
4. Run a queue worker (`php artisan queue:work`) and the scheduler as separate
   processes/services.

Tenant Cloudflare tokens and webhook secrets live encrypted in the database, never in
env.

## Testing

```bash
php artisan test        # 52 feature tests
vendor/bin/pint         # code style
```

## License

MIT.
