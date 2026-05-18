# Notification Service — Архитектура и план разработки

> Микросервис уведомлений: массовая рассылка SMS/Email с приоритезацией, гарантией доставки и идемпотентностью. Тир сложности — **Complex (DDD)** по правилам проекта (`.claude/CLAUDE.md`): state-машина уведомления, инварианты, асинхронная доставка через брокер.
>
> Этот документ — точный план реализации `test.md`. Каждый компонент сопоставлен с пунктом задания (см. §1.1–1.3). Всё, что не требуется в `test.md` и без чего можно обойтись, явно вынесено в §12 «Что не делаем» с обоснованием.

---

## 1. Цели и требования

Дословные требования из `test.md`, привязанные к разделам плана.

### 1.1 Функциональные требования

| # | Требование `test.md` | Где реализовано |
|---|---|---|
| F1 | Массовая рассылка SMS/Email через API: канал, текст, массив идентификаторов получателей | §6.1 `POST /api/v1/notifications`; §6.2 тело запроса; §10 Фазы 3, 5 |
| F2 | Приоритезация: транзакционные обгоняют маркетинговые | §3.1; §8; §10 Фаза 6 (раздельные очереди + воркеры); §10 Фаза 8 (явный тест приоритетности) |
| F3 | API истории и текущих статусов уведомлений конкретного подписчика | §6.1 `GET /api/v1/notifications?recipient={contact}`; §10 Фаза 4 |
| F4 | Статусы: `queued`, `sent`, `delivered`, `dropped` | §4.1 state-машина; §4.2 VO `NotificationStatus`; §7 схема |

### 1.2 Нефункциональные требования

| # | Требование `test.md` | Где реализовано |
|---|---|---|
| N1 | Персистентность очереди (брокер + БД) | §3.4 Outbox; §7 (таблицы `notifications`, `outbox_messages`); §8 (durable RabbitMQ) |
| N2 | At-least-once | §3.2; §10 Фаза 6 (manual ack только после persist статуса `sent`) |
| N3 | Exactly-once на бизнес-уровне (бонус) | §3.2 (state check + optimistic lock по `version`); §10 Фаза 3 |
| N4 | Retry при временной недоступности шлюза | §3.5 (TTL + DLX, exponential backoff, max 5 попыток); §8 |
| N5 | Идемпотентность входящих API-запросов | §3.3 (`Idempotency-Key` обязательный, Redis-стор); §10 Фаза 5 |
| N6 | Интеграционные тесты на полную цепочку (очередь → провайдер → БД) | §10 Фаза 8 (e2e на **реальном** RabbitMQ, не моки) |
| N7 | Docker-образ + `docker-compose up` поднимает всё | §10 Фаза 0 (compose с healthchecks, авто-миграции в entrypoint) |

### 1.3 Артефакты сдачи

| # | Требование `test.md` | Где |
|---|---|---|
| S1 | Публичный репозиторий (GitHub/GitLab) | Корень проекта |
| S2 | README с пошаговой инструкцией `docker compose up` | §10 Фаза 9 |
| S3 | Swagger (OpenAPI) или Postman-коллекция | §10 Фаза 9 (`openapi.yaml` + Swagger UI на `/api/docs`; Postman не делается, R8/R21) |

### 1.4 Допущения, явно зафиксированные

**A1. Идентификатор получателя = его контакт (phone/email).** `test.md` говорит «массив идентификаторов получателей» и «уведомления конкретного подписчика». Простейшая трактовка: получатель идентифицируется своим контактом — без дополнительной opaque-сущности `subscriber_id`. Контракт:
- В `POST /api/v1/notifications` `recipients[]` — массив строк-контактов (phone для SMS, email для Email).
- F3 («уведомления конкретного подписчика») реализуется через `GET /api/v1/notifications?recipient={contact}` (query-параметр, не path: контакт — PII, и query легче исключить из access-логов через nginx `log_format` без `args`).
- В таблице `notifications` хранится одно поле `recipient`. Реестра подписчиков сервис не ведёт.
- В ответах F3 контакт возвращается в **маскированной** форме (`Recipient::masked()`), чтобы клиент в логах/отладке видел, на что ушло, но без полной утечки PII.

**A2. `Idempotency-Key` обязателен.** `test.md` требует «защиту от повторной отправки». Самое чёткое контрактное решение — обязать клиента передавать ключ. Сервер хранит `(key, request_hash, response)` и возвращает кэшированный ответ при повторе. Без ключа — `422`. Альтернатива (серверный хэш тела) отвергнута: ломает легитимные повторные рассылки одного контента (например, периодическая маркетинговая кампания).

**A3. Webhook-эндпоинта от провайдера нет.** `test.md` явно требует **только заглушки**: «для внешних шлюзов используй классы-заглушки, которые имитируют работу реальных провайдеров». Имитация перехода `Sent → Delivered/Dropped` реализована **внутренним** `SimulateDeliveryAckJob` без HTTP-цикла. Эндпоинт `/providers/{p}/webhook` не реализуется.

**A4. Database queue для `SimulateDeliveryAckJob`.** Имитация колбэка провайдера выполняется через `queue:database`-драйвер (а не Redis). Database queue имеет встроенный retry: если worker упал между `pop` и `delete`, джоба будет переподнята после `retry_after` (90 сек по умолчанию). Это устраняет потребность в reconciliation-команде для «висящих» `sent`. После `markAsSent` диспатчится `SimulateDeliveryAckJob::dispatch($notificationId)->onConnection('database')->delay(rand(1,3))`. Запускается одним общим воркером `worker-default` (`php artisan queue:work database --queue=default`).

**A5. Лимит `body` зависит от канала.** `test.md` лимит не задаёт. Выбрано:
- `Channel::Sms` — 1..1000 символов (≈ 6 GSM-сегментов / 14 Unicode-сегментов в худшем случае; ограничение задано осознанно, чтобы клиент не отправил по ошибке многосегментный SMS, не зная про segment-биллинг);
- `Channel::Email` — 1..10000 символов (типовые транзакционные сценарии — OTP, маршрутные оповещения, статусы — укладываются с запасом; HTML-шаблоны не поддерживаются — `body` это plain text).

Лимиты вынесены в `config/notifications.php` (`body_max.sms`, `body_max.email`) и читаются в `MessageBody::for(Channel, string)`.

---

## 2. Технологический стек

| Слой | Выбор | Обоснование (со ссылкой на `test.md`) |
|---|---|---|
| Язык / фреймворк | **PHP 8.4 + Laravel 13** | Рекомендация `test.md`: «PHP (Laravel)» |
| База данных | **PostgreSQL 16** | Рекомендация `test.md`. Нужны: `jsonb` (status_history), partial index (`WHERE status='queued'`), `FOR UPDATE SKIP LOCKED` (параллельная публикация outbox), `timestamptz` |
| Брокер | **RabbitMQ 3.13** | `test.md`: «Apache Kafka или RabbitMQ (что лучше)». RabbitMQ выбран: (а) bulkhead через раздельные очереди для F2, (б) push + manual ack для N2, (в) DLX + TTL для retry N4 без кастомного scheduler’а, (г) меньше операционных издержек на одно приложение |
| Кэш / in-memory | **Redis 7** | `test.md`: «Redis (для дедубликации и контроля лимитов)». Применение: (а) idempotency-store N5, (б) Redis Hash для дедупликации stub-провайдером по `NotificationId` (см. §6.4). `SimulateDeliveryAckJob` использует `queue:database` (см. A4), не Redis. |
| AMQP-клиент | `php-amqplib/php-amqplib` + `vladimir-yuldashev/laravel-queue-rabbitmq` | Драйвер Laravel queue поверх AMQP: headers, manual ack, DLX |
| Тесты | **Pest 4** (PHPUnit 12 — fallback) | Авто-детект по `src/composer.json` (правило проекта) |
| Статический анализ | **PHPStan L8** + **Larastan** | Границы слоёв через типы |
| API-документация | **OpenAPI 3.0** + Swagger UI (CDN) | `test.md` S3. Файл `src/storage/api-docs/openapi.yaml` отдаётся как статика, Swagger UI рендерится в браузере через CDN. Без PHP-пакета, чтобы не зависеть от поддержки 3.1 в l5-swagger. Postman-коллекция не делается (избыточно при наличии Swagger UI). |
| Контейнеризация | **Docker + Docker Compose** (файл `compose.yaml`) | `test.md`: «`docker-compose up`». Имя файла `compose.yaml` — современная рекомендация Docker; команда `docker compose up` и `docker-compose up` его одинаково понимают. |

**Что НЕ берём (с обоснованием против `test.md`):**

- **Kafka** — оверкилл; RabbitMQ закрывает приоритезацию + ack + retry проще.
- **Sanctum / Bearer-аутентификация** — `test.md` ничего не говорит про auth; сервис межсервисный во внутренней сети.
- **HMAC webhook от провайдера** — нет webhook-эндпоинта (см. A3); только заглушки.
- **Circuit breaker / outbound rate limiter** — `test.md` не упоминает деградацию шлюзов и outbound throttling. «Redis для контроля лимитов» в `test.md` закрывается входным `throttle:60,1` на API (см. §10 Фаза 5). Введение исходящего circuit breaker и token bucket — это «future-proofing», запрещённый `CLAUDE.md §6`.
- **Pulse / Horizon / Octane / OpenTelemetry** — observability за пределами `test.md`. Structured logs с `trace_id` достаточны для требований задания.
- **Spatie Event Sourcing** — `test.md` не требует event-sourcing; обычная state-машина проще.
- **CQRS read-side projections** — `GET /api/v1/notifications?recipient=...` обслуживается обычным read-репозиторием с DTO; отдельный материализованный view не нужен.

---

## 3. Архитектурные решения

### 3.1 Приоритезация (F2) — bulkhead через раздельные очереди

Две физически разных очереди + два пула воркеров. Маркетинг **физически не может** забрать ресурсы транзакционных воркеров — это сильнее, чем `x-max-priority` внутри одной очереди.

```
notifications.transactional → worker-transactional (4 процесса) → providers
notifications.marketing     → worker-marketing     (2 процесса) → providers
```

Внутри каждой очереди FIFO. Между очередями приоритет гарантируется аллокацией процессов и явно подтверждается тестом из §10 Фаза 8.

### 3.2 Семантика доставки (N2 + бонус N3)

- **At-least-once на инфраструктуре:** RabbitMQ `durable` exchanges/queues + `persistent` сообщения + `manual ack` **только** после успешной записи статуса `sent` в БД. Если воркер упал между ответом провайдера и `repo->save()`, сообщение вернётся в очередь.
- **Exactly-once на бизнес-уровне:** перед вызовом провайдера `DeliverNotificationAction` делает (а) `state check` (`$n->status() === Queued`), (б) `optimistic lock` по `version`. Повторное потребление сообщения после `Sent` → no-op + ACK. Дубль вызова провайдера исключён.

### 3.3 Идемпотентность (N5)

**Контракт.** `POST /api/v1/notifications` **обязан** содержать заголовок `Idempotency-Key` (строка ≤ 64 байт). Отсутствие → `422`. Это единственный канонический контракт между сервисом и вызывающей стороной.

**Хранилище — Redis** (один источник истины, отдельной PG-таблицы нет — `test.md` прямо говорит «Redis для дедубликации»):
- ключ: `idempotency:notifications.dispatch:{key}` — namespace на use-case заложен заранее, чтобы при расширении сервиса другими idempotent-эндпоинтами не возникало коллизий по ключу
- значение: JSON `{ "request_hash": "<sha256 of body>", "status_code": 202, "response": "<json>" }`
- TTL: 24 часа.
- **Настройка Redis:** для предотвращения OOM при всплесках трафика используется политика вытеснения `allkeys-lru`.

