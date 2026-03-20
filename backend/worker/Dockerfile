FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y git unzip libzip-dev librdkafka-dev \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock* ./
COPY entrypoint.sh /entrypoint.sh
COPY producer.php ./producer.php
COPY consumer.php ./consumer.php

RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
