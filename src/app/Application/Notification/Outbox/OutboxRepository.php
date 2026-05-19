<?php

declare(strict_types=1);

namespace App\Application\Notification\Outbox;

interface OutboxRepository
{
    /**
     * Bulk append in the current transaction, chunked inside the implementation.
     *
     * @param  OutboxEntry[]  $entries
     */
    public function appendMany(array $entries): void;
}
