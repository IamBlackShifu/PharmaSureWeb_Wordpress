FROM wordpress:6.4-php8.2-fpm-alpine

# Install additional PHP extensions and development tools
RUN apk add --no-cache \
    mysql-client \
    imagemagick \
    imagemagick-dev \
    ghostscript \
    git \
    subversion \
    less \
    vim \
    curl \
    wget \
    ca-certificates \
    openssh-client \
    gettext

# Install pecl extensions
RUN docker-php-ext-install -j$(nproc) \
    mysqli \
    pdo_mysql \
    gd \
    gettext \
    zip \
    bcmath

# Install imagick via pecl
RUN pecl install imagick && \
    docker-php-ext-enable imagick

# Install redis extension
RUN pecl install redis && \
    docker-php-ext-enable redis

# Install xdebug for debugging
RUN pecl install xdebug && \
    docker-php-ext-enable xdebug

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && \
    chmod +x wp-cli.phar && \
    mv wp-cli.phar /usr/local/bin/wp

# Create log directory
RUN mkdir -p /var/www/html/wp-content && \
    touch /var/www/html/wp-content/debug.log && \
    chown -R www-data:www-data /var/www/html

# Set working directory
WORKDIR /var/www/html

# Copy custom PHP configuration
COPY php.ini /usr/local/etc/php/conf.d/pharmasure.ini

# Health check
HEALTHCHECK --interval=15s --timeout=10s --retries=5 \
    CMD curl -f http://localhost:9000/wp-json/ || exit 1

EXPOSE 9000

CMD ["php-fpm"]
