# Service Ops

Telegram-бот для распределения выездных заказов между мастерами сервиса ремонта бытовой техники.

Полное ТЗ — в `docs/` (если добавишь) или в исходных документах проекта. Ниже — только то, что нужно, чтобы поднять окружение и начать работать.

## Стек

Laravel 13 (PHP 8.4) · PostgreSQL 16 · Redis 7 (очереди) · Docker Compose. Интерфейс — только Telegram-бот, отдельной веб-админки нет.

## Быстрый старт

```bash
git clone <repo> .
cp .env.example .env   # уже сделано в этом репозитории, .env в .gitignore
docker compose up -d --build
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

Сиды создают справочники (техника/бренды/гео-зоны/слоты — гео-зоны примерные, поправь под свой регион) и, если задан `TELEGRAM_OWNER_ID`, выдают тебе роль SUPERADMIN.

## Добавление мастера

```bash
docker compose exec app php artisan master:add <telegram_id> "<Имя>" "<Телефон>" \
    --appliance="Холодильник" --appliance="Стиральная машина" \
    --brand="Bosch" --zone="Одинцово"
```

`--appliance` обязателен (можно указать несколько раз) — `MasterMatcher` фильтрует специализацию жёстко, мастер без неё не попадёт ни в одну подборку. `--brand`/`--zone` необязательны. Названия — как в справочниках (`appliance_types`/`brands`/`geo_zones`), регистр и написание должны совпадать.

Заводить новых ADMIN/SUPERADMIN отдельной командой не стали — это редкое действие, `tinker`/прямой SQL остаётся нормальным вариантом.

## Настройка бота

1. Получи токен у [@BotFather](https://t.me/BotFather), впиши в `TELEGRAM_BOT_TOKEN`.
2. Узнай свой `telegram_user_id` у [@userinfobot](https://t.me/userinfobot), впиши в `TELEGRAM_OWNER_ID`.
3. Придумай случайную строку для `TELEGRAM_WEBHOOK_SECRET` (например `openssl rand -hex 20`).
4. Пересоздай сида: `docker compose exec app php artisan db:seed --class=OwnerSeeder`.
5. Для Internal API (`/api/v1/...`) — такую же случайную строку в `INTERNAL_API_KEY`, передаётся заголовком `X-Internal-Api-Key`. Пока используется только эндпоинтом смены статуса, но защищён с самого начала.

## Локальная разработка — без вебхука

Публичный HTTPS для вебхука на локальной машине поднимать не нужно (ТЗ п.94 — polling допустим на local/dev). Вместо этого:

```bash
docker compose exec app php artisan telegram:poll
```

Команда сама снимает вебхук и слушает апдейты через `getUpdates`. Останови и просто напиши боту `/start`.

## Продакшн-вебхук

```bash
php artisan telegram:webhook set https://your-domain.com/api/telegram/webhook
php artisan telegram:webhook info
php artisan telegram:webhook delete
```

`telegram:webhook set` откажется от не-HTTPS адреса сразу, не дожидаясь невнятной ошибки от Bot API.

## Бэкапы

```bash
docker compose exec app php artisan db:backup                # снять дамп (по умолчанию хранить 30 дней)
docker compose exec app php artisan db:restore <файл> --force # восстановить (ПЕРЕЗАПИСЫВАЕТ текущие данные)
```

Дамп собирается с `--clean --if-exists`, поэтому `db:restore` безопасно гонять поверх уже существующей базы той же схемы — так и стоит регулярно проверять, что бэкап реально восстанавливается (ТЗ п.106 требует сделать это хотя бы раз до продакшна). Запланирован на 03:00 ежедневно через `routes/console.php` (`Schedule::command('db:backup')`) — в проде нужен один cron-триггер на весь планировщик Laravel:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Мониторинг

`php artisan system:health-check` проверяет вебхук (ошибки, застрявшие апдейты), Redis и БД; при проблеме шлёт алерт `TELEGRAM_OWNER_ID` и репортит в Sentry, если задан `SENTRY_LARAVEL_DSN` (пусто по умолчанию — SDK молчит). Запланирован каждые 15 минут, тот же cron-триггер, что и для бэкапа.

## Прод-чеклист

Перед тем как пускать реальный поток заказов:

- [ ] `APP_DEBUG=false` — иначе исключения показывают трассировку и потенциально данные запроса.
- [ ] `TELEGRAM_WEBHOOK_SECRET` — случайная строка, не значение из этого репозитория.
- [ ] `INTERNAL_API_KEY` — то же самое.
- [ ] Вебхук установлен на HTTPS-адрес (`telegram:webhook set`), не polling.
- [ ] `MEDIA_DISK_DRIVER=s3` (или другой не-local) — на одноразовом контейнере локальный диск не переживёт передеплой.
- [ ] `SENTRY_LARAVEL_DSN` заведён, если нужен мониторинг за пределами Telegram-алертов.
- [ ] Cron поднят (`* * * * * php artisan schedule:run`) — иначе не будет ни бэкапов, ни health-check.
- [ ] `db:restore` протестирован хотя бы раз на этом окружении (ТЗ п.106).
- [ ] Юридическая схема передачи ПД клиента мастеру согласована (см. `vault/Открытые вопросы.md`).

## Тесты

```bash
docker compose exec app php artisan test
```

`phpunit.xml` форсирует свои `env` поверх контейнерных (см. комментарий в файле) — тесты всегда бегут на sqlite `:memory:`, а не на dev-базе.

## Структура

- `app/Enums` — `UserRole`, `MasterStatus`, `OrderStatus`, `OrderDeclineReason`, `OrderDraftStep`, `DiagnosisOutcome`, `PriceDeclineReason`, `RescheduleReason`, `ContactAttemptResult`, `MediaType`, `MediaStage`.
- `app/Services/Telegram` — `TelegramClient` (тонкая обёртка над Bot API, включая скачивание файлов), `UpdateHandler` (роутер апдейтов: черновик заявки → ожидание текста/шага/медиа → команды/кнопки → callback-и), `BotMenu` (клавиатуры по ролям).
- `app/Services/Orders` — `OrderStatusMachine` (граф переходов + история, единственное место, где меняется статус), `MasterOrderGuard` (проверка владения заявкой), `DateSlotPicker` (общий выбор даты/слота), `OrderDraftFlow` (создание заявки), `OrderDecisionFlow` (приём/отказ мастера), `OrderReassignFlow`, `MasterMatcher`, `OrderNotifier`, `OrderListScreens`, `MasterOrderScreen` (единый экран активной заявки), `FieldProgressFlow` (выезд/прибытие), `DiagnosisFlow`, `PriceApprovalFlow`, `ContactAttemptFlow`, `OrderRescheduleFlow`, `PartRequestFlow`, `OrderVisitFlow`, `WorkReportFlow`, `PaymentFlow`, `MediaCollector`, `MediaCollectionFlow`, `MediaViewer`, `MasterStatsCalculator`, `OrderStatsCalculator`, `StatsFlow`.
- `app/Http/Controllers/Telegram/WebhookController` — вход для прод-вебхука.
- `app/Http/Controllers/Api/OrderTransitionController` — `POST /api/v1/orders/{id}/transition`, защищён `INTERNAL_API_KEY`.
- `app/Console/Commands` — `telegram:poll`, `telegram:webhook`, `db:backup`, `db:restore`, `system:health-check`, `master:add`.
- `database/seeders` — `ReferenceDataSeeder` (справочники + симптомы), `OwnerSeeder` (твой SUPERADMIN).

## База знаний (Obsidian)

`vault/` — открой этой папкой в Obsidian. Архитектурные решения (и почему не иначе), схема данных, граф статусов, журнал по фазам, открытые вопросы — по мере разработки обновляется вместе с кодом, не постфактум. Начни с `vault/Home.md`.

Дорожная карта и чек-лист по фазам — в отдельных артефактах проекта, не в этом репозитории.
