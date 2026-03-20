FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
    && apt-get install -y git unzip libzip-dev \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock* ./
COPY src ./src
COPY public ./public
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

EXPOSE 8081
ENTRYPOINT ["/entrypoint.sh"]
