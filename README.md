# Service Ops

Telegram-бот для распределения выездных заказов между мастерами сервиса ремонта бытовой техники.

Полное ТЗ — в `docs/` (если добавишь) или в исходных документах проекта. Ниже — только то, что нужно, чтобы поднять окружение и начать работать.

## Стек

Laravel 13 (PHP 8.4) · PostgreSQL 16 · Docker Compose. Интерфейс — только Telegram-бот, отдельной веб-админки нет. Очередь по умолчанию — `database` (Postgres), Redis 7 (`predis`) поддержан и легко включается через `QUEUE_CONNECTION=redis`, но не обязателен, пока нет реальной CRM с заметным объёмом задач (см. vault/Решения.md).

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

`php artisan system:health-check` проверяет вебхук/поллинг (ошибки, застрявшие апдейты — учитывает `TELEGRAM_MODE`), очередь (Redis — только если `QUEUE_CONNECTION=redis`) и БД; при проблеме шлёт алерт `TELEGRAM_OWNER_ID` и репортит в Sentry, если задан `SENTRY_LARAVEL_DSN` (пусто по умолчанию — SDK молчит). Запланирован каждые 15 минут, тот же cron-триггер, что и для бэкапа.

## Rate limiting

`/api/telegram/webhook` (300 запросов/мин по IP) и `/api/v1/*` (60/мин, по `X-Internal-Api-Key`, не по IP — разные клиенты Internal API не делят один бюджет) — throttle стоит первым в цепочке middleware, до проверки секрета/ключа, так что режет объём независимо от того, прошла аутентификация или нет. Лимиты щедрые (не мешают легитимным всплескам), настроены в `AppServiceProvider::boot()`.

## Internal API (CRM/сайт)

ТЗ п.88 — приём заказов от CRM/сайта и обратная связь по ним, за `X-Internal-Api-Key`:

```
POST   /api/v1/orders                    Создать заказ (status=NEW, без мастера)
GET    /api/v1/orders/{id}                Показать заказ
PATCH  /api/v1/orders/{id}                Исправить логистические/контактные поля
GET    /api/v1/orders/search              Поиск (status, master_id, customer_phone, number, visit_date_from/to)
POST   /api/v1/orders/{id}/assign         Назначить мастера (NEW/MASTER_DECLINED → ASSIGNED)
POST   /api/v1/orders/{id}/transition     Сменить статус напрямую (см. OrderStatusMachine)
POST   /api/v1/orders/{id}/visits         Запланировать повторный визит (только из WAITING_PART)
POST   /api/v1/orders/{id}/work-report    Завершить ремонт (только из IN_PROGRESS)
POST   /api/v1/orders/{id}/media          Прикрепить фото/видео (multipart, до 20 МБ)
POST   /api/v1/orders/{id}/payments       Отметить оплату (amount ≥ final_price → PAID)
GET    /api/v1/masters                    Список мастеров
GET    /api/v1/masters/{id}/stats         Статистика мастера (?from=&to=)
GET    /api/v1/stats                      Общая сводка (?from=&to=)
```

Заказ, созданный через API, не проходит через `OrderDraftFlow` — он в статусе `NEW` без мастера, ADMIN назначает его вручную в боте (экран «Нераспределённые») либо CRM сама вызывает `/assign`. Каждый write-эндпоинт зеркалит соответствующий бот-flow (см. комментарии в контроллерах `app/Http/Controllers/Api`), чтобы CRM не могла обойти бизнес-правила, которые есть у мастера в Telegram.

**CRM sync** (ТЗ п.85-87): каждый переход статуса (`OrderStatusMachine::transition()` — единая точка для всего жизненного цикла заказа) ставит `SyncOrderToCrm` в очередь, та вызывает `CrmAdapter::syncOrder()`. Реальной CRM ещё нет — по умолчанию `LogCrmAdapter` просто логирует снимок заказа. Когда появится Bitrix24/amoCRM/другая — новый класс, реализующий `App\Services\Crm\CrmAdapter`, подключается через `CRM_ADAPTER_CLASS` в `.env`, без изменений в остальном коде. Если CRM/очередь недоступны — заказ продолжает работать как обычно (`dispatchSafely()` перехватывает исключение), синк ретраится автоматически (5 попыток, backoff до 30 минут).

