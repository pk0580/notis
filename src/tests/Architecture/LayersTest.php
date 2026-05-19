<?php

declare(strict_types=1);

arch('domain is framework-free')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'Symfony', 'Eloquent', 'Carbon']);

arch('application has no http')
    ->expect('App\Application')
    ->not->toUse(['Illuminate\Http']);

arch('application has no eloquent / db facade')
    ->expect('App\Application')
    ->not->toUse(['Illuminate\Database\Eloquent', 'Illuminate\Support\Facades\DB']);

arch('outbox repository is a port (interface)')
    ->expect('App\Application\Notification\Outbox\OutboxRepository')
    ->toBeInterface();

arch('controllers are invokable')
    ->expect('App\Interface\Http\Notification\Controller')
    ->classes()
    ->toHaveMethod('__invoke');