**Поведение middleware:**
- ключ есть + `request_hash` совпадает → вернуть закэшированный `status_code` + `response`;
- ключ есть + `request_hash` отличается → `409 Conflict` («Idempotency-Key reused with different payload»);
- ключа нет → выполнить запрос; на успехе записать ответ под этот ключ с TTL.

### 3.4 Outbox (N1)

Закрывает dual-write: если транзакция в БД зафиксировалась, а процесс упал до публикации в RabbitMQ, без outbox уведомление навсегда осталось бы в `queued`.

**Application НЕ импортирует Eloquent.** Запись в outbox идёт через порт `OutboxRepository` (интерфейс в `Application/Notification/Outbox/OutboxRepository.php`, реализация `EloquentOutboxRepository` в Infrastructure). Это держит правило `layers_context.md` («Application не вызывает Eloquent напрямую»).

Один транзакционный шаг в `DispatchNotificationsAction::handle()` пишет:
- N строк `notifications` (status=`queued`) — через `NotificationRepository::saveMany()`, bulk insert чанками по `NOTIFICATIONS_INSERT_CHUNK = 2000` (13 колонок × 2000 = 26k bind params, в 2.5 раза ниже лимита PG ≈65k);
- N строк `outbox_messages` — через `OutboxRepository::appendMany(OutboxEntry[])`, той же длины чанками. Чанкование обязательно: при `batch_max = 5000` единый INSERT пробил бы лимит PG.

**Один механизм публикации** — никаких poke-job’ов и scheduled-минут:

`worker-outbox` запускает консольную команду `php artisan outbox:publish --loop` (custom command в `Infrastructure/Notification/Console/Command/OutboxPublishCommand.php`):

```
while (!shouldStop) {
  $count = $publisher->flush(batchSize: 100);   // SELECT ... FOR UPDATE SKIP LOCKED ... LIMIT 100
  if ($count === 0) usleep(500_000);            // 500ms poll при пустой outbox
}
```

Два процесса работают параллельно (`FOR UPDATE SKIP LOCKED` гарантирует отсутствие дублей). Лаг от commit до публикации ≤ 500ms. Graceful shutdown по `SIGTERM`.

**Payload в AMQP:** минимальный — `{ "notification_id": "<uuid>" }`. Тело сообщения, recipient, channel и т.д. живут только в таблице `notifications` и подгружаются потребителем по id. Это исключает рассинхронизацию «снимка» в AMQP с актуальным состоянием БД.

**AMQP-headers:**
- `x-retries` — счётчик неудачных попыток (см. §3.5).
- `x-trace-id` *(бонус, observability сверх `test.md`)* — корреляционный идентификатор для сквозного трейсинга. Берётся из `X-Trace-Id` HTTP-заголовка (или генерируется в Middleware), хранится в `notifications.trace_id` (одно поле на уведомление, без дублирования в `outbox_messages`), читается `OutboxPublisher`'ом JOIN'ом и проставляется в AMQP-header. Потребитель кладёт в `Log::withContext(['trace_id' => ...])`. Если функциональность убирается — не затрагивает соответствие `test.md`.

**Cleanup outbox.** Опубликованные строки чистит scheduled-команда `php artisan outbox:purge` (`Schedule::command('outbox:purge')->dailyAt('03:00')` в `routes/console.php`): удаляет `WHERE published_at IS NOT NULL AND published_at < now() - INTERVAL '7 days'` чанками по 5000 строк. Семидневное окно — достаточно для аудита и replay при инциденте, при этом таблица не растёт неограниченно.

### 3.5 Retry / DLQ (N4)

- **До 5 попыток доставки** при transient-ошибках провайдера (`GatewayUnavailableException`, `GatewayTimeoutException`). После 5-й неудачи → drop.
- **Exponential backoff с jitter:** 4 интервала между 5 попытками — `1s, 5s, 25s, 125s` (см. `NOTIFICATIONS_RETRY_BACKOFF_MS` в Фазе 0). Реализуется через per-message `expiration` (а не queue-level `x-message-ttl`) на одной retry-очереди + DLX обратно в основной exchange. Per-message `expiration` позволяет иметь разный delay для разных x-retries в одной очереди без 4 отдельных queue-определений.
- **Счётчик неудач** в AMQP-header `x-retries` (не в payload). Поле `attempts` в БД (= число **неудачных** попыток, R10) синхронизируется при каждой неудаче через `Notification::recordFailedAttempt($error)`. Успешная попытка `attempts` НЕ инкрементирует.
- После 5-й неудачи (когда `attempts == 5`) → publish в `notifications.dlq` + `markAsDropped(reason='max_retries_exceeded')`.
- На **permanent**-ошибке (`GatewayRejectedException` — «несуществующий номер/email» из `test.md`) → `recordFailedAttempt($e->getMessage())` (`attempts = 1`) + `markAsDropped(reason)`, без retry. ACK.

### 3.6 Разделение `last_error` и причины дропа

- `notifications.last_error` (TEXT) — сообщение **последнего** исключения провайдера (`$e->getMessage()`). Обновляется в `Notification::recordFailedAttempt($error)` на каждой неудаче. На успехе остаётся `NULL` (если до этого не было неудач).
- `notifications.status_history[last].reason` — **причина перехода** в `dropped` (`max_retries_exceeded`, `provider_rejected: <msg>`). Записывается в `Notification::markAsDropped($reason)`. Причины `ack_timeout` больше нет (см. A4 — database queue устраняет «висящие» `sent`).

Тесты на сценарии дропа ассертят `status_history`, а не `last_error`, потому что `last_error` хранит сырое сообщение последнего gateway-исключения, а причина перехода — отдельный концепт state-машины.

---

## 4. Bounded Context и доменная модель

**Bounded Context: `Notification`** — один.

### 4.1 Aggregate root — `Notification`

```
final class Notification
{
    private function __construct(
        NotificationId $id,
        Recipient $recipient,
        Channel $channel,
        Priority $priority,
        MessageBody $body,
        NotificationStatus $status,
        StatusHistory $history,
        int $attempts,                 // только число НЕУДАЧНЫХ попыток (R10)
        ?string $lastError,
        ?ProviderMessageId $providerMessageId,
        ?string $traceId,              // бонус (R6): observability; nullable
        int $version,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {}

    public static function create(Recipient $r, Channel $c, Priority $p, MessageBody $b, ?string $traceId = null): self;
    public static function reconstitute(...): self;

    public function markAsSent(ProviderMessageId $pid): void;     // успех не трогает attempts
    public function markAsDelivered(): void;
    public function markAsDropped(string $reason): void;
    public function recordFailedAttempt(string $error): void;      // ++attempts, lastError = $error
}
```

`Recipient` — контакт получателя (phone/email), он же стабильный идентификатор подписчика для F3 (см. A1). Дополнительной opaque-сущности `subscriber_id` нет. В логах `Recipient` всегда выводится через `masked()` (см. §4.2).

#### State-машина

```
        ┌──────────┐  markAsSent      ┌──────┐  markAsDelivered  ┌───────────┐
        │  Queued  ├─────────────────►│ Sent ├──────────────────►│ Delivered │
        └────┬─────┘                  └──┬───┘                   └───────────┘
             │                           │
             │ markAsDropped(reason)     │ markAsDropped(reason)
             ▼                           ▼
        ┌──────────┐                ┌──────────┐
        │  Dropped │                │  Dropped │
        └──────────┘                └──────────┘
```

Любой переход из `Delivered` или `Dropped` → `InvalidNotificationStatusTransitionException`.

### 4.2 Value Objects

`NotificationId` (UUIDv7), `Channel` (enum `Sms|Email`), `Priority` (enum `Transactional|Marketing`), `NotificationStatus` (enum `Queued|Sent|Delivered|Dropped`), `Recipient`, `MessageBody`, `ProviderMessageId`, `StatusHistory` (immutable коллекция событий со временем).

Все `readonly class`, equality by value.

`Recipient` (контакт = идентификатор подписчика, см. A1):
- для `Channel::Sms` валидирует E.164 (`/^\+[1-9]\d{6,14}$/`);
- для `Channel::Email` валидирует RFC 5322 (через `filter_var`);
- `masked(): string` — `+7***4567` / `j***@example.com`. **Все** логи пропускают recipient через этот метод — PII не утекает;
- индексируется по `(recipient, created_at DESC)` (см. §7) для F3.

`MessageBody`:
- `MessageBody::for(Channel $channel, string $body): self` — единственная фабрика; читает лимит из `config('notifications.body_max.'.$channel->value)` (`sms` → 1000, `email` → 10000, см. A5);
- кидает `InvalidMessageBodyException` (422) при нарушении длины.

### 4.3 Domain Events (past-tense, только id)

- `NotificationQueued(NotificationId, Priority)`
- `NotificationSent(NotificationId, ProviderMessageId)`
- `NotificationDelivered(NotificationId)`
- `NotificationDropped(NotificationId, string $reason)`

Все диспатчатся через `DB::afterCommit(...)`. Используются для structured-логов и тестов; внешние подписчики не предусмотрены.

### 4.4 Domain Exceptions

| Исключение | HTTP-маппинг |
|---|---|
| `InvalidRecipientException` | 422 |
| `InvalidMessageBodyException` | 422 |
| `InvalidNotificationStatusTransitionException` | 409 |
| `UnknownChannelException` | 422 |

`EmptyDispatchException` намеренно отсутствует — все батч-проверки (`recipients|array|min:1|max:5000`) выполняет `DispatchNotificationsRequest` (см. §10 Фаза 5); Action недостижим в обход FormRequest.

Маппинг в `src/bootstrap/app.php` → `withExceptions(...)`.

### 4.5 Порты

Domain-порты — `NotificationRepository`, `NotificationGateway`. Application-порты — `NotificationReadRepository`, `OutboxRepository`, `IdempotencyStore`. Все реализации — в Infrastructure.

```
// Domain/Notification/Repository/NotificationRepository.php
interface NotificationRepository
{
    public function save(Notification $n): void;          // optimistic lock by version
    public function saveMany(Notification ...$ns): void;  // bulk insert чанками
    public function findById(NotificationId $id): ?Notification;
}

// Domain/Notification/Gateway/NotificationGateway.php
interface NotificationGateway
{
    public function supports(Channel $channel): bool;

    /**
     * @throws GatewayTimeoutException        transient → retry
     * @throws GatewayUnavailableException    transient → retry
     * @throws GatewayRejectedException       permanent (несуществующий номер/email) → dropped без retry
     *
     * NotificationId передаётся как idempotency-key провайдеру.
     */
    public function send(Notification $n): GatewayResult;   // { ProviderMessageId, providerStatus }
}
```

`NotificationReadRepository` (для query-стороны F3) — интерфейс в `Application/Notification/ReadRepository/`, реализация в Infrastructure. Не выходит за пределы Application/Infrastructure.

`OutboxRepository` — порт для append-only записи в outbox (см. §3.4). Живёт в Application, не в Domain: outbox — это интеграционный паттерн доставки, а не доменный концепт. Не путать с `NotificationRepository` (write-репозиторием агрегата).

```
// Application/Notification/Outbox/OutboxEntry.php
// trace_id хранится в Notification (см. §3.4, §7), не в outbox.
final readonly class OutboxEntry
{
    public function __construct(
        public NotificationId $notificationId,
        public Priority $priority,
    ) {}
}

// Application/Notification/Outbox/OutboxRepository.php
interface OutboxRepository
{
    /**
     * Bulk append в outbox в текущей транзакции, чанкуется внутри реализации.
     *
     * @param OutboxEntry[] $entries
     */
    public function appendMany(array $entries): void;
}
```

