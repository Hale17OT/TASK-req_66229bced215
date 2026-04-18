FROM php:8.1-fpm-alpine AS base

RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        autoconf \
        gcc \
        g++ \
        make \
        oniguruma-dev \
        libzip-dev \
        zlib-dev \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
    && apk add --no-cache \
        bash \
        curl \
        unzip \
        git \
        supervisor \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        oniguruma \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        pdo_mysql \
        mbstring \
        zip \
        intl \
        gd \
        opcache \
        sockets \
    && pecl install redis-6.0.2 || true \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

# Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# php.ini overrides
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Supervisor (php-fpm + scheduled-jobs worker)
COPY docker/php/supervisord.conf /etc/supervisor/conf.d/app.conf

WORKDIR /var/www/html

# Vendor Layui at build time so runtime is fully offline
COPY scripts/download-layui.sh /usr/local/bin/download-layui.sh
RUN chmod +x /usr/local/bin/download-layui.sh && /usr/local/bin/download-layui.sh

# Composer install — copy only manifests first to leverage layer cache
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

# Now copy the rest of the application
COPY . /var/www/html

# Run ThinkPHP service discovery so think-migration commands register
RUN php think service:discover || true

RUN mkdir -p runtime storage/attachments storage/exports storage/tmp \
    && chown -R www-data:www-data runtime storage \
    && chmod -R 775 runtime storage

EXPOSE 9000

# Default command runs supervisord (FPM + worker). The `migrate` service
# overrides this in docker-compose.yml with its own entrypoint.
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/app.conf", "-n"]
