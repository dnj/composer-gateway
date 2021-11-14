FROM ghcr.io/dnj/laravel-alpine:8.0-mysql-nginx
COPY --chown=www-data . /var/www/

RUN composer install --no-dev --optimize-autoloader && \
	composer clear-cache && \
	rm -f /etc/supervisor.d/worker.ini