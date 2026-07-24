# syntax=docker/dockerfile:1

# ---- Stage 1: build frontend assets ---------------------------------------
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund --legacy-peer-deps
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- Stage 2: PHP runtime (php-fpm + nginx) -------------------------------
# serversideup/php bundles php-fpm + nginx + a production entrypoint.
FROM serversideup/php:8.4-fpm-nginx

USER root

# Node + wrangler are required so `php artisan cf:deploy-worker` / `cf:worker:sync`
# can deploy the inbound Email Worker from the running container.
RUN install-php-extensions bcmath gd intl pdo_mysql zip \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g wrangler \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

# Pre-install the inbound Worker's dependencies (postal-mime, wrangler) so
# `php artisan cf:deploy-worker` can bundle & deploy without a cold npm install.
RUN cd cf && npm install --no-audit --no-fund || true

RUN composer run-script post-autoload-dump --no-interaction || true

ENV PHP_OPCACHE_ENABLE=1

# serversideup automations (migrations, storage link, config cache) are enabled
# per-service in docker-compose.yaml — only on the `app` service, so the worker
# and scheduler never race migrations. The image already defines the entrypoint
# (php-fpm + nginx on :8080) and a HEALTHCHECK; we don't override them.
