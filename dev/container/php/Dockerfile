# syntax=docker/dockerfile:1.7
FROM php:8.4-cli-bookworm AS extensions

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        git \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        postgresql-client \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        pgsql \
        sockets \
        xml \
        zip \
    && rm -rf /var/lib/apt/lists/*

FROM composer:2.8.10 AS composer

FROM php:8.4-cli-bookworm

LABEL org.opencontainers.image.title="Dataphyre development runtime"
LABEL org.opencontainers.image.source="https://github.com/jeremie5/dataphyre"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libcurl4 \
        libfreetype6 \
        libicu72 \
        libjpeg62-turbo \
        libonig5 \
        libpng16-16 \
        libpq5 \
        libsqlite3-0 \
        libxml2 \
        libzip4 \
        postgresql-client \
        unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

COPY dev/container/php/php.ini /usr/local/etc/php/conf.d/dataphyre-development.ini
COPY runtime /opt/dataphyre/runtime

RUN install -d /workspace/applications

WORKDIR /workspace
