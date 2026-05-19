# Notification Service — детальное описание приложения

> Документ для начинающего разработчика. Здесь собрано всё, что нужно для понимания работы сервиса «с нуля»: бизнес-задача, архитектура, поток данных, инфраструктура, ключевые алгоритмы и привязка к плану `PROJECT_PLAN.md`.
>
> Везде, где описывается алгоритм, указаны конкретные классы и методы, в которых он реализован.

---

## 1. Что это за приложение

**Notification Service** — микросервис массовых уведомлений (SMS / Email) с:
- **массовой рассылкой через REST API** (один запрос — много получателей);
- **приоритезацией** транзакционных сообщений (коды подтверждения, срочные оповещения) над маркетинговыми рассылками;
- **гарантией доставки (at-least-once)** через брокер RabbitMQ + транзакционный outbox;
- **бизнес-уровневой exactly-once семантикой** через идемпотентность входа и optimistic-lock агрегата;
- **историей статусов уведомлений** по контактам подписчика (`queued → sent → delivered/dropped`).

Соответствует требованиям `TASK.md` (F1–F4, N1–N7, S1–S3) и реализован в **Complex (DDD)** тире проекта, как зафиксировано в `PROJECT_PLAN.md` §1–§2.

---

## 2. Технологический стек

| Слой | Технология | Зачем |
|---|---|---|
| Язык / фреймворк | **PHP 8.4 + Laravel 13** | Рекомендация задания. Используем readonly classes, native enums, asymmetric visibility |
| База данных | **PostgreSQL 16** | `jsonb` (status_history), `FOR UPDATE SKIP LOCKED` (параллельная публикация outbox), partial indexes |
| Брокер | **RabbitMQ 3.13** | Durable queues + manual ack + per-message TTL/DLX (retry) + раздельные очереди (bulkhead) |
| Кэш / in-memory | **Redis 7** | (1) idempotency-store входящих API-запросов, (2) Redis Hash для дедупликации stub-провайдеров по `NotificationId` |
| AMQP-клиент | `php-amqplib/php-amqplib` | Низкоуровневое управление каналом, headers, manual ack |
| Веб-сервер | **Nginx + PHP-FPM** | Стандартная связка для production-grade Laravel |
| Очередь database | Laravel `queue:database` | Имитация асинхронного колбэка от провайдера (`SimulateDeliveryAckJob`), at-least-once встроен в Laravel |
| Контейнеризация | **Docker Compose** (`compose.yaml`) | Один `docker compose up` поднимает весь стек |

Тесты — **Pest 4** (auto-detect). Документация API — **OpenAPI 3.0 + Swagger UI** на `/api/docs`.

---

## 3. Архитектура: слои, направление зависимостей

Проект следует Clean Architecture / DDD с layer-first структурой каталогов:

```
src/app/
├── Domain/            ← Чистый PHP. Бизнес-логика, инварианты, state-машина
├── Application/       ← Use cases (Actions), DTO, порты (interfaces)
├── Infrastructure/    ← Eloquent, RabbitMQ, Redis, console commands, mappers
└── Interface/Http/    ← Controllers, Form Requests, Resources, Middleware
```

**Правило зависимости:** `Interface → Application → Domain ← Infrastructure`.

Domain ничего не знает про Laravel. Application не знает про HTTP/Eloquent — пользуется только интерфейсами (портами). Infrastructure реализует порты и подключается через DI-биндинг в `NotificationServiceProvider`.

Эту инвариантность охраняет тест `tests/Architecture/LayersTest.php` (Pest `arch()`).

---

## 4. Структура каталогов и ключевые классы

```
src/app/
├── Domain/Notification/
│   ├── Entity/Notification.php                                ← Aggregate root + state-машина
│   ├── ValueObject/
│   │   ├── NotificationId.php          ← UUIDv7 wrapper
│   │   ├── Channel.php                 ← enum Sms|Email
│   │   ├── Priority.php                ← enum Transactional|Marketing
│   │   ├── NotificationStatus.php      ← enum Queued|Sent|Delivered|Dropped
│   │   ├── Recipient.php               ← контакт + валидация + masked()
│   │   ├── MessageBody.php             ← фабрика for(Channel,string)
│   │   ├── ProviderMessageId.php
│   │   └── StatusHistory.php           ← immutable коллекция переходов
│   ├── Repository/NotificationRepository.php    ← порт (interface)
│   ├── Gateway/
│   │   ├── NotificationGateway.php     ← порт шлюза
│   │   └── GatewayResult.php           ← DTO ответа шлюза
│   ├── Event/
│   │   ├── NotificationQueued.php
│   │   ├── NotificationSent.php
│   │   ├── NotificationDelivered.php
│   │   └── NotificationDropped.php
│   └── Exception/
│       ├── InvalidRecipientException.php           (422)
│       ├── InvalidMessageBodyException.php         (422)
│       ├── InvalidNotificationStatusTransitionException.php  (409)
│       ├── UnknownChannelException.php             (422)
│       ├── ConcurrencyException.php
│       ├── GatewayTimeoutException.php             ← transient → retry
│       ├── GatewayUnavailableException.php         ← transient → retry
│       └── GatewayRejectedException.php            ← permanent → drop
│
├── Application/Notification/
│   ├── UseCase/
│   │   ├── DispatchNotifications/
│   │   │   ├── DispatchNotificationsAction.php     ← основной write-юзкейс F1
│   │   │   ├── DispatchNotificationsData.php       ← input-DTO
│   │   │   └── DispatchAcceptedResult.php          ← output-DTO
│   │   ├── DeliverNotification/
│   │   │   ├── DeliverNotificationAction.php       ← вызов шлюза + state-переход
│   │   │   ├── DeliverNotificationData.php
│   │   │   ├── DeliverNotificationResult.php       ← enum Success|NoOp
│   │   │   ├── DeliverNotificationFailedException.php           ← transient
│   │   │   └── PermanentDeliverNotificationFailedException.php  ← permanent
│   │   └── AcknowledgeDelivery/
│   │       ├── AcknowledgeDeliveryAction.php       ← переход Sent → Delivered/Dropped
│   │       └── AcknowledgeDeliveryData.php
│   ├── Query/GetNotificationsByRecipient/
│   │   ├── GetNotificationsByRecipientHandler.php  ← read F3
│   │   ├── GetNotificationsByRecipientQuery.php
│   │   └── NotificationView.php                    ← read-DTO для API
│   ├── ReadRepository/NotificationReadRepository.php  ← порт read-стороны
│   ├── Outbox/
│   │   ├── OutboxRepository.php                    ← порт (Application, не Domain)
│   │   └── OutboxEntry.php                         ← {notificationId, priority}
│   └── Idempotency/IdempotencyStore.php            ← порт
│
├── Infrastructure/Notification/
│   ├── Persistence/Eloquent/
│   │   ├── Models/{NotificationModel,OutboxMessageModel}.php
│   │   ├── Repositories/
│   │   │   ├── EloquentNotificationRepository.php     ← save() с optimistic-lock, saveMany() bulk-insert
│   │   │   ├── EloquentNotificationReadRepository.php ← cursorPaginate + select() колонок
│   │   │   └── EloquentOutboxRepository.php           ← appendMany() чанками по 2000
│   │   └── Mappers/NotificationMapper.php             ← Eloquent ↔ Domain
│   ├── Messaging/
│   │   ├── RabbitMqTopology.php                    ← idempotent declare exchanges/queues
│   │   ├── OutboxPublisher.php                     ← SELECT ... FOR UPDATE SKIP LOCKED → publish
│   │   └── ConsumeNotificationJob.php              ← AMQP-consumer, обрабатывает retry+DLQ
│   ├── Gateway/
│   │   ├── StubSmsGateway.php                      ← 80/15/5%, дедупликация в Redis Hash
│   │   ├── StubEmailGateway.php
│   │   └── CompositeNotificationGateway.php        ← диспетчер по Channel
│   ├── Idempotency/RedisIdempotencyStore.php
│   ├── Http/Middleware/TraceIdMiddleware.php       ← бонус: X-Trace-Id propagation
│   ├── Job/SimulateDeliveryAckJob.php              ← имитация колбэка delivered/dropped
│   ├── Console/Command/
│   │   ├── OutboxPublishCommand.php                ← outbox:publish [--loop]
│   │   ├── OutboxPurgeCommand.php                  ← daily cleanup
│   │   └── RabbitMqConsumeCommand.php              ← rabbitmq:consume <queue>
│   └── Provider/NotificationServiceProvider.php    ← DI-биндинги
│
└── Interface/Http/Notification/
    ├── Controller/
    │   ├── DispatchNotificationsController.php     ← POST /api/v1/notifications
    │   └── GetNotificationsByRecipientController.php ← GET /api/v1/notifications
    ├── Middleware/IdempotencyMiddleware.php        ← Idempotency-Key контракт
    ├── Request/{DispatchNotificationsRequest,GetNotificationsByRecipientRequest}.php
    └── Resource/{DispatchAcceptedResource,NotificationResource,NotificationCollection}.php
```