## Очистка (prune)

`php artisan model:prune` удаляет истёкшие `pending_inputs`/`order_drafts` (`expires_at` в прошлом) и `telegram_updates` старше 7 дней (Prunable-модели, см. `app/Models`). Запланирован ежедневно в 03:15, сразу после `db:backup` — бэкап снимается до чистки, не после.

## Деплой в прод

`docker-compose.yml` (dev) намеренно не годится для прода: `artisan serve` — однопоточный dev-сервер, код смонтирован томом, а не запечён в образ, БД/Redis торчат портами наружу. Три прод-варианта:

- **`docker/php/Dockerfile.dokploy`** — один образ (nginx + PHP-FPM в одном контейнере через supervisor), под Dokploy Application (тот умеет собирать только один Dockerfile, без типа ресурса "Compose" — см. vault/Решения.md). Основной вариант для этого проекта.
- **`docker-compose.prod.yml`** — голый сервер без Dokploy: PHP-FPM + nginx отдельными сервисами (`docker/php/Dockerfile.prod`), БД/Redis самодостаточны внутри того же файла, nginx публикует `80:80` на хост.
- **`docker-compose.dokploy.yml`** — тот же compose-контур, но БД/Redis как отдельные ресурсы Dokploy — про запас, если когда-нибудь появится Dokploy-ресурс типа Compose.

Проверено вживую (на `Dockerfile.dokploy`): сборка, `/up`/`/` /вебхук-без-секрета через supervisor+nginx+php-fpm в одном контейнере, и намеренное падение php-fpm внутри контейнера — восстанавливается сам за секунду, без ручного вмешательства.

### Вариант: Dokploy

1. **БД** — отдельный ресурс Database (Postgres) в Dokploy, если ещё не создан. В его карточке посмотреть **Internal Host** и **Port** (не JDBC-строку, если Dokploy такую показывает для удобства копирования извне — этот формат не понимает Laravel). Redis — НЕ обязателен на старте: `QUEUE_CONNECTION=database` работает поверх этой же БД (см. vault/Решения.md), заводить отдельный Redis-ресурс — только когда очередь реально станет нагруженной.
2. **Веб-приложение** — Application → Build Type **Dockerfile**, Docker File — `docker/php/Dockerfile.dokploy`, Docker Context Path — `.`. Репозиторий `Leobrain12/masterof` (приватный — SSH deploy key или GitHub-интеграция в Dokploy, не просто HTTPS-URL). Ветка — `master`, не `main`.
3. **Переменные окружения** — вкладка Environment. Единственно верный список — `.env.example` в репозитории, не шаблоны из других ботов: разные названия (`DB_USER` вместо `DB_USERNAME`, `SUPERADMIN_TELEGRAM_ID` вместо `TELEGRAM_OWNER_ID` и т.п.) молча не сработают — Laravel их просто не найдёт под именами, которые не совпадают буква в букву. `DB_HOST`/`DB_PORT`/`REDIS_HOST`/`REDIS_PORT` — из шага 1. `APP_KEY` — сгенерировать отдельно (`php artisan key:generate --show` в любом PHP 8.4) и вписать руками, автогенерации на лету здесь не будет.
4. **Домен** — вкладка Domains: порт `80` (nginx внутри контейнера), домен `your-domain.com`. Dokploy сам добавит нужные Traefik-лейблы и закажет сертификат.
5. **Очередь** — второй Application-ресурс из ТОГО ЖЕ репозитория и Dockerfile (`docker/php/Dockerfile.dokploy`), но с переопределённой командой запуска (обычно поле "Docker Command"/аналог в Advanced-настройках): `php artisan queue:work --tries=3 --max-time=3600 --sleep=2`. Те же переменные окружения, что у веб-приложения. Без Domain — этому ресурсу наружу отвечать не на что.
6. **Разовые шаги после первого деплоя** (через терминал контейнера веб-приложения в UI Dokploy, либо SSH на хост если есть):
   ```bash
   php artisan migrate --force
   php artisan db:seed --class=OwnerSeeder
   ```
