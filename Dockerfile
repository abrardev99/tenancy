FROM shivammathur/node:latest

WORKDIR /var/www/html

LABEL org.opencontainers.image.source=https://github.com/stancl/tenancy \
    org.opencontainers.image.vendor="Samuel Štancl" \
    org.opencontainers.image.licenses="MIT"
