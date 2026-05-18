<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

use App\Domain\Notification\Exception\InvalidRecipientException;

final readonly class Recipient
{
    public function __construct(
        public string $value,
        public Channel $channel
    ) {
        $this->validate($value, $channel);
    }

    public static function fromString(Channel $channel, string $value): self
    {
        return new self($value, $channel);
    }

    public static function fromAny(string $value): self
    {
        if (str_starts_with($value, '+')) {
            return new self($value, Channel::Sms);
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return new self($value, Channel::Email);
        }

        throw new InvalidRecipientException("Invalid recipient format: $value");
    }

    private function validate(string $value, Channel $channel): void
    {
        if ($channel === Channel::Sms) {
            if (!preg_match('/^\+[1-9]\d{6,14}$/', $value)) {
                throw new InvalidRecipientException("Invalid SMS recipient format (E.164 required): $value");
            }
        }

        if ($channel === Channel::Email) {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidRecipientException("Invalid Email recipient format: $value");
            }
        }
    }

    public function masked(): string
    {
        if ($this->channel === Channel::Sms) {
            return substr($this->value, 0, 2) . '***' . substr($this->value, -4);
        }

        [$user, $domain] = explode('@', $this->value);
        return substr($user, 0, 1) . '***@' . $domain;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value && $this->channel === $other->channel;
    }
}