---

## 5. Структура каталогов (layer-first)

```
src/
├── app/
│   ├── Domain/Notification/
│   │   ├── Entity/Notification.php
│   │   ├── ValueObject/
│   │   │   ├── NotificationId.php
│   │   │   ├── Channel.php
│   │   │   ├── Priority.php
│   │   │   ├── NotificationStatus.php
│   │   │   ├── Recipient.php
│   │   │   ├── MessageBody.php
│   │   │   ├── ProviderMessageId.php
│   │   │   └── StatusHistory.php
│   │   ├── Repository/NotificationRepository.php
│   │   ├── Gateway/
│   │   │   ├── NotificationGateway.php
│   │   │   └── GatewayResult.php
│   │   ├── Event/
│   │   │   ├── NotificationQueued.php
│   │   │   ├── NotificationSent.php
│   │   │   ├── NotificationDelivered.php
│   │   │   └── NotificationDropped.php
│   │   └── Exception/
│   │       ├── InvalidRecipientException.php
│   │       ├── InvalidMessageBodyException.php
│   │       ├── InvalidNotificationStatusTransitionException.php
│   │       ├── UnknownChannelException.php
│   │       ├── GatewayTimeoutException.php
│   │       ├── GatewayUnavailableException.php
│   │       └── GatewayRejectedException.php
│   │
│   ├── Application/Notification/
│   │   ├── UseCase/
│   │   │   ├── DispatchNotifications/
│   │   │   │   ├── DispatchNotificationsAction.php
│   │   │   │   ├── DispatchNotificationsData.php
│   │   │   │   └── DispatchAcceptedResult.php
│   │   │   ├── DeliverNotification/
│   │   │   │   ├── DeliverNotificationAction.php
│   │   │   │   └── DeliverNotificationData.php
│   │   │   └── AcknowledgeDelivery/
│   │   │       ├── AcknowledgeDeliveryAction.php
│   │   │       └── AcknowledgeDeliveryData.php
│   │   ├── Query/GetSubscriberNotifications/
│   │   │   ├── GetSubscriberNotificationsQuery.php
│   │   │   ├── GetSubscriberNotificationsHandler.php
│   │   │   └── NotificationView.php
│   │   ├── ReadRepository/NotificationReadRepository.php
│   │   ├── Outbox/
│   │   │   ├── OutboxRepository.php          # порт, реализация в Infrastructure
│   │   │   └── OutboxEntry.php               # DTO: NotificationId + Priority
│   │   └── Idempotency/IdempotencyStore.php
│   │
│   ├── Infrastructure/Notification/
│   │   ├── Persistence/Eloquent/
│   │   │   ├── Models/
│   │   │   │   ├── NotificationModel.php
│   │   │   │   └── OutboxMessageModel.php
│   │   │   ├── Repositories/
│   │   │   │   ├── EloquentNotificationRepository.php
│   │   │   │   ├── EloquentNotificationReadRepository.php
│   │   │   │   └── EloquentOutboxRepository.php      # appendMany чанками по 2000
│   │   │   └── Mappers/NotificationMapper.php
│   │   ├── Messaging/
│   │   │   ├── RabbitMqTopology.php           # idempotent declare exchanges/queues
│   │   │   ├── OutboxPublisher.php            # SELECT ... FOR UPDATE SKIP LOCKED → publish (JOIN notifications для trace_id)
│   │   │   └── ConsumeNotificationJob.php     # AMQP-сабж, читает x-retries + x-trace-id из headers
│   │   ├── Gateway/
│   │   │   ├── StubSmsGateway.php
│   │   │   ├── StubEmailGateway.php
│   │   │   └── CompositeNotificationGateway.php
│   │   ├── Idempotency/RedisIdempotencyStore.php
│   │   ├── Http/Middleware/
│   │   │   └── TraceIdMiddleware.php          # бонус (R6): X-Trace-Id propagation
│   │   ├── Job/SimulateDeliveryAckJob.php     # имитация колбэка от провайдера (queue:database, см. A4)
│   │   ├── Console/Command/
│   │   │   ├── OutboxPublishCommand.php       # continuous-цикл публикации
│   │   │   └── OutboxPurgeCommand.php         # daily cleanup опубликованных строк (>7d)
│   │   └── Provider/NotificationServiceProvider.php
│   │
│   └── Interface/Http/Notification/
│       ├── Controller/
│       │   ├── DispatchNotificationsController.php
│       │   └── GetNotificationsByRecipientController.php
│       ├── Middleware/IdempotencyMiddleware.php
│       ├── Request/DispatchNotificationsRequest.php
│       └── Resource/
│           ├── DispatchAcceptedResource.php
│           ├── NotificationResource.php
│           └── NotificationCollection.php
│
├── config/
│   ├── notifications.php       # retry policy, batch limits
│   └── queue.php               # rabbitmq + redis connections
├── database/migrations/
│   ├── *_create_notifications_table.php
│   └── *_create_outbox_messages_table.php
├── routes/
│   ├── api.php
│   └── console.php
├── storage/api-docs/openapi.yaml
└── tests/
    ├── Unit/Domain/Notification/
    ├── Unit/Application/Notification/
    ├── Integration/Infrastructure/Notification/
    ├── Feature/Notification/
    └── Architecture/LayersTest.php
```

**Что НЕ создаём** (отвечая на возможный вопрос «а где?»):

- Отдельного агрегата `DispatchRequest` и таблицы `dispatch_requests` — `test.md` не требует истории батчей; корреляция между уведомлениями одного запроса не нужна вызывающей стороне.
- Таблицы `idempotency_keys` в Postgres — идемпотентность хранится только в Redis (см. §3.3 и допущение A2).
- Контроллера `GetNotificationController` для `GET /notifications/{id}` — `test.md` требует только историю **по подписчику** (по `recipient`), не статус одного уведомления.
- Отдельной таблицы `subscribers` и VO `SubscriberId` — идентификатор подписчика = его `recipient` (см. A1).
- Контроллера `ProviderWebhookController` — webhook-эндпоинт не реализуется (A3).
- Команды `ReapStuckSentCommand` — не нужна благодаря database-queue с встроенным retry для `SimulateDeliveryAckJob` (см. A4).
- Классов `RedisTokenBucket` — не упомянуты в `test.md`.
- Job’ов `OutboxPokeJob`, `PublishOutboxJob` (scheduled) — единственный механизм публикации outbox — continuous-команда `outbox:publish --loop`. Из планировщика только `outbox:purge` (раз в сутки).

---

## 6. Контракты слоёв

### 6.1 HTTP API

| Метод | URL | Назначение | Тело / параметры | Успех |
|---|---|---|---|---|
| `POST` | `/api/v1/notifications` | Принять массовую рассылку (F1) | JSON (см. §6.2). Заголовок `Idempotency-Key: <str ≤ 64>` обязателен | `202 Accepted` |
| `GET` | `/api/v1/notifications` | История и статусы уведомлений подписчика (F3) | Query: `?recipient=<contact>&cursor=<opaque>&per_page=20` (max 100). `recipient` — обязателен, URL-encoded; контакт **не выводится в access-log** (nginx-конфиг исключает `args` для этого пути). | `200 OK` |

`recipient` намеренно передаётся как query-параметр, а не path-параметр: контакт — PII, и `args` легче исключать из логов и метрик, чем сегменты пути.

**Заголовки ответов:**
- `X-Trace-Id` *(бонус, см. §3.4)*: возвращается во всех ответах (успех/ошибка) для облегчения диагностики.

**Аутентификация:** не предусмотрена — `test.md` не требует. Сервис разворачивается во внутренней сети.

**Базовый формат ответа:**
```
{ "data": { ... }, "meta": { ... }, "links": { ... } }
```

**Формат ошибки:**
```
{ "error": { "code": "invalid_recipient", "message": "...", "trace_id": "req_..." } }
```

**Коды ошибок (стабильные):** `idempotency_key_required` (422), `idempotency_key_conflict` (409), `invalid_recipient` (422), `invalid_message_body` (422), `empty_recipients` (422), `batch_too_large` (422), `unknown_channel` (422), `invalid_status_transition` (409), `notification_not_found` (404), `recipient_required` (422).

### 6.2 Тело `POST /api/v1/notifications`

```json
{
  "channel": "sms",
  "priority": "transactional",
  "body": "Your code is 123456",
  "recipients": [
    "+79991234567",
    "+79007654321"
  ]
}
```

**Контракт:**
- `channel`: `"sms"` или `"email"`. Иначе → 422 `unknown_channel`.
- `priority`: `"transactional"` или `"marketing"`. Иначе → 422.
- `body`: 1..N символов, где `N` зависит от канала (`sms` → 1000, `email` → 10000; см. A5). Нарушение → 422 `invalid_message_body`.
- `recipients`: массив **строк-контактов**, 1..5000 элементов (`NOTIFICATIONS_BATCH_MAX`). Каждый элемент валидируется по `channel` (`Recipient::fromString`); иначе → 422 `invalid_recipient`. Любая невалидная запись → **весь запрос отклоняется** с 422 (all-or-nothing семантика — нет частичного приёма, нет поля `rejected` в ответе; обоснование в README/OpenAPI, см. Фазу 9).
- `Idempotency-Key` (header): обязателен. Без него → 422 `idempotency_key_required`.

**Ответ 202:**
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

`notification_ids` — UUIDv7 в каноническом hex-формате (см. §4.2).

Порядок `notification_ids` соответствует порядку `recipients` в запросе — клиент может коррелировать. При `batch=5000` тело ответа достигает ≈200 KB — это документируется в OpenAPI (см. Фазу 9).

### 6.3 Тело `GET /api/v1/notifications?recipient={contact}`

**Query-параметры:**
- `recipient` — обязателен; контакт получателя, валидируется как E.164 (если в БД был сохранён как SMS) или RFC 5322 email. Если формат не распознан → 422 `invalid_recipient`. Если отсутствует → 422 `recipient_required`.
- `cursor` (опционально), `per_page` (default 20, max 100).

**Ответ 200:**
```json
{
  "data": [
    {
      "id": "01957c2e-3a8e-7c1d-b4f0-2a8e4c1d9f01",
      "channel": "sms",
      "priority": "transactional",
      "status": "delivered",
      "recipient_masked": "+7***4567",
      "attempts": 0,
      "last_error": null,
      "status_history": [
        { "status": "queued",    "at": "2026-05-18T10:00:00Z" },
        { "status": "sent",      "at": "2026-05-18T10:00:01Z" },
        { "status": "delivered", "at": "2026-05-18T10:00:03Z" }
      ],
      "created_at": "2026-05-18T10:00:00Z",
      "updated_at": "2026-05-18T10:00:03Z"
    }
  ],
  "links": { "next": "/api/v1/notifications?recipient=%2B79991234567&cursor=..." },
  "meta": { "per_page": 20 }
}
```

`recipient_masked` — результат `Recipient::masked()` (R9): даёт клиенту видимость, на какой контакт ушло уведомление, без полной утечки PII. Полный контакт в ответе не возвращается (если клиенту нужен оригинал — он уже передал его в query-параметре).

`attempts` — число **неудачных** попыток доставки (R10). Для успешно доставленных без retry-ев это поле = 0; при ретрае с одной неудачей перед успехом — 1, и т.д.

### 6.4 Порт `NotificationGateway`

```
interface NotificationGateway
{
    public function supports(Channel $channel): bool;

    /**
     * NotificationId используется как idempotency-key на стороне шлюза.
     * Stub-реализации дедуплицируют по NotificationId через Redis Hash (TTL 24h).
     */
    public function send(Notification $n): GatewayResult;
}
```

