# syntax=docker/dockerfile:1.7
FROM node:22-bookworm-slim AS browser-dependencies

COPY docker/testing/browser/package.json /opt/dataphyre-browser/package.json

RUN cd /opt/dataphyre-browser \
    && npm install --omit=dev --no-audit --no-fund

FROM php:8.4-fpm-bookworm AS php-fpm-runtime

FROM php:8.4-cli-bookworm

ARG XDEBUG_VERSION=3.5.3

LABEL org.opencontainers.image.title="Dataphyre testing runtime" \
      org.opencontainers.image.description="Hermetic PHP 8.4 CLI/phpdbg/FPM runtime for the first-party Dataphyre test runner"

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
		util-linux \
		$PHPIZE_DEPS \
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
	&& pecl install "xdebug-${XDEBUG_VERSION}" \
	&& extension_dir="$(php-config --extension-dir)" \
	&& test -f "$extension_dir/xdebug.so" \
	&& install -d -o root -g root -m 0755 /usr/local/etc/php-xdebug.d \
	&& printf 'zend_extension=%s/xdebug.so\nxdebug.mode=off\n' "$extension_dir" \
		> /usr/local/etc/php-xdebug.d/99-xdebug.ini \
    && rm -rf /var/lib/apt/lists/*

# The test runtime exercises the same post-exec inherited-descriptor boundary
# as Cloud's fixed PHP image. Build only the framework-owned native extension;
# application code and caller-selected build input never participate.
COPY runtime/native/environment_fd /tmp/dataphyre-environment-fd

RUN set -eux; \
	cd /tmp/dataphyre-environment-fd; \
	phpize; \
	./configure --enable-dataphyre-environment-fd; \
	make -j"$(nproc)"; \
	make install; \
	docker-php-ext-enable dataphyre_environment_fd; \
	cd /; \
	rm -rf /tmp/dataphyre-environment-fd; \
	php -r 'exit(extension_loaded("dataphyre_environment_fd") && phpversion("dataphyre_environment_fd") === "1.2.0" && function_exists("dataphyre_open_inherited_environment_fd") && function_exists("dataphyre_close_inherited_fd") && function_exists("dataphyre_close_unlisted_inherited_fds") && function_exists("dataphyre_enable_scheduler_child_subreaper") && function_exists("dataphyre_managed_pool_request_context") ? 0 : 1);'

# Keep the test image's CLI/phpdbg toolchain while adding the exact matching FPM
# SAPI binary needed to exercise Dataphyre's persistent managed-web boundary.
COPY --from=php-fpm-runtime /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm

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
	&& php -r 'foreach(["bcmath","curl","dataphyre_environment_fd","gd","intl","mbstring","pdo_sqlite","sodium","zip"] as $extension){if(!extension_loaded($extension)){fwrite(STDERR,"Missing extension: $extension\n");exit(1);}} if(extension_loaded("xdebug")){fwrite(STDERR,"Xdebug must be opt-in.\n");exit(1);}' \
	&& PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/usr/local/etc/php-xdebug.d XDEBUG_MODE=off php -r 'exit(extension_loaded("xdebug")&&phpversion("xdebug")==="3.5.3"?0:1);' \
	&& command -v phpdbg >/dev/null \
	&& php-fpm -v \
	&& test "$(php -r 'echo PHP_VERSION;')" = "$(php-fpm -i | sed -n 's/^PHP Version => //p' | head -n 1)"

WORKDIR /workspace/dataphyre
