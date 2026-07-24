# Inbound Email Worker

Cloudflare Email Worker that parses inbound mail and posts it to the Laravel
webhook (`/api/cf/incoming`) with a per-account HMAC signature.

**You normally do not run anything here by hand.** Laravel owns configuration and
deployment:

```bash
php artisan cf:deploy-worker {tenant}   # renders wrangler.toml + deploys + sets secret
php artisan cf:worker:status            # who is up-to-date / drifted / never deployed
php artisan cf:worker:sync              # redeploy tenants whose config drifted
```

`wrangler.toml` is generated from `wrangler.toml.stub` and Laravel config, so it is
git-ignored. `WEBHOOK_SECRET` is pushed as a Wrangler secret (never a plaintext var).

## Requirements

- Node.js + `wrangler` available on the host (`npx wrangler`).
- The deploy command authenticates wrangler with the tenant's `CLOUDFLARE_API_TOKEN`.

## Manual deploy (fallback)

```bash
cd cf
npm install
# render wrangler.toml yourself from wrangler.toml.stub, then:
CLOUDFLARE_API_TOKEN=... npx wrangler deploy
echo -n "$SECRET" | CLOUDFLARE_API_TOKEN=... npx wrangler secret put WEBHOOK_SECRET
```

## Local testing

- `npx wrangler dev` exposes a local endpoint you can POST a raw email to.
- Or test the Laravel side directly against `POST /api/cf/incoming` with a signed
  payload (see `tests/Feature/IncomingEmailTest.php`).
