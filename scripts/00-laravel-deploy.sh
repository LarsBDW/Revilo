#!/bin/bash

# Zorg dat configuratie gecached wordt voor snelheid
echo "Caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Draai de database migraties automatisch en geforceerd
echo "Running migrations..."
php artisan migrate --force
