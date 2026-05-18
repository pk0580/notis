<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