---

## 5. Доменная модель: агрегат `Notification`

Aggregate root — `App\Domain\Notification\Entity\Notification`.

### 5.1 Свойства

```php
NotificationId $id;
Recipient $recipient;
Channel $channel;
Priority $priority;
MessageBody $body;
NotificationStatus $status;            // private setter; меняется только через методы
StatusHistory $history;                // immutable — withTransition() возвращает новый
?ProviderMessageId $providerMessageId;
int $attempts;                         // считает ТОЛЬКО НЕУДАЧНЫЕ попытки (план §3.6)
?string $lastError;                    // сообщение последнего исключения шлюза
?string $traceId;                      // бонус, observability
DateTimeImmutable $createdAt;
DateTimeImmutable $updatedAt;
int $version;                          // optimistic-lock counter
```

### 5.2 Конструкторы

- `Notification::create(Recipient, Channel, Priority, MessageBody, ?string $traceId): self`
  - Стартует со `status = Queued`, `attempts = 0`, `version = 0`.
  - Сразу пишет первый переход в `history` через `StatusHistory::withTransition(Queued, $now)`.
  - Генерирует `NotificationId::generate()` → UUIDv7.
- `Notification::reconstitute(...)` — для маппера; принимает все поля как есть. Используется в `NotificationMapper::toDomain()`.

### 5.3 State-машина (методы перехода)

```
        ┌──────────┐  markAsSent       ┌──────┐  markAsDelivered  ┌───────────┐
        │  Queued  ├──────────────────►│ Sent ├──────────────────►│ Delivered │
        └────┬─────┘                   └──┬───┘                   └───────────┘
             │                            │
             │ markAsDropped(reason)      │ markAsDropped(reason)
             ▼                            ▼
        ┌──────────┐                 ┌──────────┐
        │  Dropped │                 │  Dropped │
        └──────────┘                 └──────────┘
```

Реализация:

| Метод | Допустимый исходный статус | Что делает |
|---|---|---|
| `markAsSent(ProviderMessageId)` | `Queued` | `status = Sent`, сохраняет `providerMessageId`, пишет переход в `history`. **`attempts` НЕ увеличивает** (план §3.6, R10) |
| `markAsDelivered()` | `Sent` | `status = Delivered`, пишет в `history` |
| `markAsDropped(string $reason)` | `Queued` или `Sent` | `status = Dropped`, пишет в `history` с полем `reason` |
| `recordFailedAttempt(string $error)` | любой | `attempts++`, `lastError = $error`. Сам по себе **не меняет status** |
| `incrementVersion()` | — | Синхронизация in-memory `version` с тем, что записал репозиторий |

Любой переход из недопустимого состояния → `InvalidNotificationStatusTransitionException` (→ HTTP 409).

### 5.4 Value Objects — где живут инварианты

- **`Recipient`** (`Recipient::__construct(string, Channel)`): валидирует формат. Для `Sms` — E.164 (`/^\+[1-9]\d{6,14}$/`), для `Email` — `filter_var(..., FILTER_VALIDATE_EMAIL)`. Метод `masked()` маскирует контакт для логов (`+7***4567`, `j***@example.com`). Фабрика `Recipient::fromAny(string)` определяет канал по формату — нужна для read-API (GET-запрос принимает строку без канала).
- **`MessageBody::for(Channel, string)`** — единственная фабрика. Длина читается из `config('notifications.body_max.{channel}')`: SMS — 1..1000 символов, Email — 1..10000 (план §4.2/A5). Domain-слой остаётся framework-free: если `function_exists('config')` ложен (юнит-тесты без Laravel boot), используется fallback-константа.
- **`NotificationId`** — UUIDv7 через `Ramsey\Uuid\Uuid::uuid7()`. UUIDv7 — это упорядоченный по времени UUID, что улучшает локальность в индексах PG.
- **`StatusHistory`** — immutable массив; `withTransition()` возвращает новый объект.

### 5.5 Domain Events (past-tense)

`NotificationQueued`, `NotificationSent`, `NotificationDelivered`, `NotificationDropped`. Все диспатчатся через `DB::afterCommit(...)` — гарантирует, что подписчики увидят событие только после фиксации транзакции.

---

## 6. Поток данных: end-to-end сценарий рассылки

Следующая последовательность реализует требование F1 (массовая рассылка) и одновременно закрывает N1 (персистентность), N2 (at-least-once), N5 (идемпотентность). Соответствует `PROJECT_PLAN.md` §9.

### Шаг 1. Клиент шлёт `POST /api/v1/notifications`

```http
POST /api/v1/notifications
Content-Type: application/json
Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
X-Trace-Id: trace-abc-123                     ← опционально

{
  "channel": "sms",
  "priority": "transactional",
  "body": "Your code: 1234",
  "recipients": ["+79991234567", "+79007654321"]
}
```

### Шаг 2. `TraceIdMiddleware` (бонус)

Класс: `App\Infrastructure\Notification\Http\Middleware\TraceIdMiddleware::handle()`.

- Берёт `X-Trace-Id` из заголовка; если его нет — генерирует UUIDv7 (`Str::uuid7()`).
- Кладёт в `$request->attributes['trace_id']` и в `Log::withContext()`.
- Перед возвратом ответа выставляет заголовок `X-Trace-Id` в response.

Подключён в `bootstrap/app.php` → `withMiddleware(...)` к API-группе.

### Шаг 3. `IdempotencyMiddleware`

Класс: `App\Interface\Http\Notification\Middleware\IdempotencyMiddleware::handle()` (порт `IdempotencyStore`, реализация `RedisIdempotencyStore`).

Алгоритм (план §3.3, N5):

