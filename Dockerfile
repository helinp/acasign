FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev

FROM dunglas/frankenphp:1-php8.4-bookworm
RUN install-php-extensions pdo_pgsql gd intl zip opcache
# qpdf : normalise les PDF 1.5+ (xref compressées) avant lecture par FPDI.
RUN apt-get update && apt-get install -y --no-install-recommends qpdf && rm -rf /var/lib/apt/lists/*
# Retire la file capability cap_net_bind_service posée par l'image de base :
# incompatible avec cap_drop: [ALL] + no-new-privileges (exec -> "Operation not permitted").
# Inutile ici puisque Caddy écoute sur 8080 (port non privilégié).
RUN setcap -r /usr/local/bin/frankenphp
# Données/config Caddy dans /tmp (tmpfs) : le conteneur est en read_only.
ENV XDG_CONFIG_HOME=/tmp/config XDG_DATA_HOME=/tmp/data
WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app ./
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY docker/Caddyfile /etc/caddy/Caddyfile
RUN mkdir -p /etc/caddy/conf.d && touch /etc/caddy/conf.d/00-vide.caddy
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
RUN mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s --retries=3 CMD php -r '$s=@fsockopen("127.0.0.1",8080); exit($s?0:1);'
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
