FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libsqlite3-dev \
        libpq-dev \
        libzip-dev \
        unzip \
        zip \
    && docker-php-ext-install pdo_mysql pdo_pgsql pdo_sqlite zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html

RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html/data \
    && printf '%s\n' \
        'file_uploads=On' \
        'upload_max_filesize=64M' \
        'post_max_size=72M' \
        'max_file_uploads=20' \
        > /usr/local/etc/php/conf.d/character-studio-uploads.ini \
    && printf '%s\n' \
        '<Directory /var/www/html>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/character-studio.conf \
    && a2enconf character-studio

HEALTHCHECK --interval=30s --timeout=12s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/api/health >/dev/null || exit 1