```
1. key = request.header('Idempotency-Key')
2. if (!key) → 422 idempotency_key_required
3. hash = sha256(request.body)
4. cached = store->get(key)         // Redis GET по ключу idempotency:notifications.dispatch:{key}
5. if (cached):
     if (cached.request_hash != hash) → 409 idempotency_key_conflict
     else → return JsonResponse(cached.response, cached.status_code)
6. response = $next($request)        // выполняем основной handler
7. if (response.isSuccessful() || status == 202):
     store->set(key, {request_hash, response_body, status_code}, ttl=86400)
8. return response
```

Хранилище — Redis с политикой `allkeys-lru` (см. `compose.yaml`). Ключ namespace’нут на use-case: `idempotency:notifications.dispatch:{key}` — задел на будущие идемпотентные эндпоинты без коллизий.

### Шаг 4. `DispatchNotificationsRequest` — валидация на UI-слое

Класс: `App\Interface\Http\Notification\Request\DispatchNotificationsRequest`.

`rules()`:
- `channel|required|string|in:sms,email`
- `priority|required|string|in:transactional,marketing`
- `body|required|string|min:1`
- `recipients|required|array|min:1|max:config('notifications.batch_max')` (по умолчанию 5000)
- `recipients.*|required|string|min:1`

`withValidator()` дополнительно (после `rules()` чтобы избежать каскадных ошибок):
- `MessageBody::for($channel, $body)` — проверяет channel-зависимый лимит длины.
- Для каждого элемента `recipients[i]` — `Recipient::fromString($channel, $row)`.

Семантика **all-or-nothing**: любой невалидный элемент → весь запрос отклонён с 422 (план §6.2). Это намеренно: частичный приём усложнил бы контракт без бизнес-выгоды.

### Шаг 5. `DispatchNotificationsController` строит DTO и вызывает Action

Класс: `App\Interface\Http\Notification\Controller\DispatchNotificationsController::__invoke()`.

```php
$channel = Channel::from($request->input('channel'));
$data = new DispatchNotificationsData(
    channel:    $channel,
    priority:   Priority::from($request->input('priority')),
    body:       MessageBody::for($channel, $request->input('body')),
    recipients: array_map(
        fn(string $r) => Recipient::fromString($channel, $r),
        $request->input('recipients'),
    ),
    traceId:    (string) $request->attributes->get('trace_id'),
);
$result = $this->action->handle($data);
return (new DispatchAcceptedResource($result))
    ->response()->setStatusCode(Response::HTTP_ACCEPTED);
```

Контроллер тонкий: только трансформация Request → DTO → Action → Resource. Бизнес-логика — внутри Action. Соответствует правилу `layers_context.md` (UI-слой).

### Шаг 6. `DispatchNotificationsAction::handle()` — write-юзкейс

Класс: `App\Application\Notification\UseCase\DispatchNotifications\DispatchNotificationsAction`.

```php
return $this->db->transaction(function () use ($data) {
    $notifications = [];
    $outboxEntries = [];
    $notificationIds = [];

    foreach ($data->recipients as $recipient) {
        $n = Notification::create(
            $recipient, $data->channel, $data->priority, $data->body, $data->traceId
        );
        $notifications[]   = $n;
        $outboxEntries[]   = new OutboxEntry($n->id, $n->priority);
        $notificationIds[] = $n->id;
    }

    $this->notifications->saveMany(...$notifications);   // bulk-insert чанками по 2000
    $this->outbox->appendMany($outboxEntries);           // bulk-insert чанками по 2000

    $this->db->afterCommit(function () use ($notificationIds, $data) {
        foreach ($notificationIds as $id) {
            $this->events->dispatch(new NotificationQueued($id, $data->priority));
        }
    });

    return new DispatchAcceptedResult(count($notificationIds), $notificationIds);
});
```

**Ключевые идеи (план §3.4, §6.1):**

1. **Одна транзакция** на запись агрегатов + outbox (паттерн **Transactional Outbox** — закрывает dual-write). Если транзакция упала — нет ни записи в `notifications`, ни в `outbox_messages`.
2. **Bulk insert чанками по 2000** (`NOTIFICATIONS_INSERT_CHUNK`) — иначе при `batch=5000` × 13 колонок единый INSERT пробивает лимит PG на bind-параметры (~65k).
3. **DI порта `OutboxRepository`**, реализация в Infrastructure. `DispatchNotificationsAction` НЕ импортирует Eloquent (охраняется arch-тестом).
4. **`afterCommit`** для domain-events — подписчики не увидят событие до фиксации транзакции.

### Шаг 7. `EloquentNotificationRepository::saveMany()` + `EloquentOutboxRepository::appendMany()`

`EloquentNotificationRepository::saveMany(Notification ...$notifications)`:
- Pre-condition: `version === 0` для каждого (только что созданные через `::create()`).
- `array_chunk(...)` по `config('notifications.insert_chunk', 2000)`.
- В каждом чанке — `NotificationModel::query()->insert(...)`, где `toInsertRow()` сериализует `status_history` в JSON и выставляет `version=1`.
- После записи — `incrementVersion()` для каждого in-memory объекта.

`EloquentOutboxRepository::appendMany(OutboxEntry[] $entries)`:
- `array_chunk(...)` по 2000.
- Каждый чанк — `OutboxMessageModel::query()->insert(...)` с генерацией UUID на запись и `created_at = now()`. Поле `published_at` остаётся `NULL` — будет проставлено публишером.

### Шаг 8. Ответ клиенту (202 Accepted)

`DispatchAcceptedResource` сериализует:
```json
{
  "data": {
    "accepted": 2,
    "notification_ids": [
      "01957c2e-3a8e-7c1d-b4f0-2a8e4c1d9f01",
      "01957c2e-3a8e-7c1d-b4f0-2a8e4c1d9f02"
    ]
  }
}
```

`IdempotencyMiddleware` после получения 202-ответа сохраняет его в Redis с TTL 24ч.

---

## 7. Outbox publisher: переход из БД в RabbitMQ

Шаг 9 в end-to-end сценарии — публикация сообщений в брокер. Этим занимается отдельный воркер `worker-outbox` (плата за развязку dual-write).

### 7.1 Воркер `worker-outbox`

Команда (`compose.yaml`): `php artisan outbox:publish --loop` (replicas: 2).

Реализация — `App\Infrastructure\Notification\Console\Command\OutboxPublishCommand::handle()`:

```
1. $topology->declare()                          // идемпотентно объявить exchanges/queues
2. зарегистрировать обработчики SIGTERM/SIGINT (graceful shutdown)
3. while (!shouldStop):
     count = $publisher->flush(batchSize: 100);
     if (count == 0):
         usleep(500_000);                        // 500ms idle poll
```

### 7.2 `OutboxPublisher::flush(int $batchSize)`

Класс: `App\Infrastructure\Notification\Messaging\OutboxPublisher`.

Алгоритм публикации реализован в две фазы, чтобы гарантировать надежность и производительность (план §3.4, §10):

**Фаза 1: Резервирование (в БД транзакции)**
```sql
SELECT id FROM outbox_messages
  WHERE published_at IS NULL
    AND attempts < 10
    AND (
      (reserved_at IS NULL AND (available_at IS NULL OR available_at <= now()))
      OR reserved_at < now() - INTERVAL '5 minutes'
    )
  ORDER BY created_at
  LIMIT 100
  FOR UPDATE SKIP LOCKED;
```
1. **`FOR UPDATE SKIP LOCKED`** позволяет нескольким воркерам `worker-outbox` работать параллельно, не блокируя друг друга и не выбирая одни и те же сообщения.
2. **`reserved_at`** помечает сообщения как «в обработке», что позволяет вынести сетевое взаимодействие с RabbitMQ за пределы транзакции БД. Это предотвращает удержание блокировок (locks) на время ожидания ответа от брокера и защищает от исчерпания пула соединений БД.
3. Увеличивается счетчик `attempts`. Максимальное количество попыток — 10 (план §3.4).

