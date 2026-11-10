# syntax=docker/dockerfile:1

# =============================================================================
# Image de PRODUCTION pour gateway-for-ebp (Laravel 13 + Inertia/Vue 3).
#
# Build multi-stage :
#   1. vendor  — dépendances PHP (composer, --no-dev)
#   2. assets  — build front (Node 22 ; Vite 8/Rolldown exige Node >= 20.19)
#   3. final   — PHP 8.3-fpm + nginx + supervisor, servant l'application
#
# L'environnement de DÉVELOPPEMENT reste géré par Laravel Sail (compose.yaml) :
# cette image ne s'en sert pas et ne le remplace pas.
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1 — dépendances PHP de production
# -----------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# On copie d'abord les manifestes pour profiter du cache de couche Docker :
# tant que composer.json/lock ne changent pas, l'install n'est pas rejouée.
COPY composer.json composer.lock ./

# --no-dev        : pas de paquets de dev (sail, pint, phpunit…)
# --no-scripts    : le code applicatif n'est pas encore là ; les scripts
#                   (package:discover, vendor:publish) tourneront dans le stage
#                   final via l'autoload optimisé.
# --ignore-platform-reqs : l'image composer peut ne pas avoir toutes les
#                   extensions ; elles sont présentes dans l'image finale.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress \
        --ignore-platform-reqs

# -----------------------------------------------------------------------------
# Stage 2 — build des assets front (Node 22)
# -----------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

# Sources nécessaires au build Vite (config + resources + plugin Laravel).
COPY vite.config.js jsconfig.json components.json ./
COPY resources ./resources

# Le plugin laravel-vite-plugin lit public/ pour écrire le manifeste du build.
RUN npm run build

# -----------------------------------------------------------------------------
# Stage 3 — image finale de production
# -----------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS final

# --- Paquets système + extensions PHP ---------------------------------------
# nginx + supervisor : servent et supervisent le couple nginx/php-fpm.
# Les *-dev servent uniquement à compiler les extensions, puis sont retirés.
RUN apk add --no-cache \
        nginx \
        supervisor \
        curl \
        bash \
        tzdata \
        icu-libs \
        libzip \
        libpng \
        freetype \
        libjpeg-turbo \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpng-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

# --- Configuration PHP / nginx / supervisor ----------------------------------
COPY docker/php.ini           /usr/local/etc/php/conf.d/zz-gateway.ini
COPY docker/php-fpm.conf       /usr/local/etc/php-fpm.d/zz-gateway.conf
COPY docker/nginx.conf         /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf   /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

# --- Code applicatif + dépendances déjà construites --------------------------
# Le code d'abord, puis on écrase par les dépendances buildées.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Autoload optimisé + découverte des packages (avec le code et le vendor en place).
# composer n'est pas livré dans l'image finale : on emprunte le binaire au stage
# `vendor` le temps de régénérer l'autoload, puis on le retire.
COPY --from=vendor /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm -f /usr/bin/composer

# --- Permissions -------------------------------------------------------------
# php-fpm et nginx tournent sous l'utilisateur www-data ; storage et bootstrap/cache
# doivent lui appartenir (le volume app-storage réapplique ensuite au runtime).
# NB : pas d'expansion d'accolades — l'image finale utilise /bin/sh (Alpine),
# qui ne la gère pas (créerait un dossier littéral « {cache,sessions,views} »).
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Entrypoint : amorçage idempotent au boot (voir docker/entrypoint.sh).
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# nginx écoute en HTTP sur 8080 (TLS délégué au reverse-proxy de l'utilisateur).
EXPOSE 8080

ENTRYPOINT ["entrypoint"]
# Par défaut : nginx + php-fpm. Le service horizon surcharge cette commande.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
