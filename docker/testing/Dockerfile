# syntax=docker/dockerfile:1.7
FROM node:22-bookworm-slim AS browser-dependencies

COPY docker/testing/browser/package.json /opt/dataphyre-browser/package.json

RUN cd /opt/dataphyre-browser \
    && npm install --omit=dev --no-audit --no-fund

FROM php:8.4-cli-bookworm

LABEL org.opencontainers.image.title="Dataphyre testing runtime" \
      org.opencontainers.image.description="Hermetic PHP 8.4 CLI/phpdbg runtime for the first-party Dataphyre test runner"

ENV DEBIAN_FRONTEND=noninteractive
ENV DATAPHYRE_BROWSER_NODE_MODULES=/opt/dataphyre-browser/node_modules \
    CHROME_PATH=/usr/bin/chromium

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
		git \
		chromium \
		chromium-sandbox \
        fonts-dejavu-core \
        fonts-liberation \
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
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        curl \
        gd \
        intl \
        mbstring \
        mysqli \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        pgsql \
        sockets \
        xml \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=browser-dependencies /usr/local/bin/node /usr/local/bin/node
COPY --from=browser-dependencies /opt/dataphyre-browser /opt/dataphyre-browser

RUN cd /opt/dataphyre-browser \
	&& node -e 'for(const dependency of ["puppeteer-core","axe-core","pngjs"]){require(dependency)}' \
	&& chromium --version \
	&& test -u /usr/lib/chromium/chrome-sandbox

RUN { \
      echo 'display_errors=On'; \
      echo 'log_errors=On'; \
      echo 'error_reporting=E_ALL'; \
      echo 'opcache.enable_cli=0'; \
    } > /usr/local/etc/php/conf.d/dataphyre-testing.ini \
    && php -r 'foreach(["bcmath","curl","gd","intl","mbstring","pdo_sqlite","sodium","zip"] as $extension){if(!extension_loaded($extension)){fwrite(STDERR,"Missing extension: $extension\n");exit(1);}}' \
    && command -v phpdbg >/dev/null

WORKDIR /workspace/dataphyre