**Фаза 2: Публикация и подтверждение (вне основной транзакции)**
1. Для зарезервированных ID выбираются данные уведомлений (включая `trace_id` через JOIN).
2. Выполняется `basic_publish` в RabbitMQ.
3. Используется **Publisher Confirms** (`confirm_select()` и `wait_for_pending_acks(5.0)`). Брокер должен подтвердить получение сообщения прежде, чем оно будет помечено как успешно отправленное в БД.
4. **При успехе:** `published_at = now()`, `reserved_at = null`.
5. **При ошибке:** ошибка записывается в `last_error`, `reserved_at` сбрасывается в `null`. Устанавливается `available_at` для реализации **exponential backoff** (1м, 5м, 15м, 1ч). Это предотвращает немедленную переотправку при временных сбоях.

**Минимальный payload (план §3.4):** в AMQP идёт только `notification_id`. Тело сообщения, recipient, channel и т.д. живут в БД и подгружаются потребителем. Это исключает рассинхронизацию AMQP-снимка с актуальным состоянием.

### 7.3 RabbitMQ-топология

Класс: `App\Infrastructure\Notification\Messaging\RabbitMqTopology::declare()`.

```
notifications.direct (direct, durable)
├── routing_key=transactional → notifications.transactional   (DLX→notifications.retry/transactional)
└── routing_key=marketing     → notifications.marketing       (DLX→notifications.retry/marketing)

notifications.retry (direct, durable)
├── routing_key=transactional → notifications.transactional.retry   (DLX→notifications.direct/transactional)
└── routing_key=marketing     → notifications.marketing.retry       (DLX→notifications.direct/marketing)

notifications.dlq.direct (direct, durable)
├── routing_key=transactional → notifications.dlq
└── routing_key=marketing     → notifications.dlq
```

Все exchanges и queues — `durable=true`. Все сообщения — `persistent=true`. Это закрывает N1: после рестарта брокера данные не теряются.

---

## 8. Consumer: обработка AMQP-сообщения

Шаг 10 end-to-end сценария. Воркеры `worker-transactional` (replicas: 4) и `worker-marketing` (replicas: 2) запускают:

```
php artisan rabbitmq:consume notifications.transactional
php artisan rabbitmq:consume notifications.marketing
```

### 8.1 `RabbitMqConsumeCommand`

Класс: `App\Infrastructure\Notification\Console\Command\RabbitMqConsumeCommand::handle()`.

```
1. $topology->declare()                          // идемпотентно
2. открыть AMQP-соединение и канал
3. $channel->basic_qos(null, prefetch_count=1, null)   // ровно одно сообщение за раз
4. $channel->basic_consume($queue, ..., $callback)
5. while (!shouldStop && $channel->is_consuming()):
     $channel->wait(null, false, 10)
```

`prefetch_count=1` важен для bulkhead: один воркер не накачивает себе всю очередь, оставляя место другим репликам.

### 8.2 `ConsumeNotificationJob::__invoke(AMQPMessage)` — основной consumer-обработчик

Класс: `App\Infrastructure\Notification\Messaging\ConsumeNotificationJob`.

```
1. Распаковать payload: {"notification_id": "<uuid>"}
2. Прочитать headers: x-retries (int), x-trace-id (string)
3. Log::withContext(['notification_id', 'trace_id', 'x_retries'])
4. try:
     $this->deliverAction->handle(new DeliverNotificationData($id))
     $message->ack()                              ← manual ack ТОЛЬКО после persist
   catch PermanentDeliverNotificationFailedException:  // GatewayRejected (план §3.5)
     Log::warning(...)
     $message->ack()                              ← в DLQ НЕ шлём, статус уже dropped
   catch DeliverNotificationFailedException:           // transient
     $this->handleRetry($message, $id, $xRetries, $xTraceId, $error)
   catch Throwable:                                    // непредвиденная ошибка
     Log::error(...)
     $message->ack()                              ← чтобы не зацикливаться
```

### 8.3 `DeliverNotificationAction::handle()` — собственно вызов шлюза

Класс: `App\Application\Notification\UseCase\DeliverNotification\DeliverNotificationAction`.

```
$n = $notifications->findById($data->notificationId);
if ($n === null) return DeliverNotificationResult::NoOp;
if ($n->status() !== Queued) return DeliverNotificationResult::NoOp;   ← exactly-once (N3)

try:
    $result = $gateway->send($n);                ← StubSmsGateway или StubEmailGateway
    $n->markAsSent($result->messageId);
    $notifications->save($n);                    ← UPDATE с optimistic-lock by version
    $bus->dispatch(
        (new SimulateDeliveryAckJob($n->id->value))
            ->onConnection('database')
            ->onQueue('default')
            ->delay(now()->addSeconds(rand(1, 3)))
    );
    return Success;
catch GatewayUnavailableException | GatewayTimeoutException $e:
    $n->recordFailedAttempt($e->getMessage());   ← ++attempts, lastError
    $notifications->save($n);
    throw new DeliverNotificationFailedException(...);   ← consumer обработает retry
catch GatewayRejectedException $e:               ← permanent (несуществующий номер/email)
    $n->recordFailedAttempt($e->getMessage());
    $n->markAsDropped('provider_rejected: '.$e->getMessage());
    $notifications->save($n);
    throw new PermanentDeliverNotificationFailedException(...);
```

**Exactly-once на бизнес-уровне (N3, план §3.2):**
- **State check** — если `$n->status() !== Queued`, второй consume того же AMQP-сообщения вернёт `NoOp` без вызова шлюза.
- **Optimistic lock** в `save()` — даже если две реплики consumer’а параллельно обработают одно сообщение (что не должно случиться при `prefetch_count=1`, но возможно при сбоях/redelivery), вторая получит `rows=0` от UPDATE и `ConcurrencyException` — `save` не пройдёт.

**Правило одного `save` на путь (план §10 Фаза 3):** каждая ветка catch мутирует агрегат в памяти полностью, затем делает **один** `repo->save($n)`. Это исключает гонку с optimistic-lock между двумя последовательными `save` в одном handle.

### 8.4 `EloquentNotificationRepository::save()` — optimistic lock

```php
$updated = NotificationModel::query()
    ->where('id', $n->id->value)
    ->where('version', $version)              // оптимистическая блокировка
    ->update([...$data, 'version' => $version + 1]);

if ($updated === 0) {
    // edge case: первая запись для notification, у которой ::create уже отработал
    if ($version === 0 && !exists($id)) {
        NotificationModel::create([...$data, 'version' => 1]);
        $n->incrementVersion();
        return;
    }
    throw new ConcurrencyException("...");
}
$n->incrementVersion();
```

Если параллельный процесс уже обновил агрегат — `WHERE version = ?` не сматчится, `update` вернёт 0 строк, и текущий процесс получит `ConcurrencyException`. Эта семантика мапится в HTTP 409, но в consumer’е она проявится как Throwable и приведёт к ack без повторного шлюза.

---

