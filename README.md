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

A [`Dockerfile`](Dockerfile) is included (php-fpm + nginx via serversideup/php, plus
Node + `wrangler` so the inbound Worker can be deployed from the container).

### Step by step

1. **New Resource → Application → your Git repository.** Pick this repo and the
   branch you want to deploy.
2. **Build Pack: `Dockerfile`** — *not* Nixpacks. Coolify auto-detects the
   `Dockerfile` at the repo root. (Nixpacks would ignore it and ship without
   `wrangler`, breaking the Deploy-Worker feature.)
3. **Port: `8080`** — the serversideup/php image serves on 8080. Set this as the
   app's exposed/port mapping. Health check path: `/up`.
4. **Add a MySQL database** (Coolify → Databases) and, optionally, Redis. Copy the
   connection details into the env below.
5. **Environment variables** (Coolify → Environment Variables):
   ```env
   APP_NAME="Cloudflare Mailbox"
   APP_ENV=production
   APP_DEBUG=false
   APP_KEY=            # generate once locally: php artisan key:generate --show
   APP_URL=https://mail.yourdomain.com   # your real domain (webhook base + SW scope)

   DB_CONNECTION=mysql
   DB_HOST=...         # the Coolify MySQL service host
   DB_PORT=3306
   DB_DATABASE=...
   DB_USERNAME=...
   DB_PASSWORD=...

   QUEUE_CONNECTION=database     # simplest; switch to redis + a worker later
   SESSION_DRIVER=database
   CACHE_STORE=database

   # Attachments (Cloudflare R2 / S3 / MinIO)
   CLOUDFLARE_ATTACHMENTS_DISK=s3
   AWS_ACCESS_KEY_ID=...
   AWS_SECRET_ACCESS_KEY=...
   AWS_DEFAULT_REGION=auto
   AWS_BUCKET=...
   AWS_ENDPOINT=https://<accountid>.r2.cloudflarestorage.com
   AWS_USE_PATH_STYLE_ENDPOINT=true

   # Web Push — generate once locally with: php artisan webpush:generate
   # then copy the two keys here (do NOT rely on writing .env in the container)
   VAPID_PUBLIC_KEY=...
   VAPID_PRIVATE_KEY=...
   VAPID_SUBJECT=mailto:you@yourdomain.com
   ```
6. **Post-deployment command** (Coolify → Configuration → *Post-deployment
   Command*): runs migrations and reconciles inbound Workers when `APP_URL`/secrets
   change:
   ```bash
   php artisan migrate --force && php artisan cf:worker:sync
   ```
7. **Set your custom domain** in Coolify and let it issue TLS. HTTPS is required for
   the PWA / Web Push and for the Cloudflare Worker to reach the webhook.
8. Deploy. Open `https://mail.yourdomain.com/admin`, create the admin user (or run
   `php artisan db:seed` once via Coolify's terminal), then connect Cloudflare.

### Notes

- **APP_URL must be your real public domain** — it is both the inbound webhook base
  (`{APP_URL}/api/cf/incoming`) and the SPA service-worker scope.
- **Queue:** `QUEUE_CONNECTION=database` runs jobs on the next scheduler tick or a
  worker. For instant delivery/notifications add a second Coolify resource running
  `php artisan queue:work`, or start with `sync` for the simplest setup.
- Tenant Cloudflare tokens and webhook secrets live **encrypted in the database**,
  never in env.
- `php artisan cf:deploy-worker` / `cf:worker:sync` need outbound HTTPS to Cloudflare
  from the container (already allowed on standard Coolify networking).

### Prefer Nixpacks?

You can deploy just the Laravel app with Nixpacks, but the container won't have
`wrangler`, so you'd deploy the inbound Worker separately (locally or via CI:
`cd cf && npx wrangler deploy`). The Dockerfile path keeps everything in one place —
recommended.

## Testing

```bash
php artisan test        # 52 feature tests
vendor/bin/pint         # code style
```

## License

MIT.