7. **Cron** — если у Dokploy на этой версии есть Scheduled Jobs, завести туда `php artisan schedule:run` раз в минуту в веб-контейнере; если нет — обычный host-crontab (см. ниже), если есть SSH-доступ к серверу помимо самого Dokploy.
8. **Апдейты от Telegram**: по умолчанию — вебхук, см. шаг 4 (Домен) + `telegram:webhook set` ниже. Если хостинг блокирует ВХОДЯЩИЕ соединения от Telegram (реальный случай, см. vault/Решения.md — `getWebhookInfo` показывает `last_error_message: "Connection timed out"` при полностью рабочем домене/TLS), нужен третий Application-ресурс из ТОГО ЖЕ репозитория и Dockerfile, командой `php artisan telegram:poll`, без Domain — и обязательно `TELEGRAM_MODE=polling` в Environment ВСЕХ трёх ресурсов (веб/очередь/poll), иначе `system:health-check` будет слать ложные алерты "вебхук не установлен" каждые 15 минут.
9. Дальше — как в любом варианте: проверить `/up`, и либо поставить вебхук (`telegram:webhook set`), либо убедиться, что `telegram:poll` в логах третьего ресурса пишет "Webhook удалён, слушаю апдейты".

Первый прогон вместе — часть вещей (точное название полей в UI, доступен ли SSH к хосту отдельно от Dokploy) выяснится по ходу, план выше — отправная точка, не точная инструкция клик-в-клик.

### Вариант: голый сервер (без Dokploy)

