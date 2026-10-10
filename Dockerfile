FROM richarvey/nginx-php-fpm:latest

# Kopieer alle projectbestanden naar de container
COPY . /var/www/html

# Omgevingsvariabelen voor productie
ENV LAB_ENV=production
ENV APP_ENV=production
ENV WEBROOT=/var/www/html/public

# Vertel de richarvey-image om ons script te draaien bij de start
ENV RUN_SCRIPTS=1
ENV CUSTOM_SCRIPT_PATH=/var/www/html/scripts/00-laravel-deploy.sh

# Installeer composer pakketten voor productie
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Rechten goedzetten voor Laravel opslag
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
