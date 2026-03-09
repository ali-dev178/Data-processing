#!/bin/sh
set -e

LARAVEL_DIR=/var/www

rm -rf $LARAVEL_DIR/* $LARAVEL_DIR/.* 2>/dev/null || true
composer create-project --prefer-dist --no-scripts laravel/laravel /tmp/laravel-install --quiet
cp -a /tmp/laravel-install/. $LARAVEL_DIR/
rm -rf /tmp/laravel-install

cp -r /app/app/* $LARAVEL_DIR/app/
cp -r /app/config/processing.php $LARAVEL_DIR/config/
cp -r /app/database/migrations/* $LARAVEL_DIR/database/migrations/
cp -r /app/routes/api.php $LARAVEL_DIR/routes/api.php
cp -r /app/tests/* $LARAVEL_DIR/tests/

sed -i "s|health: '/up',|api: __DIR__.'/../routes/api.php',\n        health: '/up',|" $LARAVEL_DIR/bootstrap/app.php
sed -i '/<env name="DB_CONNECTION"/d' $LARAVEL_DIR/phpunit.xml
sed -i '/<env name="DB_DATABASE"/d' $LARAVEL_DIR/phpunit.xml

cp /app/env.example $LARAVEL_DIR/.env
cd $LARAVEL_DIR && composer run-script post-autoload-dump --quiet
php $LARAVEL_DIR/artisan key:generate --quiet
php $LARAVEL_DIR/artisan migrate --force