Сервер с Docker + Docker Compose, домен, указывающий на его IP. Сам стек отдаёт только plain HTTP — TLS-терминация вне зоны ответственности этого compose-файла (Telegram требует HTTPS для вебхука, ТЗ п.93). Самый безболезненный вариант для соло-разработки — [Caddy](https://caddyserver.com/) перед этим стеком, у него автоматический Let's Encrypt в несколько строк конфига (и `ports: ["80:80"]` у nginx в `docker-compose.prod.yml` нужно вернуть — без Dokploy порт 80 на хосте никто больше не занимает):

```
# /etc/caddy/Caddyfile на хосте (Caddy не входит в docker-compose.prod.yml —
# TLS-стратегия зависит от хостинга, не должна быть зашита в сам стек)
your-domain.com {
    reverse_proxy localhost:80
}
```

### Первый деплой (голый сервер)

```bash
git clone <repo> service-ops && cd service-ops
cp .env.example .env
php artisan key:generate --show   # вписать результат в APP_KEY вручную, или через docker (см. ниже)
# .env: APP_ENV=production, APP_DEBUG=false, APP_URL=https://your-domain.com,
# TELEGRAM_*, INTERNAL_API_KEY, боевые DB_PASSWORD — случайные, не из репозитория

docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d db redis
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=OwnerSeeder

curl -s https://your-domain.com/up   # ожидается 200
php artisan telegram:webhook set https://your-domain.com/api/telegram/webhook
```

БД/Redis подняты и смигрированы **до** остального стека сознательно: `CACHE_STORE=database` — если `app`/`queue` стартуют раньше, чем существует таблица `cache`, в логах будет безвредная, но лишняя ошибка на первый опрос.

### Cron на хосте

Планировщик (`db:backup`, `system:health-check`, `model:prune`) не поднят отдельным контейнером — один cron-триггер на хосте, как рекомендует сам Laravel:

```
* * * * * cd /path/to/service-ops && docker compose -f docker-compose.prod.yml exec -T app php artisan schedule:run >> /dev/null 2>&1
```

### Обновление кода

```bash
git pull
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d
```

Пересборка образа = новый `config:cache`/`route:cache`/`view:cache` при старте контейнера (см. `docker/php/entrypoint.sh`) — простой `git pull` без пересборки ничего не изменит, `opcache.validate_timestamps=0` в проде намеренно игнорирует правки файлов на диске.

## Прод-чеклист

Перед тем как пускать реальный поток заказов:

- [ ] `APP_ENV=production`, `APP_DEBUG=false` — иначе исключения показывают трассировку и потенциально данные запроса.
- [ ] TLS настроен (Caddy/nginx+certbot/Dokploy Traefik — см. выше).
- [ ] Апдейты доходят: либо `TELEGRAM_MODE=webhook` (по умолчанию) + `TELEGRAM_WEBHOOK_SECRET` — случайная строка, не значение из этого репозитория, + `telegram:webhook set` на HTTPS-адрес; либо `TELEGRAM_MODE=polling` + третий Application-ресурс с `php artisan telegram:poll` — если хостинг блокирует ВХОДЯЩИЕ соединения от Telegram (реальный случай, см. vault/Решения.md). Несовпадение `TELEGRAM_MODE` с реальным режимом — ложные алерты либо тишина от `system:health-check`.
- [ ] `INTERNAL_API_KEY` — случайная строка, не значение из этого репозитория.
- [ ] `MEDIA_DISK_DRIVER=s3` (или другой не-local) — том `storage` переживает передеплой контейнера, но локальный диск всё равно не то же самое, что реальный бэкап медиа (ТЗ п.54, 99).
- [ ] `SENTRY_LARAVEL_DSN` заведён, если нужен мониторинг за пределами Telegram-алертов.
- [ ] Очередь поднята — второй Application-ресурс (или `queue`-сервис в compose-вариантах) с `php artisan queue:work --tries=3 --max-time=3600 --sleep=2`. Без него `SyncOrderToCrm` копится (по умолчанию в таблице `jobs`, `QUEUE_CONNECTION=database` — см. выше) и никогда не выполняется.
- [ ] Cron поднят (`* * * * * ... schedule:run`) — иначе не будет ни бэкапов, ни health-check, ни prune. В `docker/php/Dockerfile.dokploy` cron-процесса НЕТ (только php-fpm+nginx через supervisor) — на Dokploy нужны либо его Scheduled Jobs, либо host-crontab с SSH-доступом к серверу.
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
- `app/Services/Orders` — `OrderStatusMachine` (граф переходов + история, единственное место, где меняется статус), `MasterOrderGuard` (проверка владения заявкой), `DateSlotPicker` (общий выбор даты/слота), `OrderDraftFlow` (создание заявки), `OrderDecisionFlow` (приём/отказ мастера), `OrderReassignFlow`, `MasterMatcher`, `OrderNotifier`, `OrderListScreens`, `MasterOrderScreen` (единый экран активной заявки), `FieldProgressFlow` (выезд/прибытие), `DiagnosisFlow`, `PriceApprovalFlow`, `ContactAttemptFlow`, `OrderRescheduleFlow`, `PartRequestFlow`, `OrderVisitFlow`, `WorkReportFlow`, `PaymentFlow`, `MediaCollector`, `MediaCollectionFlow`, `MediaViewer`, `MasterStatsCalculator`, `OrderStatsCalculator`, `StatsFlow`, `WarrantyReturnFlow` (гарантийное обращение, ТЗ п.64-65 — см. `vault/Backlog/Гарантийный возврат.md`).
- `app/Http/Controllers/Telegram/WebhookController` — вход для прод-вебхука.
- `app/Http/Controllers/Api` — Internal API (ТЗ п.88, см. раздел выше), все защищены `INTERNAL_API_KEY`: `OrderController` (store/show/update/search), `OrderAssignController`, `OrderTransitionController`, `OrderVisitController`, `OrderWorkReportController`, `OrderMediaController`, `OrderPaymentController`, `MasterController`, `StatsController`.
- `app/Services/Crm` — `CrmAdapter` (интерфейс), `LogCrmAdapter` (реализация по умолчанию, реальной CRM ещё нет).
- `app/Jobs/SyncOrderToCrm` — очередь + автоматический ретрай синка заказа в CRM (ТЗ п.87).
- `app/Console/Commands` — `telegram:poll`, `telegram:webhook`, `db:backup`, `db:restore`, `system:health-check`, `master:add`.
- `database/seeders` — `ReferenceDataSeeder` (справочники + симптомы), `OwnerSeeder` (твой SUPERADMIN).
- `docker-compose.yml`/`docker/php/Dockerfile` — dev (`artisan serve`, код томом). `docker-compose.prod.yml`/`docker/php/Dockerfile.prod`/`docker/nginx` — прод (PHP-FPM + nginx, код в образе), см. «Деплой в прод» выше.

## База знаний (Obsidian)

`vault/` — открой этой папкой в Obsidian. Архитектурные решения (и почему не иначе), схема данных, граф статусов, журнал по фазам, открытые вопросы — по мере разработки обновляется вместе с кодом, не постфактум. Начни с `vault/Home.md`.

Дорожная карта и чек-лист по фазам — в отдельных артефактах проекта, не в этом репозитории.
