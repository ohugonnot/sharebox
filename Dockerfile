FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        nginx \
        ffmpeg \
        apache2-utils \
        bash \
        sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && mkdir -p /data /media /run/nginx

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/entrypoint.sh /entrypoint.sh

COPY . /app

VOLUME ["/data", "/media"]
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
