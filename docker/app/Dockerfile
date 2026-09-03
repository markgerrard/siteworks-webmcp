# Pinned so the runtime cannot drift under composer's platform config; composer.json's
# config.platform.php is a resolution FLOOR, not the runtime.
FROM php:8.4.24-fpm

# Node 20.x is required for the per-surface Vite build step below.
# php:8.4-fpm doesn't ship with node/npm; install via NodeSource alongside
# the existing PHP-side apt deps so the build runs in one layer.
RUN apt-get update && apt-get install -y curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y \
        git unzip libpq-dev libzip-dev libsqlite3-dev sqlite3 nginx supervisor \
        libmagickwand-dev imagemagick \
        nodejs \
    && docker-php-ext-install pdo_pgsql pdo_sqlite zip pcntl sockets \
    && pecl install redis && docker-php-ext-enable redis \
    && pecl install imagick && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .
# package:discover needs an APP_KEY; the committed .env.demo has none on
# purpose (entrypoint generates one into the sqlite volume). A throwaway
# key is enough for the image build.
ENV APP_KEY=base64:AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA=
RUN composer install --no-dev --optimize-autoloader --no-interaction
RUN npm ci
ARG SURFACE=all
RUN npm run build:${SURFACE}
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views/livewire/classes \
        storage/framework/views/livewire/views \
        storage/framework/views/livewire/scripts \
        storage/framework/views/livewire/styles \
        storage/framework/views/livewire/placeholders \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Per-surface nginx: entrypoint installs site-public (also SURFACE=all) vs
# default at boot. Default preserves try_files static robots; site-public
# forces /robots.txt → PHP.
COPY docker/app/nginx.conf /etc/nginx/sites-available/nginx.default
COPY docker/app/nginx.site-public.conf /etc/nginx/sites-available/nginx.site-public
RUN cp /etc/nginx/sites-available/nginx.default /etc/nginx/sites-available/default
COPY docker/app/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/app/php-fpm-overrides.conf /usr/local/etc/php-fpm.d/zzz-overrides.conf
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n"]
