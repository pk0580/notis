# Notification Service

Микросервис массовой рассылки SMS/Email с приоритезацией, гарантией доставки и идемпотентностью входа.

Реализует требования `TASK.md`. Полный технический разбор — в [`DESCRIPTION.md`](./DESCRIPTION.md): доменная модель, поток данных, AMQP-топология, ретраи/DLQ, схема БД, тестовая стратегия.

---

## Возможности

- **Массовая рассылка** через `POST /api/v1/notifications` — один запрос, до 5000 получателей.
- **Приоритезация** транзакционных сообщений (коды, срочные оповещения) над маркетинговыми — bulkhead из раздельных очередей и пулов воркеров.
- **Гарантия доставки at-least-once** — Transactional Outbox + manual ack + retry с экспоненциальной задержкой.
- **Exactly-once на бизнес-уровне** — state-check агрегата + optimistic-lock по `version` + дедупликация stub-шлюза по `NotificationId`.
- **Идемпотентность входа** — обязательный `Idempotency-Key` (Redis-стор, TTL 24ч).
- **История подписчика** через `GET /api/v1/notifications?recipient=...` с маскированием контакта и cursor-пагинацией.
- **Trace-ID propagation** — сквозной `X-Trace-Id` от HTTP до AMQP-хедера и structured-логов.

---

## Технологический стек

| Слой | Технология |
|---|---|
| Язык / фреймворк | PHP 8.4 + Laravel 13 |
| База данных | PostgreSQL 16 (`jsonb`, `FOR UPDATE SKIP LOCKED`, partial indexes) |
| Брокер | RabbitMQ 3.13 (durable queues, manual ack, DLX + per-message TTL для retry) |
| Кэш / in-memory | Redis 7 (idempotency-стор, дедупликация шлюза) |
| Веб-сервер | Nginx + PHP-FPM |
| Тесты | Pest 4 (поддерживается параллельный режим) |
| API-доки | OpenAPI 3.0 + Swagger UI |

---

## Быстрый старт

```bash
cp src/.env.example src/.env
docker compose up -d
```

Сервис:
- API — `http://localhost:8080`
- Swagger UI — `http://localhost:8080/api/docs`
- RabbitMQ Management — `http://localhost:15672` (guest/guest)
- Healthcheck — `http://localhost:8080/up`

`docker compose up` поднимает app + nginx + postgres + rabbitmq + redis + one-shot `migrate` + воркеры (`worker-transactional` ×4, `worker-marketing` ×2, `worker-outbox` ×2, `worker-default` ×1) со своими healthchecks и `service_completed_successfully` зависимостями.

---

## API

### `POST /api/v1/notifications` — отправка батча

Обязателен заголовок `Idempotency-Key` (UUID, ≤64 байт). `X-Trace-Id` опционален — генерируется автоматически при отсутствии.

```bash
curl -X POST http://localhost:8080/api/v1/notifications \
     -H 'Content-Type: application/json' \
     -H 'Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000' \
     -d '{
       "channel": "sms",
       "priority": "transactional",
       "body": "Ваш код подтверждения: 1234",
       "recipients": ["+79991234567", "+79997654321"]
     }'
```

Ответ `202 Accepted`:

```json
{
  "data": {
    "accepted": 2,
    "notification_ids": ["01957c2e-...", "01957c2e-..."]
  }
}
```

Throttle: 60 req/min на API-токен.

### `GET /api/v1/notifications?recipient={contact}` — история по получателю

```bash
curl "http://localhost:8080/api/v1/notifications?recipient=%2B79991234567&per_page=20"
```

Cursor-пагинация: `meta.next_cursor`. Контакт в ответе маскирован (`+7***4567`, `j***@example.com`).

Throttle: 120 req/min.

### Коды ошибок (стабильные)

| HTTP | `error.code` | Когда |
|---|---|---|
| 422 | `idempotency_key_required` | нет `Idempotency-Key` |
| 409 | `idempotency_key_conflict` | тот же ключ с другим телом запроса |
| 422 | `invalid_recipient` | контакт не E.164 / не e-mail |
| 422 | `invalid_message_body` | длина `body` вне лимита канала |
| 422 | `unknown_channel` | канал не `sms` / `email` |
| 409 | `invalid_status_transition` | нелегальный переход state-машины |

---

## Поведенческие контракты

