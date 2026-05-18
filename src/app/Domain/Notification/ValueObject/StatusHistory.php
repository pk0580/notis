<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

use DateTimeImmutable;

final readonly class StatusHistory
{
    /** @param list<array{status: string, at: string, reason?: string}> $items */
    public function __construct(public array $items = []) {}

    public function withTransition(NotificationStatus $status, DateTimeImmutable $at, ?string $reason = null): self
    {
        $items = $this->items;
        $entry = [
            'status' => $status->value,
            'at' => $at->format(DateTimeImmutable::ATOM),
        ];

        if ($reason !== null) {
            $entry['reason'] = $reason;
        }

        $items[] = $entry;

        return new self($items);
    }
}