Реализации в `Infrastructure/Notification/Gateway/`:
- `StubSmsGateway` — 80% успех, 15% `GatewayUnavailableException`, 5% `GatewayRejectedException` (имитация «несуществующего номера/email» из `test.md`).
- `StubEmailGateway` — те же распределения.
- `CompositeNotificationGateway` — выбирает шлюз по `Channel`.

**Идемпотентность stub-провайдера** (важно для exactly-once N3): перед `send()` проверка Redis Hash `gateway:idempotency:{channel}` по `NotificationId`. Если запись есть — возвращается сохранённый `GatewayResult` без побочных эффектов. На успехе записывается новый результат с TTL 24 ч. Эмулирует поведение реального шлюза, дедуплицирующего по client-side message-id.

**Имитация колбэка `delivered/dropped`:** после успешного `markAsSent` в `DeliverNotificationAction` диспатчится `SimulateDeliveryAckJob` на **database queue** (A4) — `->onConnection('database')->onQueue('default')->delay(now()->addSeconds(rand(1, 3)))`. Аргумент — `NotificationId`. Job вызывает `AcknowledgeDeliveryAction::handle($notificationId, $finalStatus)`: 90% → `delivered`, 10% → `dropped(reason='provider_rejected_late')`. Идемпотентен: повторный запуск на уже `Delivered/Dropped` — no-op.

Почему database queue: встроенный retry-механизм `jobs` + `failed_jobs` (с `retry_after` ≈ 90 сек) гарантирует, что если worker упал между `pop` и `delete`, джоба будет переподнята. Это устраняет потребность в reconciliation-команде для висящих `sent` (см. A4) — Laravel сам берёт на себя at-least-once для этого ack-job'а.

Это закрывает переход `Sent → Delivered/Dropped` без HTTP-вебхука и без эндпоинта `/providers/{p}/webhook` (A3).

---

## 7. Схема БД (PostgreSQL)

```sql
-- notifications: индивидуальные сообщения
CREATE TABLE notifications (
  id                  UUID PRIMARY KEY,
  recipient           VARCHAR(255) NOT NULL,    -- phone или email — он же идентификатор подписчика (A1)
  channel             VARCHAR(16)  NOT NULL,
  priority            VARCHAR(16)  NOT NULL,
  body                TEXT         NOT NULL,
  status              VARCHAR(16)  NOT NULL,
  status_history      JSONB        NOT NULL DEFAULT '[]',
  attempts            INTEGER      NOT NULL DEFAULT 0,    -- только НЕУДАЧНЫЕ попытки (R10)
  last_error          TEXT,
  provider_message_id VARCHAR(128),
  trace_id            VARCHAR(64),              -- бонус (R6, R20): nullable, хранится здесь, не в outbox
  version             INTEGER      NOT NULL DEFAULT 0,
  created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CONSTRAINT notifications_status_chk
    CHECK (status IN ('queued','sent','delivered','dropped')),
  CONSTRAINT notifications_channel_chk
    CHECK (channel IN ('sms','email')),
  CONSTRAINT notifications_priority_chk
    CHECK (priority IN ('transactional','marketing'))
);

-- F3: основной запрос «история подписчика» — по recipient
CREATE INDEX notifications_recipient_created_idx
  ON notifications (recipient, created_at DESC);

-- Узкий «горячий» подзапрос для воркеров / диагностики
CREATE INDEX notifications_status_queued_idx
  ON notifications (status) WHERE status = 'queued';


-- outbox_messages: транзакционный outbox (N1)
-- trace_id здесь НЕ хранится (R20): publisher JOIN'ит notifications для получения trace_id.
CREATE TABLE outbox_messages (
  id              UUID PRIMARY KEY,
  notification_id UUID         NOT NULL REFERENCES notifications(id),
  priority        VARCHAR(16)  NOT NULL,        -- определяет routing_key в RabbitMQ
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
  published_at    TIMESTAMPTZ,
  attempts        INTEGER      NOT NULL DEFAULT 0,
  last_error      TEXT
);

-- Поиск неопубликованных через partial index — дешевле full index
CREATE INDEX outbox_unpublished_idx
  ON outbox_messages (created_at) WHERE published_at IS NULL;

-- Cleanup-команде `outbox:purge` нужен range scan по опубликованным:
CREATE INDEX outbox_published_at_idx
  ON outbox_messages (published_at) WHERE published_at IS NOT NULL;
```

**Database queue (A4):** таблицы `jobs` и `failed_jobs` создаются стандартной миграцией `php artisan queue:table && php artisan queue:failed-table` — отдельных кастомных миграций не нужно. `SimulateDeliveryAckJob` пишется в `jobs`.

**Намеренно отсутствует:** таблица `idempotency_keys` (Redis — единственный стор, см. §3.3), таблица `dispatch_requests` (нет агрегата dispatch), отдельная таблица `subscribers` и поле `subscriber_id` (см. A1), индекс на `provider_message_id` (lookup по нему не используется — `SimulateDeliveryAckJob` принимает `NotificationId` напрямую, см. §6.4).

---

## 8. RabbitMQ-топология

```
exchange: notifications.direct (type=direct, durable=true)
│
├── routing_key="transactional" → queue: notifications.transactional
│                                  (x-dead-letter-exchange=notifications.retry,
│                                   x-dead-letter-routing-key=transactional)
│
└── routing_key="marketing"     → queue: notifications.marketing
                                  (x-dead-letter-exchange=notifications.retry,
                                   x-dead-letter-routing-key=marketing)

exchange: notifications.retry (type=direct, durable=true)
│
├── routing_key="transactional" → queue: notifications.transactional.retry
│                                  (x-message-ttl на сообщении, x-dead-letter-exchange=notifications.direct)
│
└── routing_key="marketing"     → queue: notifications.marketing.retry
                                  (per-message `expiration` подбирается по x-retries: 1s,5s,25s,125s — 4 интервала между 5 попытками)

exchange: notifications.dlq.direct  (type=direct, durable=true)
│
└── routing_key="*"            → queue: notifications.dlq (на анализ)

# NB: notifications.dlq.direct — не DLX в смысле RabbitMQ x-dead-letter-exchange.
# Это target для explicit basic_publish из consumer'а после исчерпания 5 попыток
# (см. Фазу 6, шаг 6). DLX-механизм используется только между основным exchange
# и retry-очередями.
```

Декларация в `RabbitMqTopology` — idempotent declare при старте воркеров.

**Воркеры (см. также §10 Фаза 0):**
- `worker-transactional` (`processes: 4`) — `php artisan rabbitmq:consume notifications.transactional`
- `worker-marketing`     (`processes: 2`) — `php artisan rabbitmq:consume notifications.marketing`
- `worker-outbox`        (`processes: 2`) — `php artisan outbox:publish --loop`
- `worker-default`       (`processes: 1`) — `php artisan queue:work database --queue=default --tries=3` (`SimulateDeliveryAckJob`, A4)

---

## 9. End-to-end сценарий

```
1. Client → POST /api/v1/notifications  (Idempotency-Key: <key>)

2. IdempotencyMiddleware:
   ├── ключ в Redis + hash совпадает  → return cached 202
   ├── ключ в Redis + hash не совпал  → 409 Conflict
   └── ключа нет                       → продолжить

3. DispatchNotificationsRequest валидирует:
     channel, priority, body длиной по `body_max.{channel}` (sms=1000 / email=10000),
     recipients[] (1..5000) — массив строк-контактов; каждый элемент → Recipient::fromString($channel, $contact).
     Любая ошибка → 422 (all-or-nothing).

4. Controller строит DispatchNotificationsData (DTO).

5. DispatchNotificationsAction::handle($data):
   // $data->traceId уже гарантированно задан Middleware (бонус, см. §3.4, §10 Фаза 5)
   DB::transaction:
     ├── для каждого Recipient: Notification::create($recipient, $channel, $priority, $body, $traceId) (статус Queued)
     ├── NotificationRepository::saveMany($notifications)
     │     (bulk insert чанками по NOTIFICATIONS_INSERT_CHUNK=2000)
     └── OutboxRepository::appendMany($outboxEntries)
           (по одной OutboxEntry { notification_id, priority } на каждое уведомление;
            реализация чанкует по 2000)
   DB::afterCommit:
     └── dispatch NotificationQueued events (для логов)

6. Controller возвращает 202 + DispatchAcceptedResource:
     { data: { accepted, notification_ids[] } }

7. IdempotencyMiddleware сохраняет ответ в Redis с TTL 24h.

8. worker-outbox (continuous loop):
   SELECT o.id, o.notification_id, o.priority, n.trace_id
     FROM outbox_messages o
     JOIN notifications n ON n.id = o.notification_id        -- trace_id живёт в notifications (R20)
     WHERE o.published_at IS NULL
     ORDER BY o.created_at LIMIT 100 FOR UPDATE SKIP LOCKED
   для каждой строки:
     ├── basic_publish(notifications.direct, routing_key=priority,
     │                  body={"notification_id": "..."},
     │                  headers={"x-retries": 0, "x-trace-id": trace_id?},   // x-trace-id опционален
     │                  persistent=true)
     └── UPDATE outbox_messages SET published_at = now() WHERE id = ?
   COMMIT
   (если outbox пуст — sleep 500ms)

9. worker-transactional / worker-marketing получает AMQP-сообщение:
   ConsumeNotificationJob::handle($notificationId, $xRetries, $xTraceId):
     Log::withContext(['trace_id' => $xTraceId, 'notification_id' => $notificationId])
     DeliverNotificationAction::handle(DeliverNotificationData):
       ├── $n = repo->findById($notificationId)
       ├── if status !== Queued → ACK + return  (exactly-once N3)
       ├── try:
       │     $result = gateway->send($n)          // stub дедуплицирует по NotificationId
       │     $n->markAsSent($result->messageId)    // attempts НЕ инкрементируется на успехе (R10)
       │     repo->save($n)                        // одиночный UPDATE с optimistic-lock
       │     ACK
       │     dispatch SimulateDeliveryAckJob::delay(rand(1,3)s) onConnection('database')
       │   catch GatewayUnavailable | GatewayTimeout:
       │     $n->recordFailedAttempt($e->getMessage())   // ++attempts, lastError
       │     if x-retries+1 >= NOTIFICATIONS_MAX_ATTEMPTS:
       │       $n->markAsDropped('max_retries_exceeded') // state-transition в той же in-memory мутации
       │       repo->save($n)                             // одиночный UPDATE с optimistic-lock
       │       publish dlq; ACK
       │     else:
       │       repo->save($n)
       │       publish notifications.retry с expiration=backoff[x-retries] и headers.x-retries++
       │       ACK (оригинальное сообщение)
       │   catch GatewayRejected (permanent):
       │     $n->recordFailedAttempt($e->getMessage())   // ++attempts, lastError
       │     $n->markAsDropped('provider_rejected: '.$e->getMessage())
       │     repo->save($n); ACK                          // одиночный UPDATE

10. SimulateDeliveryAckJob (queue:database `default`, delay 1..3s, --tries=3, A4):
    AcknowledgeDeliveryAction::handle(NotificationId $id, NotificationStatus $finalStatus, ?string $reason = null):
      ├── $n = repo->findById($id)
      ├── if status in (Delivered, Dropped) → no-op  (идемпотент — закрывает retry от database-queue)
      ├── 90% → finalStatus=Delivered, reason=null   → $n->markAsDelivered()
      └── 10% → finalStatus=Dropped,   reason='provider_rejected_late' → $n->markAsDropped($reason)
      repo->save($n)
```

---

## 10. Пошаговый план разработки

Каждая фаза: **что делаем → критерий приёмки**.

