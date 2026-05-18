<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Request;

use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\Recipient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class DispatchNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:sms,email'],
            'priority' => ['required', 'string', 'in:transactional,marketing'],
            'body' => ['required', 'string', 'min:1'],
            'recipients' => [
                'required',
                'array',
                'min:1',
                'max:'.config('notifications.batch_max', 5000),
            ],
            'recipients.*' => ['required', 'string', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->any()) {
                return;
            }

            $channel = Channel::from($this->input('channel'));
            $body = $this->input('body');

            try {
                MessageBody::for($channel, $body);
            } catch (\DomainException $e) {
                $validator->errors()->add('body', $e->getMessage());
            }

            foreach ($this->input('recipients') as $index => $recipient) {
                try {
                    Recipient::fromString($channel, $recipient);
                } catch (\DomainException $e) {
                    $validator->errors()->add("recipients.{$index}", $e->getMessage());
                }
            }
        });
    }
}
