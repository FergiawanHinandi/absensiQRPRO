# =============================================================================
# AbsensiQRPro - Multi-stage Dockerfile for Production
# =============================================================================

# Stage 1: Builder - Install dependencies and build assets
FROM php:8.2-fpm-alpine AS builder

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    libpq-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo_pgsql \
    zip \
    mbstring \
    opcache \
    gd \
    intl \
    bcmath \
    exif

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Install dependencies (with dev for testing)
RUN composer install --no-scripts --no-autoloader --no-progress

# Copy application code
COPY . .

# Generate autoloader and optimize
RUN composer dump-autoload --optimize --no-dev

# Stage 2: Production - Final image
FROM php:8.2-fpm-alpine

# Install runtime dependencies
RUN apk add --no-cache \
    supervisor \
    postgresql-client \
    nginx \
    nodejs \
    npm \
    fcgi \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
    pdo_pgsql \
    zip \
    mbstring \
    opcache \
    gd \
    intl \
    bcmath \
    exif

# PHP configuration for production
RUN echo "opcache.enable=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.interned_strings_buffer=8" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.revalidate_freq=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.save_comments=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "opcache.fast_shutdown=1" >> /usr/local/etc/php/conf.d/opcache.ini && \
    echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "upload_max_filesize=10M" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "post_max_size=10M" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "max_execution_time=60" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "max_input_time=60" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "expose_php=Off" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "display_errors=Off" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "log_errors=On" >> /usr/local/etc/php/conf.d/app.ini && \
    echo "error_log=/var/log/php-error.log" >> /usr/local/etc/php/conf.d/app.ini

# Set working directory
WORKDIR /var/www/html

# Copy from builder
COPY --from=builder /var/www/html /var/www/html
COPY --from=builder /usr/local/bin/composer /usr/local/bin/composer

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Copy Supervisor configuration
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create required directories
RUN mkdir -p /var/log/supervisor \
    /var/log/nginx \
    /var/log/php \
    /var/www/html/storage/logs \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/bootstrap/cache

# Set permissions
RUN chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    && chmod -R 775 \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Expose port
EXPOSE 9000

# Start Supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
