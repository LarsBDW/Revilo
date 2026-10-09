<?php

// Creates development-only secrets locally. Never prints passwords or modifies global services.
$root = dirname(__DIR__);
$environment = <<<'ENV'
APP_NAME=Revilo
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_LOCALE=nl
APP_FALLBACK_LOCALE=nl
APP_FAKER_LOCALE=nl_NL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=revilo
DB_USERNAME=revilo
DB_PASSWORD=__PASSWORD__
MYSQL_ROOT_PASSWORD=__ROOT_PASSWORD__
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_LIFETIME=43200
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug
MAIL_MAILER=log
MAIL_FROM_ADDRESS=preview@example.test
MAIL_FROM_NAME=Revilo
TEST_CHECKOUT_ENABLED=true
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
SHOP_CONTACT_EMAIL=
ENV;
if (is_file($root.'/.env') && str_contains(file_get_contents($root.'/.env'), 'APP_NAME=Revilo')) {
    echo "Local environment already prepared.\n";
    exit;
}
file_put_contents($root.'/.env', str_replace(['__PASSWORD__', '__ROOT_PASSWORD__'], [bin2hex(random_bytes(24)), bin2hex(random_bytes(24))], $environment));
echo "Local development environment prepared with generated secrets.\n";
