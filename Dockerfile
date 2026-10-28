FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx \
        ffmpeg \
        bash \
        shadow \
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
COPY docker/demo-bootstrap.php /docker/demo-bootstrap.php
RUN chmod +x /docker/demo-data.sh

COPY . /app

VOLUME ["/data", "/media"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1/share/login.php || exit 1

ENTRYPOINT ["/entrypoint.sh"]
