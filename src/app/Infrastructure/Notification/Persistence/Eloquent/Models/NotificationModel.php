<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationModel extends Model
{
    protected $table = 'notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'recipient',
        'channel',
        'priority',
        'body',
        'status',
        'status_history',
        'attempts',
        'last_error',
        'provider_message_id',
        'trace_id',
        'version',
    ];

    protected $casts = [
        'status_history' => 'array',
        'attempts' => 'integer',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
