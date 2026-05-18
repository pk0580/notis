FROM php:8.4-fpm-alpine as base

# Установка системных зависимостей
RUN apk add --no-cache \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    linux-headers \
    $PHPIZE_DEPS

# Установка PHP расширений
RUN docker-php-ext-install \
    pdo_pgsql \
    bcmath \
    intl \
    sockets \
    pcntl

# Установка Redis расширения
RUN pecl install redis && docker-php-ext-enable redis

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Установка php-fpm-healthcheck
RUN curl -o /usr/local/bin/php-fpm-healthcheck https://raw.githubusercontent.com/renatomefi/php-fpm-healthcheck/master/php-fpm-healthcheck \
    && chmod +x /usr/local/bin/php-fpm-healthcheck \
    && apk add --no-cache fcgi

# Копирование конфига для healthcheck
COPY php_infra_tmp/status.conf /usr/local/etc/php-fpm.d/status.conf

# Удаление временной папки после копирования
RUN rm -rf php_infra_tmp

WORKDIR /var/www/html

FROM base as app

COPY . .

# Установка зависимостей через Composer
RUN composer install --no-interaction --no-plugins --no-scripts --prefer-dist

# Настройка прав для Laravel
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
