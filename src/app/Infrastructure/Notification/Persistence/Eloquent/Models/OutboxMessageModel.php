<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OutboxMessageModel extends Model
{
    use HasUuids;

    protected $table = 'outbox_messages';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'notification_id',
        'priority',
        'created_at',
        'published_at',
        'attempts',
        'last_error',
    ];

    protected $casts = [
        'created_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'attempts' => 'integer',
    ];
}