### Фаза 0. Bootstrap инфраструктуры (N7)

1. Создать `compose.yaml` (R16; современная рекомендация Docker, понимается обеими версиями CLI) со службами: `app` (PHP-FPM), `nginx`, `postgres`, `rabbitmq` (с management UI), `redis`, `worker-transactional`, `worker-marketing`, `worker-outbox`, `worker-default`, `migrate` (one-shot).
2. **Healthchecks** обязательны для всех долгоживущих сервисов:
   - `postgres`: `pg_isready -U $POSTGRES_USER`
   - `rabbitmq`: `rabbitmq-diagnostics -q ping`
   - `redis`: `redis-cli ping`
   - `app` (PHP-FPM, R17): `cgi-fcgi -bind -connect 127.0.0.1:9000` (через `php-fpm-healthcheck` или эквивалент)
   - `worker-*` (R17): `pgrep -f 'rabbitmq:consume|outbox:publish|queue:work' >/dev/null` — лёгкая проверка, что процесс жив; для production стоит расширить до Liveness через файл-маркер, но для теста достаточно.
3. **Зависимости через `condition: service_healthy`** для `app`, всех `worker-*`, и `migrate` — это гарантирует, что первый холодный `docker compose up` отработает без гонок.
4. Контейнер `migrate` (one-shot, `restart: "no"`): `php artisan migrate --force` (включает миграции `jobs`, `failed_jobs` для database queue, A4). От него зависят все `worker-*` и `app` через `depends_on: { condition: service_completed_successfully }`.
5. **Graceful shutdown:** воркеры запускаются под `tini` (`init: true` в compose), обрабатывают `SIGTERM` — текущая задача завершается, AMQP-сообщение ACK’ается перед остановкой.
6. **Resource isolation:** явные `cpus` / `mem_limit` для `worker-marketing` ниже, чем для `worker-transactional` — дополнительный bulkhead на уровне OS (поверх раздельных очередей).
7. Volumes: `pg_data`, `rabbit_data`, `redis_data`.
8. `Dockerfile` для PHP 8.4-fpm: расширения `pdo_pgsql`, `bcmath`, `intl`, `redis`, `sockets`, `pcntl`. Добавить пакет `php-fpm-healthcheck` (composer-binary) либо собственный health-script (R17).
9. `compose.override.yaml` для dev: bind-mount `./src` в контейнер.
10. Установить Laravel 13: `composer create-project laravel/laravel src`.
11. `composer require php-amqplib/php-amqplib vladimir-yuldashev/laravel-queue-rabbitmq ramsey/uuid`.
12. `composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan`.
13. Удалить `laravel/sanctum`, если установлен по умолчанию.
14. Заполнить `.env` / `.env.example`:
    - `DB_*` (PG), `REDIS_*` (maxmemory-policy: allkeys-lru), `RABBITMQ_*`
    - `QUEUE_CONNECTION=rabbitmq` (для уведомлений) + `database` connection для `SimulateDeliveryAckJob` (A4)
    - `NOTIFICATIONS_BATCH_MAX=5000`, `NOTIFICATIONS_INSERT_CHUNK=2000` (R14)
    - `NOTIFICATIONS_RETRY_BACKOFF_MS=1000,5000,25000,125000`  # 4 интервала между 5 попытками
    - `NOTIFICATIONS_MAX_ATTEMPTS=5`
    - `NOTIFICATIONS_BODY_MAX_SMS=1000`, `NOTIFICATIONS_BODY_MAX_EMAIL=10000`
15. Создать `src/config/notifications.php`.

**Приёмка:** `docker compose up` (с холодным состоянием) поднимает стек одной командой; `migrate` отрабатывает успешно; все `worker-*` стартуют после healthcheck RabbitMQ/Postgres/PHP-FPM; RabbitMQ UI на `:15672` отвечает; `docker compose ps` показывает `healthy` для всех долгоживущих сервисов (включая PHP-FPM и воркеров).

### Фаза 1. Domain layer

1. VO: `NotificationId`, `Channel`, `Priority`, `NotificationStatus`, `Recipient` (с `masked()`), `MessageBody`, `ProviderMessageId`, `StatusHistory`. (`SubscriberId` — нет, см. R1/A1.)
2. Entity `Notification`: `::create($recipient, $channel, $priority, $body, ?$traceId)`, `::reconstitute()`.
3. State-машина: `markAsSent` (успех — `attempts` НЕ инкрементируется, R10), `markAsDelivered`, `markAsDropped`, `recordFailedAttempt($error)` (только на неудаче). Каждый переход кидает `InvalidNotificationStatusTransitionException` при недопустимом.
4. Domain events.
5. Repository / Gateway-интерфейсы.
6. Domain-исключения (включая Gateway*Exception).

**Приёмка:** Unit-тесты `Domain/Notification/` зелёные без бутстрапа Laravel. `phpstan analyze src/app/Domain` — clean.

### Фаза 2. Persistence

1. Миграции двух таблиц (см. §7) со всеми индексами и check-констрейнтами.
2. Eloquent-модели: `NotificationModel`, `OutboxMessageModel`. Чистые (`$casts`, `$fillable`, без бизнес-логики).
3. `NotificationMapper`: Eloquent ↔ Domain (включая JSON `status_history`).
4. `EloquentNotificationRepository`:
   - `save()` — UPDATE с `WHERE version = ?` и инкрементом; на `rows = 0` → `ConcurrencyException`.
   - `saveMany()` — bulk insert чанками по `NOTIFICATIONS_INSERT_CHUNK = 2000` (R14).
   - `findById()`.
5. `NotificationServiceProvider` биндит интерфейсы → реализации.

**Приёмка:** Integration-тесты round-trip (Domain → save → findById → equals). Тест конфликта `version` → `ConcurrencyException`.

### Фаза 3. Application — Write

1. `DispatchNotificationsData` (`readonly`, фабрика `::from()` из массива). Поля: `channel: Channel`, `priority: Priority`, `body: MessageBody`, `recipients: Recipient[]`, `traceId: ?string` (бонус, R6/A1: nullable; из заголовка `X-Trace-Id` или сгенерирован Middleware).
2. `OutboxEntry` (DTO) и `OutboxRepository` (interface) — см. §4.5. **`DispatchNotificationsAction` не импортирует Eloquent**, пишет в outbox только через порт `OutboxRepository`.
3. `DispatchNotificationsAction::handle($data)`:
   - `DB::transaction`:
     - для каждого `Recipient` собирается `Notification::create($recipient, $data->channel, $data->priority, $data->body, $data->traceId)` (валидация инвариантов в конструкторе);
     - `NotificationRepository::saveMany($notifications)` — чанками по `NOTIFICATIONS_INSERT_CHUNK = 2000` (R14);
     - сборка N `OutboxEntry` (`notificationId`, `priority`);
     - `OutboxRepository::appendMany($entries)` — реализация чанкует по 2000.
   - `DB::afterCommit`: dispatch `NotificationQueued` events.
   - Вернуть `DispatchAcceptedResult` (DTO).
   - Возвращаемая структура: `DispatchAcceptedResult { accepted: int, notificationIds: NotificationId[] }`. Файл `Application/Notification/UseCase/DispatchNotifications/DispatchAcceptedResult.php` создаётся именно в этой фазе (не отложен).
4. `DeliverNotificationAction::handle($data)`:
   - Загрузить `Notification`. Если нет / уже `Sent | Delivered | Dropped` → `NoOpResult` (exactly-once).
   - Вызов `NotificationGateway::send()`.
   - На успех — `markAsSent($pid)` + один `repo->save` (optimistic lock). `attempts` НЕ инкрементируется на успехе (R10).
   - На transient — `recordFailedAttempt($e->getMessage())`, `save`, бросить `DeliverNotificationFailedException` (consumer её ловит, решает дропать ли по `x-retries`, см. §9 шаг 9).
   - На permanent — `recordFailedAttempt($e->getMessage())` + `markAsDropped('provider_rejected: '.$e->getMessage())` подряд (две in-memory мутации), затем один `repo->save($n)`. Это даёт `attempts = 1` и заполненный `last_error`, что и ассертится тестом 6 в Фазе 8.

   **Правило одного `save` на путь.** Каждый catch-блок мутирует aggregate в памяти полностью, затем делает **один** `repo->save($n)`. Это исключает гонку с optimistic-lock между двумя последовательными `save` в одном handle’е.
5. `AcknowledgeDeliveryAction::handle(NotificationId $id, NotificationStatus $finalStatus, ?string $reason = null)`:
   - Загрузить `Notification`. Если уже `Delivered | Dropped` → no-op (идемпотент).
   - `markAsDelivered` или `markAsDropped($reason)`.
   - `save`.

**Приёмка:** Unit-тесты Application с in-memory `NotificationRepository`. Тест на дубль вызова `DeliverNotificationAction` — `gateway->send()` зовётся **ровно один раз**.

### Фаза 4. Application — Read (F3)

1. `NotificationReadRepository` (интерфейс): `findByRecipient(Recipient $recipient, ?string $cursor, int $perPage): array`.
2. `EloquentNotificationReadRepository` с курсорной пагинацией и явным `select()` колонок (включая `recipient` для последующего `masked()` в DTO; R9).
3. `NotificationView` (DTO для API) — содержит `id, channel, priority, status, recipient_masked, attempts, last_error, status_history, created_at, updated_at`. Поле `recipient_masked` — результат `Recipient::masked()` (R9). Сырой `recipient` в DTO не пробрасывается дальше read-слоя.
4. `GetNotificationsByRecipientQuery` + `GetNotificationsByRecipientHandler`. Аргумент query — `Recipient`, не строка: парсинг и валидация `recipient` происходят в FormRequest UI-слоя.

**Приёмка:** Integration-тест возвращает список с пагинацией по recipient. (Тест `EXPLAIN ANALYZE` удалён, R13 — индекс `notifications_recipient_created_idx` определён в миграции; корректность плана проверяется на интеграционных нагрузочных прогонах, не в unit-suite.)

### Фаза 5. UI — HTTP

1. `IdempotencyStore` (интерфейс в Application) + `RedisIdempotencyStore` (Infrastructure).
2. `TraceIdMiddleware` *(бонус, R6 — observability сверх `test.md`)* (применяется ко всему `/api/v1/*` группой в `routes/api.php`): читает `X-Trace-Id` из request, при отсутствии генерирует UUIDv7, кладёт в `request->attributes` и в `Log::withContext()`. `DispatchNotificationsData::$traceId` всегда задан Middleware.
3. `IdempotencyMiddleware` (применяется только к `POST /notifications` в `routes/api.php`):
   - нет `Idempotency-Key` → 422 (`idempotency_key_required`);
   - ключ есть + hash совпал → cached response;
   - ключ есть + hash не совпал → 409 (`idempotency_key_conflict`);
   - ключа нет → выполнить запрос, на 2xx сохранить с TTL 24h.
4. `DispatchNotificationsRequest`:
   - `rules()`:
     - `channel|in:sms,email`,
     - `priority|in:transactional,marketing`,
     - `body|string|min:1` + channel-зависимый `max` через `withValidator` (`max:config('notifications.body_max.'.$channel)`),
     - `recipients|array|min:1|max:config('notifications.batch_max')`,
     - `recipients.*|string|min:1` (массив строк, не объектов — R1).
   - в `withValidator()` для каждого элемента: `Recipient::fromString($channel, $row)` → `InvalidRecipientException` (422), `MessageBody::for($channel, $body)` → `InvalidMessageBodyException` (422). Все ошибки агрегируются — клиент видит сразу все нарушения.
   - `authorize(): true` (аутентификации нет, см. §2).
