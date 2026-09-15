#!/bin/sh
set -e

# APP_KEY/DB_*/итд приходят из env_file: .env в docker-compose.prod.yml (реальные
# переменные окружения контейнера) — не из файла .env внутри образа, там его нет
# (см. .dockerignore). Кэшируется при каждом старте контейнера, а не при сборке
# образа: на моменте сборки .env ещё недоступен, а значения зависят от окружения
# (staging/prod), не должны запекаться в сам образ.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
