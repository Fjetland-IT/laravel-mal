# PHP 8.5-FPM for det lokale Docker-miljøet (compose.yaml, tjenesten php). Utvidelsene tilsvarer
# pakkene på vps1 (Fjetland-IT/vps, bootstrap.sh og app-oppsett.sh):
#   php8.5-fpm/-cli/-common  → bildet har opcache, sodium, ctype, fileinfo, iconv, tokenizer osv.;
#                              exif, calendar, gettext, sockets og pcntl legges til
#   php8.5-mysql             → pdo_mysql, mysqli
#   php8.5-mbstring, -curl   → i bildet
#   php8.5-xml               → dom, simplexml, xml, xmlreader, xmlwriter i bildet; xsl legges til
#   php8.5-zip, -gd, -intl, -bcmath
#   php8.5-sqlite3           → pdo_sqlite, sqlite3 i bildet
#   php-redis                → redis (PECL)
# Composer følger med. Ingen kode og ingen hemmeligheter i bildet; koden monteres inn.
FROM php:8.5-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git unzip \
        libfreetype-dev libicu-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libxslt1-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath calendar exif gd gettext intl mysqli pcntl pdo_mysql sockets xsl zip; \
    php -m | grep -qx 'Zend OPcache' || docker-php-ext-install opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear /var/lib/apt/lists/*; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    install -d -m 1777 /composer-cache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY lokal.ini /usr/local/etc/php/conf.d/zz-lokal.ini

WORKDIR /var/www/html