## 9. Retry с экспоненциальной задержкой (N4)

Реализация — `ConsumeNotificationJob::handleRetry()`. Соответствует `PROJECT_PLAN.md` §3.5.

### 9.1 Алгоритм

```
nextRetry = xRetries + 1                              ← header AMQP, читаем при поступлении
if (nextRetry >= maxAttempts):                        ← maxAttempts = 5
    moveToDlq($message, $id, $xTraceId, 'Max retries exceeded')
    return

delayMs = retryBackoffMs[xRetries] ?? last(retryBackoffMs)  ← [1000, 5000, 25000, 125000]
retryMsg = new AMQPMessage($message->body, [
    delivery_mode: persistent,
    content_type:  'application/json',
    expiration:    delayMs                            ← per-message TTL!
])
retryMsg->set('application_headers', ['x-retries' => nextRetry, 'x-trace-id' => ...])
$channel->basic_publish(retryMsg, exchange='notifications.retry', routing_key=priority)
$message->ack()                                       ← ACK оригинала
```

### 9.2 Что делает RabbitMQ

1. Сообщение попадает в `notifications.retry` exchange.
2. Привязка отправляет его в `notifications.{priority}.retry`-очередь.
3. **Per-message `expiration`** (а не queue-level `x-message-ttl`) — даёт разный delay для разных `x-retries` на одной retry-очереди.
4. По истечению TTL сообщение помечается dead-lettered и через `x-dead-letter-exchange=notifications.direct` возвращается в исходную очередь (`notifications.{priority}`).
5. Consumer получает его снова с инкрементированным `x-retries`.

**Backoff:** 4 интервала между 5 попытками — `1s, 5s, 25s, 125s` (`NOTIFICATIONS_RETRY_BACKOFF_MS`).

### 9.3 DLQ после исчерпания попыток

`ConsumeNotificationJob::moveToDlq()`:

```
publish в notifications.dlq.direct (не через DLX, а явный basic_publish)
$message->ack()
if (notification->status !== 'dropped'):
    $notification->markAsDropped('max_retries_exceeded')
    $repo->save($notification)
```

**Почему отдельный exchange `notifications.dlq.direct`** — RabbitMQ DLX используется только между основным и retry-exchange. После 5 неудач это явная бизнес-операция «отказались доставлять», и она идёт через separate exchange (чище семантика, проще трассировать).

### 9.4 Permanent reject (`GatewayRejectedException`)

`TASK.md`: «несуществующий номер/email — статус dropped». Такие ошибки **не уходят в retry**:

- `DeliverNotificationAction` ловит `GatewayRejectedException`, делает `recordFailedAttempt(msg)` + `markAsDropped('provider_rejected: '.msg)` подряд (две in-memory мутации), затем один `repo->save($n)`.
- Кидает `PermanentDeliverNotificationFailedException`.
- `ConsumeNotificationJob` ловит permanent-исключение, делает `Log::warning(...)` и `$message->ack()`. **В DLQ не публикует** (план §3.5).

---

## 10. Имитация колбэка delivered/dropped (без webhook)

`TASK.md` явно требует только заглушки шлюзов. Чтобы получить полную state-машину `Sent → Delivered/Dropped`, реализован внутренний асинхронный job — `SimulateDeliveryAckJob` (план §A3, §6.4, A4).

### 10.1 Диспетч

В `DeliverNotificationAction` сразу после `markAsSent`:

```php
$this->bus->dispatch(
    (new SimulateDeliveryAckJob($notification->id->value))
        ->onConnection('database')             ← Laravel queue: database
        ->onQueue('default')
        ->delay(now()->addSeconds(random_int(1, 3)))
);
```

### 10.2 Воркер

`worker-default` в `compose.yaml` запускает `php artisan queue:work database --queue=default --tries=3`.

### 10.3 Сам job

Класс: `App\Infrastructure\Notification\Job\SimulateDeliveryAckJob::handle(AcknowledgeDeliveryAction)`:

```php
$chance = random_int(1, 100);
if ($chance <= 90) {
    $finalStatus = NotificationStatus::Delivered;
    $reason = null;
} else {
    $finalStatus = NotificationStatus::Dropped;
    $reason = 'provider_rejected_late: delivery_failed';
}
$action->handle(new AcknowledgeDeliveryData($id, $finalStatus, $reason));
```

### 10.4 `AcknowledgeDeliveryAction::handle()`

Класс: `App\Application\Notification\UseCase\AcknowledgeDelivery\AcknowledgeDeliveryAction`.

```php
$n = $repo->findById($data->notificationId);
if ($n === null) return;
if ($n->status() in [Delivered, Dropped]) return;        ← идемпотентность

if ($data->finalStatus === Delivered) $n->markAsDelivered();
elseif ($data->finalStatus === Dropped) $n->markAsDropped($data->reason ?? 'unknown_reason');
else return;

$repo->save($n);
```

### 10.5 Почему database queue (план A4)

Database queue Laravel имеет встроенный retry: если worker упал между `pop` и `delete` записи из `jobs`-таблицы, джоба будет переподнята после `retry_after` (90 секунд по умолчанию). Это даёт **at-least-once для ack-job без кастомной reconciliation-команды**. Action идемпотентен — повторный запуск на уже `Delivered/Dropped` уведомлении — no-op.

---

## 11. Gateway-заглушки

`App\Infrastructure\Notification\Gateway\StubSmsGateway`, `StubEmailGateway`. Реализуют `App\Domain\Notification\Gateway\NotificationGateway`.

### 11.1 Распределение исходов

```php
$chance = random_int(1, 100);
if ($chance <= 5)     throw new GatewayRejectedException('...');     // permanent (5%)
if ($chance <= 20)    throw new GatewayUnavailableException('...');  // transient (15%)
// success (80%)
```

### 11.2 Идемпотентность шлюза

Перед `send()` шлюз проверяет Redis Hash `gateway:idempotency:sms` (или `email`):

```php
$cachedId = Redis::hget(self::REDIS_KEY, $notification->id->value);
if ($cachedId) return new GatewayResult(new ProviderMessageId($cachedId));
```

При успехе сохраняет результат:

```php
Redis::hset(self::REDIS_KEY, $notification->id->value, $messageId);
Redis::expire(self::REDIS_KEY, 86400);
```

Это эмулирует поведение реальных шлюзов, дедуплицирующих по client-side message-id. Закрывает exactly-once N3 на стороне шлюза — даже если consumer как-то умудрится дважды вызвать `send()` с тем же `NotificationId`, шлюз вернёт тот же `ProviderMessageId`.

### 11.3 `CompositeNotificationGateway`

Диспетчер по `Channel`: `send()` идёт по списку и выбирает первый `supports($channel)`. Биндится в `NotificationServiceProvider` как singleton.

---

## 12. Read API: история уведомлений подписчика (F3)

Эндпоинт: `GET /api/v1/notifications?recipient=<contact>&cursor=<opaque>&per_page=20`.

### 12.1 `GetNotificationsByRecipientRequest`

Класс: `App\Interface\Http\Notification\Request\GetNotificationsByRecipientRequest`.

- `rules()`: `recipient|required|string`, `cursor|nullable|string`, `per_page|nullable|integer|min:1|max:100`.
- `withValidator()` дополнительно: `Recipient::fromAny($recipient)` — определяет канал по формату (`+\d` → SMS, иначе пытается email через `filter_var`). Ошибка → 422.
- Метод `getRecipient(): Recipient` возвращает уже распарсенный VO для контроллера.

