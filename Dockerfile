FROM php:8.1-cli-alpine

# System dependencies. Pin them to keep the supply chain auditable
# (the Alpine package index moves, so reproducibility lives in the
# Dockerfile's tag — not the package list).
RUN apk add --no-cache \
        git \
        curl \
        ca-certificates \
        zip \
        unzip \
        tini \
    && docker-php-ext-install pcntl \
    && rm -rf /var/cache/apk/*

# Composer (pinned by the upstream `composer:latest` image tag at build time).
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Composer install with no scripts and locked autoload optimisation.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .

RUN chmod +x bin/suitecrm-mcp-server

# Non-root execution (NSA spec 3.2). UID/GID >= 1000 to avoid clashing
# with typical host privileged ranges. Filesystem is read-only by default
# — operators that need to persist the audit log mount a tmpfs at
# /app/logs in their docker-compose / k8s spec (see SECURITY.md).
RUN adduser -D -u 1000 -g 1000 mcp-user \
    && chown -R mcp-user:mcp-user /app

USER mcp-user

# tini handles signal propagation cleanly so stdio shutdown is graceful.
ENTRYPOINT ["/sbin/tini", "--", "php", "suitecrm-mcp-server.php"]
