# Notification Service

Микросервис массовых уведомлений с поддержкой приоритезации, гарантии доставки (Reliability) и идемпотентности.

## Технологический стек
- **PHP 8.4** (Laravel 11+ skeleton)
- **PostgreSQL 16** (Основное хранилище и Outbox)
- **RabbitMQ 3.13** (Брокер сообщений)
- **Redis 7** (Идемпотентность и лимиты)
- **Nginx** (Прокси)

## Быстрый старт

1. Склонируйте репозиторий.
2. Скопируйте файл окружения:
   ```bash
   cp src/.env.example src/.env
   ```
3. Запустите инфраструктуру:
   ```bash
   docker compose up -d
   ```

Сервис будет доступен по адресу `http://localhost:8080`.
RabbitMQ Management UI: `http://localhost:15672` (guest/guest).

## Конфигурация
Основные настройки уведомлений находятся в `src/config/notifications.php` и настраиваются через `.env`:
- `NOTIFICATIONS_BATCH_MAX`: Максимальный размер батча (мягкий лимит: 5000).
- `NOTIFICATIONS_MAX_ATTEMPTS`: Количество попыток отправки (5).
- `NOTIFICATIONS_RETRY_BACKOFF_MS`: Экспоненциальная задержка между ретраями.

## Архитектура
Проект следует принципам DDD и Clean Architecture:
- `Domain`: Чистая бизнес-логика (Entities, VOs, Events).
- `Application`: Сценарии использования (Actions, DTOs).
- `Infrastructure`: Техническая реализация (Repositories, Eloquent, RabbitMQ).
- `Interface`: Точки входа (API Controllers, CLI).

Подробное описание API и контрактов будет доступно в Swagger по адресу `http://localhost:8080/api/docs`.