### 12.2 `GetNotificationsByRecipientController::__invoke()`

```php
$query = new GetNotificationsByRecipientQuery(
    recipient: $request->getRecipient(),
    cursor:    $request->query('cursor') ? (string) $request->query('cursor') : null,
    perPage:   (int) $request->query('per_page', 20),
);
$result = $this->handler->handle($query);
return (new NotificationCollection($result['data']))
    ->additional(['meta' => ['next_cursor' => $result['next_cursor']]])
    ->response();
```

### 12.3 `GetNotificationsByRecipientHandler::handle()` + `EloquentNotificationReadRepository`

```php
NotificationModel::query()
    ->select(['id','recipient','channel','priority','status','attempts',
              'last_error','status_history','created_at','updated_at'])    // явный select, не *
    ->where('recipient', $recipient->value)
    ->orderByDesc('created_at')
    ->orderByDesc('id')
    ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);
```

Cursor pagination — лучше для больших историй (стабильность при вставке новых строк), плюс на индекс `(recipient, created_at DESC)` ложится идеально.

Каждая модель → `NotificationView` DTO:

```json
{
  "id": "01957c2e-3a8e-...",
  "channel": "sms",
  "priority": "transactional",
  "status": "delivered",
  "recipient_masked": "+7***4567",                ← Recipient::masked(), PII не утекает
  "attempts": 0,
  "last_error": null,
  "status_history": [
    {"status":"queued","at":"..."},
    {"status":"sent","at":"..."},
    {"status":"delivered","at":"..."}
  ],
  "created_at": "...",
  "updated_at": "..."
}
```

**`recipient_masked`** — план R9. Сырой контакт в ответе не возвращается (клиент уже знает контакт — он сам его передал в query). Это снижает риск утечки PII в логах/трейсах потребителя.

---

## 13. Приоритезация (F2) — Bulkhead

`TASK.md`: «транзакционные обгоняют маркетинговые». Реализовано как **физическая изоляция** (план §3.1):

| Очередь | Воркер | Replicas | CPU limit | Mem limit |
|---|---|---|---|---|
| `notifications.transactional` | `worker-transactional` | 4 | 0.50 | 512M |
| `notifications.marketing` | `worker-marketing` | 2 | 0.25 | 256M |

Маркетинг **физически не может** забрать ресурсы транзакционных воркеров — это сильнее, чем `x-max-priority` внутри одной очереди.

`OutboxPublisher` направляет сообщение в нужную очередь через `routing_key = priority`. Тест `tests/Feature/Notification/Scenario2PrioritizationTest.php` проверяет: при запуске только `worker-transactional`, маркетинговые сообщения остаются `queued`.

---

## 14. Идемпотентность входа (N5)

Уже описана в §6 (шаг 3). Сводим контракт:

| Случай | Поведение |
|---|---|
| `Idempotency-Key` отсутствует | 422 `idempotency_key_required` |
| Тот же ключ + тот же body (sha256) | Возврат закэшированного 202 без выполнения Action |
| Тот же ключ + другой body | 409 `idempotency_key_conflict` |
| Новый ключ | Выполнить Action, сохранить ответ в Redis с TTL 24ч |

**Хранилище:** Redis (`maxmemory-policy: allkeys-lru` — защита от OOM при всплесках). Ключ — `idempotency:notifications.dispatch:{key}`. Значение — JSON `{request_hash, response, status_code}`.

В Postgres отдельной таблицы `idempotency_keys` нет (план §3.3) — TASK.md прямо говорит «Redis для дедубликации».

---

## 15. Гарантия доставки (N1, N2, N3)

Сводно — три уровня:

### N1: Персистентность

- Postgres durable, RabbitMQ durable queues/exchanges, persistent messages.
- **Transactional Outbox**: запись в `notifications` + `outbox_messages` в одной транзакции (см. §6). Если приложение упадёт между `commit` БД и `basic_publish`, `OutboxPublisher` дочинит позже.

### N2: At-least-once

- Manual ack: `ConsumeNotificationJob` делает `$message->ack()` **только после** успешного `$repo->save($n)` со статусом `Sent`.
- Если воркер упал между `gateway->send()` и `save()` — AMQP-сервер переотдаст сообщение другому воркеру (или этому же после рестарта), `gateway->send()` будет вызван повторно — но это безопасно благодаря §11.2 (дедупликация шлюза по `NotificationId`).
- `SimulateDeliveryAckJob` на database queue с `--tries=3` тоже at-least-once.

### N3 (бонус): Exactly-once на бизнес-уровне

Две защиты:

1. **State check в `DeliverNotificationAction`:** если повторный consume пришёл, а статус уже `Sent`/`Delivered`/`Dropped` — `NoOp` без вызова шлюза.
2. **Optimistic lock в `EloquentNotificationRepository::save()`:** `WHERE id = ? AND version = ?` — параллельный writer получит 0 строк и `ConcurrencyException`.

Дополнительно — дедупликация шлюза (§11.2) на случай, если первые две защиты вдруг не сработают.

---

## 16. Trace-ID propagation (бонус)

Сквозной трейсинг, дополняющий N6 (план §3.4, R6). Не обязателен по TASK, но включён как observability-улучшение.

Путь trace_id:

```
HTTP header X-Trace-Id
    ↓ TraceIdMiddleware
$request->attributes['trace_id']                         (UUIDv7 если header отсутствовал)
    ↓ DispatchNotificationsController
DispatchNotificationsData::$traceId
    ↓ DispatchNotificationsAction
Notification::create(..., traceId)
    ↓ NotificationMapper::toRow
notifications.trace_id (column в Postgres, nullable, varchar(64))
    ↓ OutboxPublisher::flush (JOIN с notifications)
AMQP header x-trace-id
    ↓ ConsumeNotificationJob
Log::withContext(['trace_id' => ...])
```

`trace_id` НЕ хранится в `outbox_messages` (план R20: без дублирования; publisher JOIN’ит `notifications`).

`X-Trace-Id` возвращается в каждом HTTP-ответе через тот же `TraceIdMiddleware`.

---

## 17. База данных

### 17.1 `notifications`

Миграция: `src/database/migrations/2026_05_18_000001_create_notifications_table.php`.

```sql
CREATE TABLE notifications (
  id                  UUID PRIMARY KEY,                    -- UUIDv7
  recipient           VARCHAR(255) NOT NULL,                -- phone/email — идентификатор подписчика (A1)
  channel             VARCHAR(16)  NOT NULL,
  priority            VARCHAR(16)  NOT NULL,
  body                TEXT         NOT NULL,
  status              VARCHAR(16)  NOT NULL,
  status_history      JSONB        NOT NULL DEFAULT '[]',
  attempts            INTEGER      NOT NULL DEFAULT 0,      -- только НЕУДАЧНЫЕ попытки (R10)
  last_error          TEXT,
  provider_message_id VARCHAR(128),
  trace_id            VARCHAR(64),                          -- бонус
  version             INTEGER      NOT NULL DEFAULT 0,      -- optimistic-lock
  created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Check-constraints (status, channel, priority)
-- Indexes:
CREATE INDEX notifications_recipient_created_idx
  ON notifications (recipient, created_at DESC);              -- F3 hot path
CREATE INDEX notifications_status_queued_idx
  ON notifications (status) WHERE status = 'queued';          -- partial для диагностики
```

