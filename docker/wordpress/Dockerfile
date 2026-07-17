FROM wordpress:6.4-php8.2-fpm

# Install all dependencies in single RUN to reduce layers
RUN apt-get update && apt-get install -y --no-install-recommends \
    mariadb-client \
    ghostscript \
    git \
    vim \
    curl \
    wget \
    build-essential \
    libfreetype6-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j$(nproc) gd zip intl bcmath pdo_mysql mysqli \
    && pecl install redis xdebug \
    && docker-php-ext-enable redis xdebug \
    && apt-get purge -y --auto-remove build-essential \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
    chmod +x wp-cli.phar && \
    mv wp-cli.phar /usr/local/bin/wp

# Create directories and set permissions
RUN mkdir -p /var/www/html/wp-content && \
    mkdir -p /var/log/php-fpm && \
    touch /var/www/html/wp-content/debug.log && \
    chown -R www-data:www-data /var/www/html /var/log/php-fpm

# Set working directory
WORKDIR /var/www/html

# Copy custom PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/pharmasure.ini

# Validate the FPM configuration. FPM speaks FastCGI on port 9000, not HTTP.
HEALTHCHECK --interval=15s --timeout=10s --retries=5 \
    CMD php-fpm -t || exit 1

EXPOSE 9000

CMD ["php-fpm"]
