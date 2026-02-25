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

# Install dependencies (if composer.lock exists)
RUN if [ -f composer.lock ]; then \
    composer install --no-dev --optimize-autoloader --no-interaction; \
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
