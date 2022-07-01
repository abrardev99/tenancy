FROM shivammathur/node:latest

WORKDIR /var/www/html

LABEL org.opencontainers.image.source=https://github.com/stancl/tenancy \
    org.opencontainers.image.vendor="Samuel Štancl" \
    org.opencontainers.image.licenses="MIT" \
    org.opencontainers.image.title="PHP ${PHP_VERSION} with modules for laravel support" \
    org.opencontainers.image.description="PHP ${PHP_VERSION} with a set of php/os packages suitable for running Laravel apps"