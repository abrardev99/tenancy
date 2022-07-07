ARG PHP_VERSION=7.4
ARG PHP_TARGET=php:${PHP_VERSION}-cli

FROM setupphp/node:latest

WORKDIR /var/www/html

LABEL org.opencontainers.image.source=https://github.com/stancl/tenancy \
    org.opencontainers.image.vendor="Samuel Štancl" \
    org.opencontainers.image.licenses="MIT" \
    org.opencontainers.image.title="PHP ${PHP_VERSION} with modules for laravel support" \
    org.opencontainers.image.description="PHP ${PHP_VERSION} with a set of php/os packages suitable for running Laravel apps"

# our default timezone and langauge
ENV TZ=Europe/London
ENV LANG=en_GB.UTF-8

RUN curl https://packages.microsoft.com/keys/microsoft.asc | apt-key add - \
    && curl https://packages.microsoft.com/config/debian/9/prod.list > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && apt-get -y --no-install-recommends install msodbcsql17 unixodbc-dev \
    && pecl install sqlsrv \
    && pecl install pdo_sqlsrv \
    && echo "extension=pdo_sqlsrv.so" >> `php --ini | grep "Scan for additional .ini files" | sed -e "s|.*:\s*||"`/30-pdo_sqlsrv.ini \
    && echo "extension=sqlsrv.so" >> `php --ini | grep "Scan for additional .ini files" | sed -e "s|.*:\s*||"`/30-sqlsrv.ini \
    && apt-get clean; rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends libhiredis0.14 libjemalloc2 liblua5.1-0 lua-bitop lua-cjson redis redis-server redis-tools

RUN apt-get update \
    && pecl install redis \
    && echo extension='"redis.so"' >> /etc/php/8.1/cli/php.ini
# set the system timezone
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime \
    && echo $TZ > /etc/timezone
