<?php

declare(strict_types=1);

namespace App\Domain\Notification\Entity;

use App\Domain\Notification\Exception\InvalidNotificationStatusTransitionException;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Domain\Notification\ValueObject\Recipient;
use App\Domain\Notification\ValueObject\StatusHistory;
use DateTimeImmutable;

final class Notification
{
    private function __construct(
        public readonly NotificationId $id,
        public readonly Recipient $recipient,
        public readonly Channel $channel,
        public readonly Priority $priority,
        public readonly MessageBody $body,
        private NotificationStatus $status,
        private StatusHistory $history,
        public readonly ?string $traceId = null,
        private ?ProviderMessageId $providerMessageId = null,
        private int $attempts = 0,
        private ?string $lastError = null,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null,
        public readonly int $version = 0,
    ) {
    }

    public static function create(
        Recipient $recipient,
        Channel $channel,
        Priority $priority,
        MessageBody $body,
        ?string $traceId = null
    ): self {
        $status = NotificationStatus::Queued;
        $at = new DateTimeImmutable();

        return new self(
            id: NotificationId::generate(),
            recipient: $recipient,
            channel: $channel,
            priority: $priority,
            body: $body,
            status: $status,
            history: (new StatusHistory())->withTransition($status, $at),
            traceId: $traceId,
            createdAt: $at,
            updatedAt: $at,
        );
    }

    public static function reconstitute(
        NotificationId $id,
        Recipient $recipient,
        Channel $channel,
        Priority $priority,
        MessageBody $body,
        NotificationStatus $status,
        StatusHistory $history,
        ?string $traceId,
        ?ProviderMessageId $providerMessageId,
        int $attempts,
        ?string $lastError,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        int $version
    ): self {
        return new self(
            $id,
            $recipient,
            $channel,
            $priority,
            $body,
            $status,
            $history,
            $traceId,
            $providerMessageId,
            $attempts,
            $lastError,
            $createdAt,
            $updatedAt,
            $version
        );
    }

    public function markAsSent(ProviderMessageId $providerMessageId): void
    {
        if ($this->status !== NotificationStatus::Queued) {
            throw new InvalidNotificationStatusTransitionException(
                "Cannot mark as sent from status: {$this->status->value}"
            );
        }

        $this->status = NotificationStatus::Sent;
        $this->providerMessageId = $providerMessageId;
        $this->history = $this->history->withTransition($this->status, new DateTimeImmutable());
    }

    public function markAsDelivered(): void
    {
        if ($this->status !== NotificationStatus::Sent) {
            throw new InvalidNotificationStatusTransitionException(
                "Cannot mark as delivered from status: {$this->status->value}"
            );
        }

        $this->status = NotificationStatus::Delivered;
        $this->history = $this->history->withTransition($this->status, new DateTimeImmutable());
    }

    public function markAsDropped(string $reason): void
    {
        $allowed = [NotificationStatus::Queued, NotificationStatus::Sent];
        if (!in_array($this->status, $allowed, true)) {
            throw new InvalidNotificationStatusTransitionException(
                "Cannot mark as dropped from status: {$this->status->value}"
            );
        }

        $this->status = NotificationStatus::Dropped;
        $this->history = $this->history->withTransition($this->status, new DateTimeImmutable(), $reason);
    }

    public function recordFailedAttempt(string $error): void
    {
        $this->attempts++;
        $this->lastError = $error;
    }

    public function status(): NotificationStatus
    {
        return $this->status;
    }

    public function history(): StatusHistory
    {
        return $this->history;
    }

    public function providerMessageId(): ?ProviderMessageId
    {
        return $this->providerMessageId;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
