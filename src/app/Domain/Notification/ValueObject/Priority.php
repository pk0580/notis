<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

enum Priority: string
{
    case Transactional = 'transactional';
    case Marketing = 'marketing';
}
