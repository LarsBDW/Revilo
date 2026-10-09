FROM richarvey/nginx-php-fpm:latest

# Copy project files into the container
COPY . /var/www/html

# Set environment variables for production
ENV LAB_ENV=production
ENV APP_ENV=production
ENV WEBROOT=/var/www/html/public

# Run composer deployment scripts
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Fix permissions for Laravel storage and cache directories
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