### 17.2 `outbox_messages`

Миграция: `src/database/migrations/2026_05_18_000002_create_outbox_messages_table.php`.

```sql
CREATE TABLE outbox_messages (
  id              UUID PRIMARY KEY,
  notification_id UUID         NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
  priority        VARCHAR(16)  NOT NULL,                  -- определяет routing_key
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
  published_at    TIMESTAMPTZ,                            -- NULL = ожидает публикации
  reserved_at     TIMESTAMPTZ,                            -- пометка «в обработке»
  available_at    TIMESTAMPTZ,                            -- время следующей попытки (backoff)
  attempts        INTEGER      NOT NULL DEFAULT 0,
  last_error      TEXT
);
CREATE INDEX outbox_unpublished_idx
  ON outbox_messages (created_at) WHERE published_at IS NULL;   -- горячий путь publisher
CREATE INDEX outbox_published_at_idx
  ON outbox_messages (published_at) WHERE published_at IS NOT NULL;  -- для purge
```

### 17.3 `jobs` и `failed_jobs`

Стандартные таблицы Laravel queue:database для `SimulateDeliveryAckJob`. Миграции: `2026_05_18_000003_*` и `2026_05_18_000004_*`.

### 17.4 Что НЕ создаём

- Таблицу `idempotency_keys` (Redis — единственный стор).
- Таблицу `subscribers` (идентификатор подписчика = его контакт, план A1).
- Таблицу `dispatch_requests` (корреляция «уведомлений одного API-вызова» не нужна клиенту).

---

## 18. Cleanup outbox

Класс: `App\Infrastructure\Notification\Console\Command\OutboxPurgeCommand`.

```
php artisan outbox:purge [--days=7]
```

Удаляет `published_at IS NOT NULL AND published_at < now() - INTERVAL '7 days'` чанками по 5000 строк.

Запланирован в `routes/console.php`:

```php
Schedule::command('outbox:purge')->dailyAt('03:00');
```

7-дневное окно — достаточно для аудита и replay при инциденте, и при этом таблица не растёт неограниченно.

---

## 19. HTTP API: контракты

### 19.1 `POST /api/v1/notifications`

**Заголовки:**
- `Idempotency-Key` (required, ≤64 байта)
- `X-Trace-Id` (optional, генерируется при отсутствии)
- `Content-Type: application/json`

**Body:**
```json
{
  "channel":   "sms" | "email",
  "priority":  "transactional" | "marketing",
  "body":      "string (1..1000 для sms, 1..10000 для email)",
  "recipients": ["+79991234567", ...]              // 1..5000 элементов
}
```

**Ответ 202:**
```json
{
  "data": {
    "accepted": 2,
    "notification_ids": ["01957c2e-...", "..."]
  }
}
```

**Ошибки:** `idempotency_key_required` (422), `idempotency_key_conflict` (409), `invalid_recipient` (422), `invalid_message_body` (422), `unknown_channel` (422), `batch_too_large` (422).

**Throttle:** 60 запросов в минуту (`throttle:60,1`).

### 19.2 `GET /api/v1/notifications?recipient={contact}`

**Query-параметры:**
- `recipient` (required, URL-encoded)
- `cursor` (optional, opaque)
- `per_page` (optional, default 20, max 100)

**Ответ 200:** см. §12.3 (массив `NotificationView` + `meta.next_cursor`).

**Ошибки:** `recipient_required` (422), `invalid_recipient` (422).

**Throttle:** 120 запросов в минуту.

### 19.3 Healthcheck

`GET /up` — стандартный Laravel `health` endpoint (см. `bootstrap/app.php` → `withRouting(health: '/up')`).

### 19.4 Документация

`GET /api/docs` — Swagger UI (Blade-шаблон `swagger.blade.php`, подгружающий swagger-ui-dist через CDN).
`GET /api/docs/openapi.yaml` — сам OpenAPI 3.0 файл.

---

## 20. Маппинг доменных исключений → HTTP

В `src/bootstrap/app.php`:

| Доменное исключение | HTTP | code |
|---|---|---|
| `InvalidRecipientException` | 422 | `invalid_recipient` |
| `InvalidMessageBodyException` | 422 | `invalid_message_body` |
| `InvalidNotificationStatusTransitionException` | 409 | `invalid_status_transition` |

Остальные ошибки (валидация Laravel, Idempotency) формируются непосредственно в middleware/FormRequest.

---

## 21. DI-биндинги

`App\Infrastructure\Notification\Provider\NotificationServiceProvider::register()` связывает порты с реализациями:

```php
$this->app->bind(NotificationRepository::class,     EloquentNotificationRepository::class);
$this->app->bind(OutboxRepository::class,           EloquentOutboxRepository::class);
$this->app->bind(NotificationReadRepository::class, EloquentNotificationReadRepository::class);
$this->app->singleton(IdempotencyStore::class,      RedisIdempotencyStore::class);
$this->app->singleton(NotificationGateway::class,   fn() => new CompositeNotificationGateway([
    new StubSmsGateway,
    new StubEmailGateway,
]));
$this->app->singleton(RabbitMqTopology::class, ...);
$this->app->singleton(OutboxPublisher::class, ...);
$this->app->singleton(ConsumeNotificationJob::class, ...);
$this->commands([
    OutboxPublishCommand::class,
    OutboxPurgeCommand::class,
    RabbitMqConsumeCommand::class,
]);
```

Регистрируется в `bootstrap/app.php` через `withProviders([NotificationServiceProvider::class])`.

---

## 22. Docker Compose — сервисы

`compose.yaml` (план §10 Фаза 0, требование N7):

| Сервис | Образ / команда | Healthcheck | Зависит от |
|---|---|---|---|
| `app` | PHP-FPM (билдится из `./src`) | `php-fpm-healthcheck` | `migrate` (completed_successfully) |
| `nginx` | `nginx:alpine` на порту 8080 | `curl /health` | `app` (healthy) |
| `postgres` | `postgres:16-alpine` | `pg_isready` | — |
| `rabbitmq` | `rabbitmq:3.13-management-alpine` (UI на 15672) | `rabbitmq-diagnostics ping` | — |
| `redis` | `redis:7-alpine`, maxmemory 256mb, `allkeys-lru` | `redis-cli ping` | — |
| `migrate` | one-shot `php artisan migrate --force` | — (restart: "no") | postgres, rabbitmq, redis (все healthy) |
| `worker-transactional` | `rabbitmq:consume notifications.transactional`, replicas=4 | `pgrep` | `migrate` (completed) |
| `worker-marketing` | `rabbitmq:consume notifications.marketing`, replicas=2 | `pgrep` | `migrate` |
| `worker-outbox` | `outbox:publish --loop`, replicas=2 | `pgrep` | `migrate` |
| `worker-default` | `queue:work database --queue=default --tries=3`, replicas=1 | `pgrep` | `migrate` |

Все воркеры запускаются с `init: true` (tini) для корректной обработки SIGTERM при `docker compose down`.

`compose.override.yaml` для разработки добавляет bind-mount `./src:/var/www/html`.

---

## 23. Тестовая стратегия

Tests pyramid под `src/tests/`:

