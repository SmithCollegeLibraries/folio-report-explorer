FROM php:7.2-fpm

# Switch to archived Debian Buster repos (EOL)
RUN sed -i 's|http://deb.debian.org/debian|http://archive.debian.org/debian|g' /etc/apt/sources.list \
    && sed -i 's|http://security.debian.org/debian-security|http://archive.debian.org/debian-security|g' /etc/apt/sources.list \
    && sed -i '/buster-updates/d' /etc/apt/sources.list \
    && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    unzip \
    git \
    && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    pdo_mysql \
    zip \
    intl \
    bcmath \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock* ./

# Install dependencies only when explicitly requested.
# Default is OFF because some hosts hit CPU/emulation issues during composer install
# (Illegal instruction) and this repository already includes vendor dependencies.
ARG RUN_COMPOSER_INSTALL=0
RUN if [ "$RUN_COMPOSER_INSTALL" = "1" ] && [ -f composer.lock ]; then \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction; \
    else \
    echo "Skipping composer install at build time (RUN_COMPOSER_INSTALL=${RUN_COMPOSER_INSTALL})."; \
    fi

# Copy application code
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html/web \
    && mkdir -p runtime data \
    && chmod 777 runtime \
    && chown www-data:www-data data \
    && chmod 775 data \
    && if [ -f yii ]; then chmod +x yii; fi

EXPOSE 9000

CMD ["php-fpm"]
