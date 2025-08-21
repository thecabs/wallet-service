# ---------- Vendors / Build (même base que le runtime) ----------
FROM php:8.2-cli-alpine AS vendor

# Outils + librairies nécessaires à Composer et aux extensions
RUN apk add --no-cache git unzip icu-dev oniguruma-dev libzip-dev zlib-dev $PHPIZE_DEPS \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) mbstring pdo_mysql bcmath intl zip opcache

# Composer depuis l'image officielle
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_MEMORY_LIMIT=-1

# (Optionnel) Débloquer les rate-limits GitHub lors d'install de packages privés
ARG GITHUB_TOKEN
RUN if [ -n "$GITHUB_TOKEN" ]; then composer config -g github-oauth.github.com "$GITHUB_TOKEN"; fi

WORKDIR /app

# Étape cache-friendly: d'abord les manifests Composer
COPY composer.json composer.lock ./

# Installer sans scripts pour éviter les hooks Laravel tant que l'env n'est pas prêt
# Utilise un cache Composer pour accélérer les builds CI
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --prefer-dist --no-scripts --optimize-autoloader

# Copier le code applicatif
COPY . .

# Optimiser l'autoloader (toujours sans scripts ici)
RUN composer dump-autoload --no-dev --optimize

# ---------- Runtime PHP-FPM ----------
FROM php:8.2-fpm-alpine AS app

# Extensions PHP pour Laravel (inclut mbstring + zip)
RUN apk add --no-cache icu-dev oniguruma-dev libzip-dev zlib-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) mbstring pdo_mysql bcmath intl zip opcache

WORKDIR /var/www

# Copier l'application "buildée"
COPY --from=vendor /app /var/www

# Permissions minimales
RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm","-F"]