5. `GetNotificationsByRecipientRequest`:
   - `rules()`: `recipient|required|string`, `cursor|nullable|string`, `per_page|integer|min:1|max:100`.
   - В `withValidator()` — `Recipient::fromAny($recipient)` (определение канала по формату: `+\d` → SMS, иначе email через `filter_var`); ошибка → 422 `invalid_recipient`.
6. Invokable controllers:
   - `DispatchNotificationsController` — строит `DispatchNotificationsData` (подставляет `traceId` из `request->attributes->get('trace_id')`, бонус). Устанавливает заголовок `X-Trace-Id` в ответе (бонус).
   - `GetNotificationsByRecipientController` — также возвращает `X-Trace-Id` (бонус).
7. `DispatchAcceptedResource`, `NotificationResource` (с `recipient_masked`, R9), `NotificationCollection`.
8. Маршруты в `routes/api.php`. Группа `/api/v1` + `TraceIdMiddleware` (бонус). `throttle:60,1` на write-эндпоинт, `throttle:120,1` на read (защита от случайного DDoS клиентом — стандартная практика Laravel).
9. Маппинг доменных исключений на HTTP в `src/bootstrap/app.php`.
10. **Nginx access-log: исключить `args`** для пути `/api/v1/notifications` — query содержит `recipient` (PII, A1). Кастомный `log_format` без `$args`/`$query_string`.

**Приёмка:** Feature-тесты (см. полный список в Фазе 8).

### Фаза 6. Messaging

1. `RabbitMqTopology::declare()` — все exchanges, queues, bindings, retry-/dlx-структуры (idempotent declare при старте каждого воркера).
2. `EloquentOutboxRepository implements OutboxRepository` — реализация `appendMany(array $entries)`: bulk insert чанками по 2000 (R14) в `outbox_messages` с полями `id`, `notification_id`, `priority` (trace_id здесь НЕ хранится, R20). Использует `OutboxMessageModel::insert(...)` — но эта зависимость **локализована в Infrastructure**, Application видит только интерфейс.
3. `OutboxPublisher::flush(int $batchSize = 100): int`:
   - SELECT с JOIN'ом для подгрузки `trace_id` из `notifications` (R20):
     ```sql
     SELECT o.id, o.notification_id, o.priority, n.trace_id
       FROM outbox_messages o
       JOIN notifications n ON n.id = o.notification_id
       WHERE o.published_at IS NULL
       ORDER BY o.created_at
       LIMIT 100
       FOR UPDATE SKIP LOCKED;
     ```
   - публикация `{ "notification_id": ... }` с `routing_key = priority`, headers `x-retries=0` и `x-trace-id=<trace_id>` (только если `trace_id IS NOT NULL`; бонус, R6), `persistent=true`;
   - `UPDATE outbox_messages SET published_at = now() WHERE id = ?`;
   - возвращает число опубликованных сообщений.
4. `OutboxPublishCommand` (`outbox:publish --loop`): continuous-цикл `flush()` + sleep 500ms на пустом outbox. Graceful по `SIGTERM`.
5. `OutboxPurgeCommand` (`outbox:purge`): удаляет `published_at IS NOT NULL AND published_at < now() - INTERVAL '7 days'` чанками по 5000 строк. Планируется `Schedule::command('outbox:purge')->dailyAt('03:00')` в `routes/console.php`.
6. `ConsumeNotificationJob` — обёртка над AMQP-сообщением, читает `notification_id` из payload и `x-retries`, `x-trace-id` из headers, ставит `Log::withContext(['trace_id' => ..., 'notification_id' => ...])` (бонус), внутри вызывает `DeliverNotificationAction`. Manual `ack` после успеха. На transient-ошибке — publish в `notifications.retry` с инкрементированным `x-retries`, тем же `x-trace-id` и `expiration` = `retry_backoff_ms[x-retries]`.
7. Воркер-команды:
   - `php artisan rabbitmq:consume notifications.transactional`
   - `php artisan rabbitmq:consume notifications.marketing`
   - `php artisan outbox:publish --loop`
   - `php artisan queue:work database --queue=default --tries=3` (R4/R15: `SimulateDeliveryAckJob` на database queue).

**Приёмка:** integration-тесты на **реальном RabbitMQ** (см. Фазу 8). `arch()`-тест в Фазе 8 запрещает `App\Application` импортировать `Illuminate\Database\Eloquent` — реализация `OutboxRepository` живёт только в Infrastructure.

### Фаза 7. Gateway-заглушки

1. `StubSmsGateway` / `StubEmailGateway`:
   - распределение: 80% success / 15% `GatewayUnavailableException` / 5% `GatewayRejectedException`;
   - **дедупликация** в Redis Hash `gateway:idempotency:{channel}` по `NotificationId` с TTL 24 ч (см. §6.4).
2. `CompositeNotificationGateway` — диспетчер по `Channel`.
3. `SimulateDeliveryAckJob` (R4: **queue:database**, `default`): диспатчится с `delay(rand(1, 3) sec)` сразу после `markAsSent`. Принимает `NotificationId` (не `ProviderMessageId` — это убирает потребность в дополнительном индексе). 90% → `AcknowledgeDeliveryAction` со статусом `delivered`, 10% → `dropped(reason='provider_rejected_late')`. `--tries=3` гарантирует, что если worker упал между `pop` и `delete`, джоба будет переподнята — это закрывает потребность в reconciliation-команде.

**Приёмка:**
- Тест идемпотентности stub’а: двойной `send()` с одним `NotificationId` → один `ProviderMessageId`, в логах stub-вызов засчитан один раз.
- Тест: `markAsSent` → через ≤ 4 сек `SimulateDeliveryAckJob` переводит уведомление в `delivered` или `dropped`.
- Тест ретрая database queue: имитировать падение worker’а в момент обработки `SimulateDeliveryAckJob` — джоба должна быть переподнята и в итоге выполнена. Action идемпотентен, поэтому при повторе уже-`Delivered/Dropped` уведомления — no-op.

### Фаза 8. Тесты (N6)

Структура `src/tests/`:

