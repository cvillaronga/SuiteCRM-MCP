FROM php:8.1-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    zip \
    unzip \
    && docker-php-ext-install pcntl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock* ./

# Install PHP dependencies
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# Copy application files
COPY . .

# Make the binary executable
RUN chmod +x bin/suitecrm-mcp-server

# Create a non-root user
RUN adduser -D -u 1000 mcp-user && \
    chown -R mcp-user:mcp-user /app

# Switch to non-root user
USER mcp-user

# Set the entrypoint
ENTRYPOINT ["php", "suitecrm-mcp-server.php"]