| Уровень | Каталог | Что покрывает |
|---|---|---|
| Unit | `Unit/Domain/Notification/` | State-машина, VO, инварианты — без бутстрапа Laravel |
| Unit | `Unit/Application/Notification/` | Actions с in-memory репозиториями |
| Integration | `Integration/Infrastructure/Notification/` | Eloquent-репо, OutboxPublisher, Consumer — на реальных Postgres + RabbitMQ + Redis |
| Feature | `Feature/Notification/` | HTTP-эндпоинты + полные e2e сценарии |
| Architecture | `Architecture/LayersTest.php` | Pest `arch()` — Domain без Illuminate, Application без Eloquent/Http |

**Ключевые сценарии (план §10 Фаза 8):**

1. `Scenario1E2ETest` — главный e2e на реальном RabbitMQ.
2. `Scenario2PrioritizationTest` — приоритезация bulkhead.
3. `DispatchNotificationsTest` — идемпотентность N5.
4. `RetryTest` — transient retry + DLQ после исчерпания.
5. `Scenario8ReadApiTest` — F3 read API.
6. `Scenario10DatabaseQueueRetryTest` — at-least-once для `SimulateDeliveryAckJob`.
7. `Scenario11TraceIdTest` — trace_id propagation.
8. `Scenario12OutboxRecoveryTest` — восстановление outbox после crash (центральный для N1).

База для интеграционных тестов — `RabbitMqIntegrationTestCase`. В `setUp()`:
- declare RabbitMQ topology (idempotent),
- `queue.purge` всех `notifications.*` очередей,
- `RefreshDatabase` для Postgres,
- `Redis::flushdb()` на dedicated test-DB.

---

## 24. Привязка к `PROJECT_PLAN.md` — что и каким приёмом закрывается

| Требование | Приём в коде | Где |
|---|---|---|
| **F1** — массовая рассылка | `POST /api/v1/notifications` → FormRequest → DTO → `DispatchNotificationsAction::handle()` в транзакции | §6 |
| **F2** — приоритезация | Bulkhead через раздельные exchange-routing + раздельные пулы воркеров с разными CPU/mem | §13 |
| **F3** — история подписчика | `GET /api/v1/notifications?recipient=...`, индекс `(recipient, created_at DESC)`, cursor pagination | §12 |
| **F4** — 4 статуса | State-машина в `Notification` (Queued → Sent → Delivered/Dropped), `status_history` JSONB | §5.3 |
| **N1** — персистентность | Postgres durable + RabbitMQ durable + Transactional Outbox через `OutboxRepository` + `OutboxPublisher` с `FOR UPDATE SKIP LOCKED` | §6, §7 |
| **N2** — at-least-once | Manual ack в `ConsumeNotificationJob` после `repo->save($n)`; database queue с `--tries=3` для ack-job | §8, §15 |
| **N3** — exactly-once (бонус) | State check в `DeliverNotificationAction` + optimistic lock в `EloquentNotificationRepository::save()` + дедупликация шлюза через Redis Hash | §8.3, §11.2, §15 |
| **N4** — retry | Per-message `expiration` + DLX-цикл `notifications.direct → retry-queue → notifications.direct`, max 5 попыток, backoff `1s,5s,25s,125s`, после исчерпания → `notifications.dlq` + `markAsDropped('max_retries_exceeded')` | §9 |
| **N5** — идемпотентность | `IdempotencyMiddleware` + `RedisIdempotencyStore`, ключ `idempotency:notifications.dispatch:{key}`, TTL 24ч | §6 шаг 3, §14 |
| **N6** — интеграционные тесты | `Scenario1E2ETest` + другие на реальном RabbitMQ/Postgres/Redis в том же `compose.yaml` | §23 |
| **N7** — Docker Compose | `compose.yaml` с healthchecks + one-shot `migrate` + `service_completed_successfully` зависимостями | §22 |
| **S1** — публичный репозиторий | Корень проекта | — |
| **S2** — README | `README.md` | — |
| **S3** — Swagger | `src/resources/api-docs/openapi.yaml` + Swagger UI на `/api/docs` | §19.4 |

### 24.1 Дополнительные приёмы, которые держат архитектурные границы

- **DDD-границы** охраняются Pest `arch()`-тестами в `tests/Architecture/LayersTest.php`. Если кто-то импортирует `Illuminate\Database\Eloquent` в Application — тест красный.
- **`OutboxRepository` как порт в Application**, реализация в Infrastructure: `DispatchNotificationsAction` не знает про Eloquent.
- **`Recipient::masked()` — единая точка маскирования PII**. Все логи и read-DTO пропускают контакт через этот метод.
- **`MessageBody::for()`** — единственная фабрика, читает channel-зависимый лимит из конфига; Domain остаётся framework-free (fallback на константы при отсутствии `config()`).
- **`Notification::create()` / `::reconstitute()`** — private constructor, single point of construction. Невозможно создать notification с битыми инвариантами.
- **Domain events диспатчатся через `DB::afterCommit`** в `DispatchNotificationsAction` — подписчики не сработают до коммита транзакции.

### 24.2 Что НЕ делаем (по плану §12)

- Bearer / OAuth — auth не в TASK.
- Webhook от провайдера — только заглушки.
- Kafka — RabbitMQ закрывает приоритезацию проще.
- Circuit Breaker / outbound rate limiter — не в TASK, запрещено как future-proofing.
- Event Sourcing — обычная state-машина проще.
- CQRS read-side projections — обычный read-репозиторий достаточен для F3.
- Таблицы `subscribers`, `dispatch_requests`, `idempotency_keys`.

---

## 25. Быстрый чек-лист для нового разработчика

Чтобы войти в проект:

1. **Прочитать `TASK.md`** — бизнес-требования.
2. **Прочитать `PROJECT_PLAN.md`** — архитектурное обоснование каждого решения. Особенно §3 (архитектурные приёмы) и §9 (end-to-end сценарий).
3. **Поднять стек:** `cp src/.env.example src/.env && docker compose up -d`.
4. **Открыть Swagger** на `http://localhost:8080/api/docs`.
5. **Открыть RabbitMQ UI** на `http://localhost:15672` (guest/guest).
6. **Шлёт POST** через curl (см. README §«Примеры использования»).
7. **Запустить тесты:** `docker compose exec app php artisan test`.

**Чтобы изменить бизнес-логику:**
- Доменные правила → `Notification` entity + VO. State-переходы — только методы агрегата.
- Новый use case → новый Action в `Application/Notification/UseCase/`. Без прямого Eloquent, только через порты.
- Новые поля БД → миграция в `src/database/migrations/` + поправить `NotificationModel`, `NotificationMapper`, миграция `version` если меняется агрегат.
- Новый HTTP-эндпоинт → invokable Controller + FormRequest + Resource в `Interface/Http/Notification/`.

**Чтобы изменить инфраструктуру:**
- Новая реализация порта — в `Infrastructure/Notification/`. Биндинг в `NotificationServiceProvider`.
- Новые exchanges/queues — в `RabbitMqTopology::declare()`.
- Новые console commands — в `Infrastructure/Notification/Console/Command/`, регистрация в `NotificationServiceProvider::register()` через `$this->commands([...])`.

**Архитектурные ограничения, которые нельзя нарушать:**
- Domain не импортирует `Illuminate`, `Eloquent`, `Carbon` (mutable), `Symfony`.
- Application не импортирует `Illuminate\Database\Eloquent`, `Illuminate\Http`, `Illuminate\Support\Facades\DB`.
- Controllers тонкие: Request → DTO → Action → Resource. Никакой бизнес-логики.

Эти инварианты охраняются Pest `arch()`-тестами — нарушение поломает CI.
