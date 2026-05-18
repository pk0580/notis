<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Middleware;

use App\Application\Notification\Idempotency\IdempotencyStore;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class IdempotencyMiddleware
{
    public function __construct(
        private IdempotencyStore $store
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return new JsonResponse([
                'error' => [
                    'code' => 'idempotency_key_required',
                    'message' => 'Idempotency-Key header is required',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hash = hash('sha256', $request->getContent());

        if ($cached = $this->store->get($key)) {
            if ($cached['request_hash'] !== $hash) {
                return new JsonResponse([
                    'error' => [
                        'code' => 'idempotency_key_conflict',
                        'message' => 'Idempotency-Key reused with different payload',
                    ],
                ], Response::HTTP_CONFLICT);
            }

            return new JsonResponse(
                json_decode($cached['response'], true),
                $cached['status_code']
            );
        }

        $response = $next($request);

        if ($response->isSuccessful() || $response->getStatusCode() === Response::HTTP_ACCEPTED) {
            $this->store->set($key, [
                'request_hash' => $hash,
                'response' => $response->getContent(),
                'status_code' => $response->getStatusCode(),
            ], 86400); // 24 hours
        }

        return $response;
    }
}
