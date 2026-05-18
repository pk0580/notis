<?php

declare(strict_types=1);

namespace App\Application\Notification\Query\GetNotificationsByRecipient;

final readonly class NotificationView
{
    public function __construct(
        public string $id,
        public string $channel,
        public string $priority,
        public string $status,
        public string $recipient_masked,
        public int $attempts,
        public ?string $last_error,
        public array $status_history,
        public string $created_at,
        public string $updated_at,
    ) {}
}
