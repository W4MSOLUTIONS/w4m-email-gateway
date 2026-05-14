#!/usr/bin/env sh
set -eu

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

vendor/bin/phpunit -c phpunit.xml.dist "$@"
