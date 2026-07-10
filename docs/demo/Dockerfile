# SPDX-FileCopyrightText: 2026 Nick Manning
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# PHP runtime for the demo rig: runs THIS source checkout (mounted at
# /var/www/html), serving the web UI via PHP's built-in server (fine for a
# demo) and providing `php occ` + the import spike. PHP 8.3 per
# lib/versioncheck.php (>= 8.3, < 8.6).
FROM php:8.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libgmp-dev \
        libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        gd zip intl bcmath gmp pdo_mysql opcache pcntl exif sysvsem \
    && pecl install apcu && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/*

# Generous CLI limits for scan/import benchmarking
RUN { \
        echo 'memory_limit=2G'; \
        echo 'apc.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/demo.ini

WORKDIR /var/www/html
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080"]
