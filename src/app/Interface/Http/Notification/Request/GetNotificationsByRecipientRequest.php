<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Request;

use App\Domain\Notification\Exception\InvalidRecipientException;
use App\Domain\Notification\ValueObject\Recipient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class GetNotificationsByRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string'],
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $recipient = $this->query('recipient');
            if (is_string($recipient)) {
                try {
                    Recipient::fromAny($recipient);
                } catch (InvalidRecipientException) {
                    $validator->errors()->add('recipient', 'invalid_recipient');
                }
            }
        });
    }

    public function getRecipient(): Recipient
    {
        /** @var string $recipient */
        $recipient = $this->query('recipient');

        return Recipient::fromAny($recipient);
    }
}