- **Идемпотентность.** `POST /api/v1/notifications` хранит `(key, sha256(body), response)` в Redis 24ч. Повтор с тем же ключом и телом возвращает закэшированный 202; повтор с другим телом — 409.
- **Гарантия доставки.** Transactional Outbox: запись в `notifications` + `outbox_messages` в одной транзакции; `worker-outbox` публикует в RabbitMQ через `SELECT ... FOR UPDATE SKIP LOCKED` с резервацией (`reserved_at`) и exponential backoff на ошибки публикации (1м/5м/15м/1ч). Consumer делает manual ack только после `repo->save($n)` со статусом `sent`.
- **Exactly-once на бизнес-уровне.** Дублирующий consume → `state check` в `DeliverNotificationAction` (status уже `Sent` → no-op) + optimistic-lock по `version` на UPDATE. Дополнительно — Redis Hash в stub-шлюзах дедуплицирует `NotificationId → ProviderMessageId`.
- **Retry / DLQ.** Transient-ошибки шлюза (`GatewayTimeoutException`, `GatewayUnavailableException`) → до 5 попыток с backoff `1s, 5s, 25s, 125s` через per-message `expiration` + DLX-цикл. После исчерпания → `notifications.dlq` + `markAsDropped('max_retries_exceeded')`. Permanent (`GatewayRejectedException`) → сразу `dropped` без retry, в DLQ не отправляется.
- **Приоритезация (Bulkhead).** Транзакционные и маркетинговые сообщения попадают в физически разные очереди RabbitMQ. `worker-transactional` (4 реплики, 0.50 CPU / 512M) и `worker-marketing` (2 реплики, 0.25 CPU / 256M) — изолированные пулы; перегрузка маркетинга не задерживает критичный путь.
- **State-машина.** `queued → sent → delivered | dropped`. Любой нелегальный переход → `InvalidNotificationStatusTransitionException` (HTTP 409).

---

## Архитектура

Layer-first DDD (`Interface → Application → Domain ← Infrastructure`):

| Слой | Назначение |
|---|---|
| `app/Domain/Notification` | Aggregate, VO, события, порты репозиториев и шлюза, доменные исключения. Чистый PHP без Laravel. |
| `app/Application/Notification` | Use cases (Actions), input/output DTO, порт outbox-репозитория, idempotency-стор. |
| `app/Infrastructure/Notification` | Eloquent, RabbitMQ-топология/publisher/consumer, Redis, stub-шлюзы, console-команды. |
| `app/Interface/Http/Notification` | Invokable controllers, FormRequest, API Resources, middleware (`IdempotencyMiddleware`, `TraceIdMiddleware`). |

Границы охраняются Pest `arch()`-тестами в `tests/Architecture/LayersTest.php` — Domain без `Illuminate`, Application без `Http`/`Eloquent`/`DB`-фасада.

Детальный разбор каждого слоя, поток данных end-to-end, описание AMQP-топологии и схемы БД — в [`DESCRIPTION.md`](./DESCRIPTION.md).

---

## Конфигурация

`src/config/notifications.php` (через `.env`):

| ENV | По умолчанию | Назначение |
|---|---|---|
| `NOTIFICATIONS_BATCH_MAX` | 5000 | Максимальный размер `recipients[]` |
| `NOTIFICATIONS_INSERT_CHUNK` | 2000 | Размер чанка bulk-insert (защита от лимита bind-параметров PG) |
| `NOTIFICATIONS_MAX_ATTEMPTS` | 5 | Макс. число попыток доставки до DLQ |
| `NOTIFICATIONS_RETRY_BACKOFF_MS` | `1000,5000,25000,125000` | Интервалы exponential backoff |
| `NOTIFICATIONS_BODY_MAX_SMS` | 1000 | Лимит длины тела SMS |
| `NOTIFICATIONS_BODY_MAX_EMAIL` | 10000 | Лимит длины тела Email |

---

## Тестирование

Pest 4. Тесты живут в `src/tests/`: Unit (Domain/Application), Integration (Eloquent + RabbitMQ + Redis), Feature (HTTP + e2e-сценарии), Architecture (`arch()`).

```bash
# Серийно
docker compose exec app ./vendor/bin/pest

# Параллельно (paratest, ~16 процессов)
docker compose exec app ./vendor/bin/pest --parallel
```

**Изоляция параллельных тестов per-worker** (`tests/bootstrap.php`):
- Postgres — БД `notifications_test_{TOKEN}`, создаётся идемпотентно через PDO к `postgres`.
- Redis — собственный logical DB по индексу (`TOKEN mod 16`).
- RabbitMQ — собственный vhost `testing_{TOKEN}` + права для `guest`, создаются через management API.

Бутстрап также синхронизирует `$_ENV` → `$_SERVER` для `phpunit.xml`-overrides — обходит особенность `Illuminate\Support\Env`, который читает `$_SERVER` первым (без этого тестовый vhost подменялся бы продакшен-значением из `compose.yaml` env_file).

---

## Observability

- **Trace-ID** — `X-Trace-Id` извлекается из заголовка или генерируется в `TraceIdMiddleware`. Прокидывается через DTO → `notifications.trace_id` → AMQP-хедер `x-trace-id` → `Log::withContext()` у consumer'а. В ответе возвращается тем же заголовком.
- **Structured logs** — каждая запись содержит `trace_id`, `notification_id`, `recipient_masked`, `channel`.
- **Healthcheck** — `GET /up` (Laravel default health endpoint).
- **RabbitMQ UI** — `http://localhost:15672`, мониторинг очередей `notifications.transactional`, `notifications.marketing`, `notifications.dlq`, retry-очередей.

---

## Console-команды

```bash
docker compose exec app php artisan outbox:publish [--loop]       # одноразовый flush или непрерывный режим
docker compose exec app php artisan outbox:purge [--days=7]       # удаление опубликованных строк старше N дней
docker compose exec app php artisan rabbitmq:consume <queue>      # consumer для notifications.transactional / .marketing
```

`outbox:purge` запланирован в `routes/console.php` на `dailyAt('03:00')`.
