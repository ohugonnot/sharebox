FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx \
        ffmpeg \
        bash \
        curl \
        sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && mkdir -p /data /media /run/nginx

# Optional: Intel VAAPI GPU transcoding support
# Uncomment to enable hardware-accelerated encoding on Intel iGPU hosts.
# RUN apk add --no-cache libva-intel-driver intel-media-driver mesa-va-gallium

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/demo-data.sh /docker/demo-data.sh
COPY docker/seed-tmdb.php /docker/seed-tmdb.php
RUN chmod +x /docker/demo-data.sh

COPY . /app

VOLUME ["/data", "/media"]
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
