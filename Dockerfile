FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        supervisor \
        postgresql-dev \
        librdkafka-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql bcmath zip pcntl \
    && pecl install rdkafka redis \
    && docker-php-ext-enable rdkafka redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /app

EXPOSE 8000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
