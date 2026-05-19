<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class TraceIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Trace-Id') ?? (string) Str::uuid7();

        $request->attributes->set('trace_id', $traceId);

        Log::withContext([
            'trace_id' => $traceId,
        ]);

        $response = $next($request);

        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
