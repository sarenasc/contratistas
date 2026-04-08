FROM php:8.2-apache

# ── Sistema base ──────────────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
        curl \
        gnupg2 \
        apt-transport-https \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# ── Microsoft ODBC Driver 17 para SQL Server ──────────────────────────────────
RUN curl -sSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor \
        -o /usr/share/keyrings/microsoft.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/microsoft.gpg] \
        https://packages.microsoft.com/debian/12/prod bookworm main" \
        > /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y msodbcsql17 unixodbc-dev \
    && rm -rf /var/lib/apt/lists/*

# ── Extensiones PHP ───────────────────────────────────────────────────────────
RUN pecl install sqlsrv pdo_sqlsrv \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd zip

# ── Apache ────────────────────────────────────────────────────────────────────
RUN a2enmod rewrite

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/apache/contratista.conf /etc/apache2/sites-available/contratista.conf
RUN a2ensite contratista.conf \
    && a2dissite 000-default.conf

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ── Código fuente ─────────────────────────────────────────────────────────────
WORKDIR /var/www/html/contratista

COPY . .

# Instalar dependencias PHP (sin devtools, con autoloader optimizado)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# ── Permisos ──────────────────────────────────────────────────────────────────
RUN mkdir -p storage/asistencia \
    && chown -R www-data:www-data storage \
    && chmod -R 775 storage

EXPOSE 80
