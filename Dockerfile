# syntax=docker/dockerfile:1

# ---- Frontend assets ----
# VITE_* values are compiled into the JS bundle here, at build time — they
# can't be changed later by just setting runtime env vars on the container.
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
ARG VITE_APP_NAME=AuxRoom
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME
ENV VITE_APP_NAME=$VITE_APP_NAME \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME
RUN npm run build

# ---- PHP app ----
# Pinned to 8.4 — composer.lock has resolved Symfony packages that actually
# require PHP >=8.4.1, regardless of composer.json's own "php" constraint.
FROM php:8.4-cli-alpine AS app

RUN apk add --no-cache bash libpq-dev icu-dev oniguruma-dev libzip-dev sqlite-dev \
        libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite mbstring bcmath pcntl opcache zip gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Installed before the rest of the source is copied so this layer only
# rebuilds when composer.lock actually changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
COPY --from=assets /app/public/build /app/public/build

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 80 is the web app, 8080 is the Reverb websocket server — both run in this
# one container (see entrypoint.sh). Dokploy needs to route a public
# domain/port to each; see the deployment notes for how.
EXPOSE 80 8080

ENTRYPOINT ["entrypoint.sh"]