- **Unit/Domain/Notification/** — entity, VO, state-машина, доменные исключения.
- **Unit/Application/Notification/** — Actions с in-memory репозиториями и in-memory gateway’ем.
- **Integration/Infrastructure/Notification/** — репозитории, mapper round-trip, optimistic lock, outbox publisher, consumer e2e. **На реальном RabbitMQ + Postgres + Redis** (в том же `compose.yaml`, тесты исполняются командой `docker compose exec app php artisan test`).

  **Изоляция (простейший путь, R4):** базовый класс `tests/Integration/RabbitMqIntegrationTestCase.php` в `setUp()`:
  1. `RabbitMqTopology::declare()` — idempotent объявление всех exchanges/queues;
  2. `queue.purge` всех `notifications.*` очередей (`notifications.transactional`, `notifications.marketing`, обе retry-очереди, dlq).
  3. `RefreshDatabase` (через trait) — Postgres откатывается транзакцией.
  4. `Redis::connection()->flushdb()` на отдельном dedicated DB `REDIS_DB_TEST = 15` (выставляется в `phpunit.xml`).

  Этого достаточно для последовательного `php artisan test`. Параллельный запуск (`--parallel`) планом не предусмотрен; при необходимости решается через `RABBITMQ_VHOST=notifications_test_${TEST_TOKEN}` без переделок.
- **Feature/Notification/** — HTTP-эндпоинты + идемпотентность.
- **Architecture/LayersTest.php** — Pest `arch()` (объединённый набор):
  ```
  arch('domain is framework-free')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'Symfony', 'Eloquent', 'Carbon']);
  arch('application has no http')
    ->expect('App\Application')
    ->not->toUse(['Illuminate\Http']);
  arch('application has no eloquent / db facade')   // R1
    ->expect('App\Application')
    ->not->toUse(['Illuminate\Database\Eloquent', 'Illuminate\Support\Facades\DB']);
  arch('outbox repository is a port (interface)')   // R1
    ->expect('App\Application\Notification\Outbox\OutboxRepository')
    ->toBeInterface();
  arch('controllers are invokable')
    ->expect('App\Interface\Http')
    ->classes()->toHaveMethod('__invoke');
  ```

**Конкретные обязательные тестовые сценарии:**

1. **Главный e2e (N6, требование задания):** Feature-тест поднимает реальный стек (Postgres + RabbitMQ + Redis в `compose.yaml`). Шлёт `POST /api/v1/notifications` с массивом строк-контактов и `Idempotency-Key`. Затем:
   1. Запускает `OutboxPublisher::flush()` синхронно → ассертит `outbox_messages.published_at IS NOT NULL`.
   2. **Считывает сообщение из реальной очереди:** через `php-amqplib` напрямую — `$channel->basic_get('notifications.transactional', no_ack: false)` — получает `AMQPMessage`. Ассертит, что `body` декодируется в `{ "notification_id": "<uuid>" }`, в headers есть `x-retries=0` (`x-trace-id` ассертится опционально, как бонус). Это закрывает дословное требование `test.md` «от **получения сообщения из очереди** до корректного изменения статуса в базе данных».
   3. Передаёт прочитанный payload в `ConsumeNotificationJob::handle($payload, $headers)` синхронно → внутри отрабатывает `DeliverNotificationAction` против `StubGateway`. После успеха тест делает `$channel->basic_ack($msg->getDeliveryTag())`.
   4. Ассертит: `notifications.status = 'sent'`, `provider_message_id IS NOT NULL`, `notifications.recipient` совпадает с запросом, очередь `notifications.transactional` пуста.
   5. Запускает `SimulateDeliveryAckJob::handle()` синхронно → ассертит `status IN ('delivered', 'dropped')`, `status_history` содержит все переходы в правильном порядке.

2. **Тест приоритезации (F2, bulkhead через раздельные пулы):** создаются 50 marketing + 5 transactional через `DispatchNotificationsAction`. `OutboxPublisher::flush()` синхронно публикует все 55 в RabbitMQ. Запускается **только** `worker-transactional` (через `php artisan rabbitmq:consume notifications.transactional --max-messages=5 --stop-when-empty`); `worker-marketing` **не запускается**. Ассертит:
   - все 5 транзакционных → `status = 'sent'`;
   - все 50 маркетинговых → `status = 'queued'` (физически не двигаются без своего воркера).

   Этот тест проверяет ровно то, что отвечает требованию F2 в нашей реализации: приоритезация = bulkhead через раздельные очереди + раздельные пулы воркеров. Если бы приоритезация была сломана (например, обе очереди слушал один общий пул), маркетинг бы тоже сдвинулся.

   Дополнительная проверка («mixed»): отдельным тестом запускается одновременно оба воркера на 60 марк. + 5 транз., ассертит `avg(updated_at - created_at) for transactional < avg(...) for marketing * 0.5` — мягкое подтверждение в реалистичном сценарии.

3. **Идемпотентность (N5):**
   - повтор с тем же `Idempotency-Key` и body → тот же response, **нет** новых строк в БД;
   - повтор с тем же `Idempotency-Key` и другим body → 409;
   - запрос без `Idempotency-Key` → 422.

4. **Retry transient (N4):** инжектируется stub-gateway, всегда бросающий `GatewayUnavailableException` 3 раза, затем success. Ассертит: `attempts = 3` (R10: только неудачи; успешная попытка `attempts` НЕ инкрементирует), итоговый `status = 'sent'`, провайдер вызван 4 раза. Тест работает с принудительными короткими TTL (overrides конфига).

5. **Retry exhausted (N4):** stub всегда бросает `GatewayUnavailableException('gateway down')`. Ассертит:
   - после 5 неудач `status = 'dropped'`, `attempts = 5`;
   - `status_history[last].reason = 'max_retries_exceeded'` (причина перехода state-машины);
   - `last_error LIKE '%gateway down%'` (raw-сообщение последнего gateway-исключения, **не** `'max_retries_exceeded'` — см. §3.5);
   - сообщение опубликовано в `notifications.dlq`.

6. **Permanent reject (F4, dropped «несуществующий номер/email»):** stub бросает `GatewayRejectedException('unknown number')`. Ассертит:
   - `status = 'dropped'`, `attempts = 1`, retry **не запускался**;
   - `status_history[last].reason LIKE 'provider_rejected: %'`;
   - `last_error LIKE '%unknown number%'`;
   - сообщение НЕ в DLQ.

7. **Exactly-once (бонус N3):** один и тот же AMQP-message подаётся в consumer дважды. Ассертит: `gateway->send()` вызван **ровно один раз**, `version = 1`.

8. **Read API (F3):** создаются 30 уведомлений для одного контакта `+79991234567` через `Notification::create(...)`. Запрашивается `GET /api/v1/notifications?recipient=%2B79991234567&per_page=10`. Ассертит: 3 страницы по 10, корректный курсор, явный `select` колонок (нет утечки `*`), в ответе поле `recipient_masked = '+7***4567'` (R9), сырого `recipient` нет.

9. **Валидация:**
   - `recipients = []` → 422 `empty_recipients`;
   - элемент с битым контактом → 422 `invalid_recipient` (all-or-nothing, других не сохранено);
   - SMS-`body` длиной 1500 символов → 422 `invalid_message_body` (лимит 1000); email-`body` длиной 1500 → 200 OK (лимит 10000);
   - `recipients.length > 5000` → 422 `batch_too_large`;
   - `channel = 'fax'` → 422 `unknown_channel`;
   - `GET /api/v1/notifications` без `recipient` → 422 `recipient_required`.

10. **Database-queue retry для `SimulateDeliveryAckJob` (R4, A4):** имитируется падение worker'а на этом джобе — `$this->fail(new RuntimeException('worker crash'))` с `$tries=3`. Ассертит: после двух fail-retries 3-я попытка отрабатывает успешно, итоговый `status IN ('delivered', 'dropped')`. Action идемпотентен — при повторе уже-`Delivered` уведомления `version` не растёт.

11. **Trace-ID propagation (бонус, R6):** `POST /api/v1/notifications` с заголовком `X-Trace-Id: trace-abc-123`. После прохождения через outbox → AMQP → consumer ассертит, что в `notifications.trace_id = 'trace-abc-123'` (R20: trace_id хранится в notifications, не в outbox) и в собранных тестовым log-handler’ом записях потребителя есть `trace_id = 'trace-abc-123'`.

12. **Восстановление Outbox после падения между commit БД и publish (N1, центральный сценарий §3.4):** имитируется ситуация, когда транзакция в БД закоммитилась, но процесс упал до публикации в RabbitMQ. Тест:
    1. Вручную (минуя HTTP-эндпоинт) вставляет одну строку в `notifications` (`status='queued'`, `trace_id='trace-xyz'`) и одну в `outbox_messages` (`published_at=NULL`, `notification_id` совпадает).
    2. Очищает очередь `notifications.transactional` через `$channel->queue_purge()` и ассертит, что она пуста (`message_count = 0`).
    3. Запускает `OutboxPublisher::flush()` синхронно.
    4. Ассертит: `outbox_messages.published_at IS NOT NULL`, `$channel->basic_get('notifications.transactional')` возвращает сообщение с правильным `notification_id` в payload и `x-trace-id = 'trace-xyz'` в headers (получено из JOIN'а с notifications, R20).
    5. Повторный вызов `flush()` без новых записей → `published_count = 0`, очередь всё так же содержит ровно одно сообщение (нет дублирующей публикации).

    Этот тест закрывает гарантию N1: «персистентность очереди» + dual-write через outbox реально работает после crash recovery.

**Приёмка фазы:** `php artisan test` зелёный, coverage Domain ≈ 100%, PHPStan L8 clean. Architecture-тесты (определены выше единым блоком) гарантируют, что `DispatchNotificationsAction` не получит в будущем прямую зависимость от Eloquent.

### Фаза 9. Документация и сдача (S1–S3)

1. Создать `src/storage/api-docs/openapi.yaml` (OpenAPI 3.0) — все эндпоинты, схемы запроса/ответа, примеры. **Обязательные пункты:**
   - `Idempotency-Key` объявлен `in: header, required: true, schema: { type: string, maxLength: 64 }` для `POST /api/v1/notifications` с примером и описанием контракта (cached на повтор с тем же hash, 409 на коллизию hash, 422 без ключа);
   - `X-Trace-Id` объявлен **optional** header *(бонус, наблюдаемость сверх `test.md`, R6)* с пояснением, что при отсутствии сервер генерирует UUID;
   - явно описаны допущения A1 (recipient = идентификатор подписчика; нет отдельной opaque-сущности; query-параметр для F3), A4 (database queue для ack-job обеспечивает at-least-once без reconciliation), A5 (body limit: sms 1000 / email 10000) с обоснованием — для SMS лимит маленький, чтобы избежать неожиданного segment-биллинга на стороне клиента;
   - **All-or-nothing валидация батча** — отдельный параграф в описании `POST /api/v1/notifications`: «любой невалидный элемент → 422 на весь запрос; никакие частичные уведомления не создаются; partial accept зарезервирован под v2»;
   - **Семантика поля `attempts`** (R10) — отдельный параграф в schema `NotificationView`: «`attempts` считает **только неудачные** попытки доставки; успешная попытка `attempts` не увеличивает; `attempts = 0` для уведомлений, отправленных с первой попытки»;
   - **Размер ответа на батч** — в описании `202 Accepted` отметить: «при `batch=5000` тело ответа достигает ≈200 KB (массив `notification_ids`); клиент должен поддерживать чтение ответов такого размера»;
   - **F3 query-параметр `recipient`** (A1) — пояснить, что контакт обязателен, передаётся URL-encoded, и в access-логах nginx маскируется/исключается;
   - **`recipient_masked` в ответе F3** (R9) — пояснить формат маскирования и почему сырой контакт не возвращается;
   - перечислены все коды ошибок из §6.1 со схемой error-response.
2. Smoke-страница Swagger UI на `/api/docs`: один Blade-шаблон, подгружающий swagger-ui-dist через CDN и читающий `openapi.yaml`. Без PHP-пакета.
3. *(Postman-коллекция намеренно не делается, R8/R21 — `test.md` требует **либо** Swagger **либо** Postman; Swagger UI закрывает требование S3.)*
4. `README.md`:
   - Быстрый старт: `docker compose up -d` (R16) → миграции запускаются автоматически контейнером `migrate`.
   - Архитектура: короткое описание + ссылка на этот файл.
   - Примеры `curl` для `POST /notifications` (**обязательно с `-H 'Idempotency-Key: <uuid>'`**, в примере явно подписано «без этого заголовка → 422 `idempotency_key_required`») и `GET /api/v1/notifications?recipient=%2B79991234567`. В примере `POST` тело содержит `recipients: ["+79991234567", ...]` (массив строк, R1).
   - Раздел «Допущения»: A1 (recipient = идентификатор подписчика; реестр подписчиков сервис не ведёт), A2 (Idempotency-Key обязателен — отдельный абзац с обоснованием), A3 (только stub-провайдеры), A4 (database queue для `SimulateDeliveryAckJob` обеспечивает at-least-once без reconciliation-команды), A5 (body 1..1000 для SMS / 1..10000 для email; обоснование SMS-сегментов).
   - **Раздел «Поведенческие контракты»**:
     - all-or-nothing валидация батча (любая ошибка → 422 на весь запрос);
     - `attempts` считает **только неудачи** (R10);
     - размер 202-ответа при `batch=5000` ≈ 200 KB;
     - **`NOTIFICATIONS_BATCH_MAX = 5000`** — мягкий лимит (R18), настраивается через `.env`. Снизить до 500–1000, если клиенту удобнее много мелких запросов; повысить до 10000 при больших разовых рассылках — индекс `notifications_recipient_created_idx` и чанкование INSERT'а это выдерживают. Снижение лимита может потребовать пересмотра `NOTIFICATIONS_INSERT_CHUNK` (по умолчанию 2000, R14).
   - **Раздел «Приоритезация»** (R3): пояснить, что F2 реализована как **bulkhead** через раздельные очереди RabbitMQ + раздельные пулы воркеров с разными `cpus/mem_limit`, а не как `x-max-priority` внутри одной очереди. Это сильнее за счёт OS-изоляции, но требует явного объявления приоритета клиентом в момент `POST`.
   - **Раздел «Наблюдаемость» (бонус, R6):** `X-Trace-Id` propagation через middleware → `notifications.trace_id` → AMQP headers → consumer logs. Это бонус сверх `test.md`; при необходимости легко отключается удалением `TraceIdMiddleware` и поля `trace_id`.
   - Команды: `docker compose exec app php artisan test`, RabbitMQ UI на `:15672`, как смотреть логи воркеров, как форсированно дёрнуть `outbox:purge` руками.
5. CI-pipeline (GitHub Actions): build → `phpstan` (L8) → `composer audit` → `php artisan test` с поднятыми Postgres / RabbitMQ / Redis в services.

**Финальная приёмка (mapping на `test.md`):**
- ✅ F1: `POST /api/v1/notifications` принимает SMS/Email рассылку → 202.
- ✅ F2: транзакционные сообщения обрабатываются раньше маркетинговых через bulkhead (тест 2 из Фазы 8).
- ✅ F3: `GET /api/v1/notifications?recipient={contact}` возвращает историю по подписчику.
- ✅ F4: видимы все четыре статуса (`queued | sent | delivered | dropped`) в `status_history`.
- ✅ N1: RabbitMQ durable + outbox.
- ✅ N2: manual ack только после persist в БД.
- ✅ N3 (бонус): state check + optimistic lock — exactly-once на бизнес-уровне.
- ✅ N4: до 5 попыток с exponential backoff через TTL+DLX, потом DLQ + `dropped`.
- ✅ N5: `Idempotency-Key` обязателен, Redis-стор, тесты дубля/конфликта/отсутствия.
- ✅ N6: e2e интеграционный тест на реальном RabbitMQ (тест 1 из Фазы 8).
- ✅ N7: `docker compose up` поднимает весь стек одной командой, healthchecks (включая PHP-FPM и воркеров, R17) гарантируют корректный холодный старт.
- ✅ S1: публичный репозиторий с этим планом и кодом.
- ✅ S2: README с инструкцией.
- ✅ S3: OpenAPI 3.0 + Swagger UI (Postman не делается, R8/R21).

---

## 11. Self-review checklist (перед мержем)

- [ ] Domain не импортирует `Illuminate\*`, `Eloquent`, `Carbon` (mutable).
- [ ] **Application не импортирует `Eloquent` / `DB`-фасады.** `DispatchNotificationsAction` пишет в outbox только через `OutboxRepository`. `arch()`-тест зелёный.
- [ ] Контроллеры тонкие: Request → DTO → Action → Resource.
- [ ] Eloquent-модели — только в `Infrastructure/`.
- [ ] Все state-переходы кидают `InvalidNotificationStatusTransitionException` при нарушении.
- [ ] `NotificationRepository::save()` использует optimistic-lock по `version`; rows=0 → `ConcurrencyException`.
- [ ] `DispatchNotificationsAction` пишет notifications + outbox **в одной транзакции**, через bulk insert чанками по 2000 (R14, и для notifications, и для outbox).
- [ ] Domain-события диспатчатся через `DB::afterCommit`.
- [ ] **`Idempotency-Key` обязателен для `POST /notifications`** (422 без него), повтор с тем же ключом и body → cached, другое body → 409. Хранилище — **только Redis**, ключ `idempotency:notifications.dispatch:{key}`, не Postgres.
- [ ] Дубль consume того же AMQP-сообщения не вызывает провайдер дважды (state check + optimistic lock).
- [ ] Stub-провайдер дедуплицирует по `NotificationId` (Redis Hash, TTL 24 ч).
- [ ] **`SimulateDeliveryAckJob` использует `queue:database`** (R4/A4) — at-least-once для ack-job через встроенный retry; `--tries=3`. Action идемпотентен. Нет `ReapStuckSentCommand` и нет schedule на 5 минут.
- [ ] **`attempts` в `notifications` хранит только неудачи** (R10). Успешный `markAsSent` НЕ инкрементирует.
- [ ] **`last_error`** содержит сообщение последнего gateway-исключения; **причина дропа** — в `status_history[last].reason`. Тесты дропа ассертят `status_history`, не `last_error`. Причины `ack_timeout` нет (R4 устраняет источник).
- [ ] Retry: до 5 попыток, `x-retries` в AMQP-headers, exponential backoff через TTL+DLX, после исчерпания — DLQ + `markAsDropped('max_retries_exceeded')`.
- [ ] Permanent reject (`GatewayRejectedException`) → `dropped` без retry, не уходит в DLQ.
- [ ] Outbox-публикация работает параллельно (2 воркера) через `FOR UPDATE SKIP LOCKED`. Единственный механизм — `outbox:publish --loop`. Publisher JOIN'ит `notifications` для подгрузки `trace_id` (R20).
- [ ] **Outbox cleanup `outbox:purge`** запланирован раз в сутки, удаляет `published_at < now() - 7 days`.
- [ ] **Trace-ID** *(бонус, R6)* пробрасывается: HTTP `X-Trace-Id` → `notifications.trace_id` (R20) → AMQP header `x-trace-id` → `Log::withContext()` в потребителе. Тест прохождения зелёный. При отключении функциональности соответствие `test.md` не теряется.
- [ ] Маркетинг физически не блокирует транзакционных воркеров (отдельные процессы + отдельный consumer + cpu/mem limits). Тест приоритезации: только `worker-transactional` → marketing остаётся `queued`.
- [ ] **`recipient` — единственный идентификатор подписчика** (R1/A1). Никакого `subscriber_id` нет — ни в таблице, ни в VO, ни в DTO. Индекс F3 — `notifications_recipient_created_idx`. Контакт в URL передаётся query-параметром; nginx access-log исключает `args` для этого пути.
- [ ] **GET-эндпоинт возвращает `recipient_masked`** (R9), не сырой контакт. Маска — результат `Recipient::masked()`.
- [ ] **`MessageBody::for(Channel, string)`** — единственная фабрика; SMS 1..1000, Email 1..10000; нарушение → `InvalidMessageBodyException` (422).
- [ ] Все индексы из §7 в миграциях, включая `outbox_published_at_idx`. **Нет** таблицы `idempotency_keys`, **нет** таблицы `dispatch_requests`, **нет** таблицы `subscribers`, **нет** поля `subscriber_id` в `notifications`, **нет** поля `trace_id` в `outbox_messages` (R20), **нет** индекса на `provider_message_id`.
- [ ] **Database queue tables (`jobs`, `failed_jobs`)** созданы стандартной миграцией Laravel; нужны для `SimulateDeliveryAckJob`.
- [ ] Exchange для DLQ называется `notifications.dlq.direct` (не `notifications.dlx`) — `basic_publish` после 5-й неудачи идёт в него, не через `x-dead-letter-exchange`.
- [ ] PII (recipient) маскируется единым `Recipient::masked()` во всех логах. `body` не логируется или маскируется (см. R12 как открытое улучшение).
- [ ] Architecture-тесты зелёные (включая запрет Eloquent в Application).
- [ ] PHPStan L8 clean.
- [ ] `composer audit` clean.
- [ ] **Интеграционный e2e тест на реальном RabbitMQ зелёный** (главное требование N6 из задания).
- [ ] **Тест приоритезации зелёный** (только worker-transactional → marketing не двигается).
- [ ] **Изоляция интеграционных тестов:** базовый `RabbitMqIntegrationTestCase` в `setUp()` объявляет топологию + purge `notifications.*` + flush Redis test-DB.
- [ ] OpenAPI спека покрывает все эндпоинты + явно описывает `Idempotency-Key` как required + допущения A1/A2/A3/A4/A5; Postman-коллекция отсутствует (R8/R21).
- [ ] **`compose.yaml`** (R16); `docker compose up` (холодный старт) поднимает весь стек одной командой; healthchecks для **всех** сервисов, включая PHP-FPM и worker-контейнеры (R17); `service_completed_successfully` гарантирует, что `migrate` отрабатывает до старта воркеров.
- [ ] README содержит шаги запуска, примеры curl с обязательным `Idempotency-Key` и `recipient`-query-параметром в GET, ссылку на Swagger, разделы «Поведенческие контракты», «Приоритезация», «Наблюдаемость (бонус)», и явное указание, что `NOTIFICATIONS_BATCH_MAX=5000` — мягкий лимит, настраиваемый через env (R18).

---

## 12. Что НЕ делаем (с обоснованием против `test.md`)

Каждый пункт — это решение «не добавлять», явно проверенное на отсутствие в `test.md`.

| Не делаем | Почему |
|---|---|
| Bearer-токен / Sanctum / Passport / OAuth | Auth не упомянут в `test.md`; сервис межсервисный |
| HMAC-подпись webhook’а | Нет webhook-эндпоинта (см. A3) |
| Эндпоинт `POST /providers/{p}/webhook` | `test.md` явно требует только заглушки; имитация `Sent → Delivered/Dropped` реализована внутренним job’ом |
| Отдельный эндпоинт `GET /notifications/{id}` | `test.md` требует «истории и статусов **подписчика**», что покрыто `GET /api/v1/notifications?recipient=...` |
| VO `SubscriberId`, поле `subscriber_id`, таблица `subscribers` | R1/A1: идентификатор = контакт; отдельная opaque-сущность не нужна |
| Команда `ReapStuckSentCommand` и Schedule на 5 минут | R4: database queue с `--tries=3` устраняет «висящие» `sent` |
| Postman-коллекция | R8/R21: `test.md` требует **либо** Swagger **либо** Postman; Swagger UI достаточен |
| Агрегат `DispatchRequest` и таблица `dispatch_requests` | Корреляция «какие уведомления одного API-вызова» не нужна вызывающей стороне; не упомянуто в `test.md` |
| Таблица `idempotency_keys` в Postgres | Дублирование стора; `test.md` прямо указывает Redis «для дедубликации» |
| Kafka | `test.md` оставляет выбор «Kafka или RabbitMQ»; RabbitMQ выбран обоснованно (см. §2) |
| Horizon / Octane / OpenTelemetry / Laravel Pulse | Observability за пределами `test.md`; structured logs с `trace_id` (бонус, R6) достаточны |
| Circuit Breaker | `test.md` не упоминает деградацию шлюзов; CLAUDE.md §6 запрещает future-proofing |
| Outbound rate limiter / token bucket на шлюзы | «Redis для контроля лимитов» закрыт **входным** `throttle:60,1` на API; исходящий лимитер — не требование `test.md` |
| Spatie Event Sourcing | `test.md` не требует event-sourcing; обычная state-машина проще |
| Шаблоны сообщений / переменные `{{name}}` / HTML | `body` — простая строка plain text в `test.md` |
| Управление подписчиками (opt-in / opt-out, blacklist) | Не упомянуто в `test.md` |
| Шифрование `body` / `recipient` at-rest | Не упомянуто в `test.md`; в production стоит обсудить отдельно |
| Multi-tenancy | Не упомянуто |
| Saga / распределённые транзакции | Не нужно: одна БД, outbox закрывает dual-write |
| CQRS read-side projections / материализованные view | Обычный read-репозиторий с DTO покрывает F3 без переусложнения |

---

### 13. Что в плане соответствует `test.md` хорошо

Не для протокола — чтобы при правках не потерять то, что сделано правильно:
- F1/F3/F4 покрыты явными эндпоинтами и state-машиной (§6, §4.1).
- F2 решена через bulkhead (раздельные очереди + раздельные пулы) — это сильнее, чем `x-max-priority`, и под него заложен явный тест (Фаза 8 тест 2). Контракт зафиксирован в README (§10 Фаза 9 шаг 4).
- N1/N2/N3: Outbox + manual ack + state check + optimistic lock — экзамен на «гарантию доставки» сдан с запасом, включая бонус N3 и явный тест восстановления (тест 12).
- N4: 5 попыток + exponential backoff через per-message expiration + DLX обратно в основной exchange — корректная RabbitMQ-идиома без кастомного scheduler’а.
- N5: `Idempotency-Key` обязателен, namespace на use-case заложен, поведение «cached / 409 / 422» — однозначное и тестируемое.
- N6: integration-тесты против реального RabbitMQ + Postgres + Redis с реальным `basic_get` (тесты 1 и 12).
- N7: healthcheck-зависимости (включая PHP-FPM и воркеров, R17) + one-shot `migrate` + `service_completed_successfully` — корректная схема холодного старта; `compose.yaml` (R16).
- A4 (виснущий `sent`) — устранён database-queue’ом для `SimulateDeliveryAckJob` (R4); reconciliation-команды нет, что упрощает архитектуру.
- DDD-границы: `OutboxRepository` как порт в Application, реализация — в Infrastructure, и `arch()`-тест, который это охраняет.
- API-контракт упрощён (R1): `recipients` — массив строк, идентификатор подписчика = его контакт; F3-ответ возвращает `recipient_masked` (R9) без утечки полного PII.
- `attempts` теперь хранит только неудачи (R10) — интуитивная семантика для клиентов API.
- Trace-ID propagation (R6) выделен как **бонус-наблюдаемость** сверх `test.md`, без жёсткой привязки к соответствию задания.

---


---

## 14. Замечания по соответствию (Compliance Review)

На основе анализа `test.md` подтверждено полное соответствие плана заданию.

- **Функциональность:** Все эндпоинты (рассылка, история) и статусы (`queued`, `sent`, `delivered`, `dropped`) соответствуют требованиям F1-F4.
- **Приоритезация:** Реализована через bulkhead (раздельные очереди), что гарантирует выполнение транзакционных сообщений вне очереди маркетинговых (F2).
- **Reliability:** План закрывает требования N1-N4 через Outbox, manual ack и ретраи с экспоненциальной задержкой.
- **Идемпотентность:** Требование N5 закрыто обязательным `Idempotency-Key` и хранилищем в Redis.
- **Тестирование:** План включает обязательные интеграционные тесты на реальном RabbitMQ (N6).
- **DevOps:** Инфраструктура в Docker Compose с healthcheck-зависимостями (N7).

### Дополнительные комментарии:
1. **Exactly-once (N3):** План реализует защиту от повторного вызова провайдера при повторном потреблении из очереди (state check + optimistic lock). Это покрывает "бонусное" требование exactly-once на уровне бизнес-логики.
2. **Модель подписчика (A1):** Допущение об отсутствии таблицы `subscribers` и использовании контакта как идентификатора является оптимальным для данного ТЗ, так как в `test.md` не указана необходимость управления профилями.
3. **Лимиты (A5):** Введение лимитов на размер `body` и размер батча (`5000`) является хорошей практикой проектирования, хотя явно не требовалось в `test.md`.
4. **Trace-ID (R6):** Сквозной трейсинг добавлен как полезное дополнение (бонус), не противоречащее основным требованиям.
