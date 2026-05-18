<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Application\Notification\UseCase\AcknowledgeDelivery\AcknowledgeDeliveryAction;
use App\Infrastructure\Notification\Job\SimulateDeliveryAckJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class Scenario10DatabaseQueueRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulate_delivery_ack_job_retries_on_failure(): void
    {
        // 1. Prepare a notification
        $notificationId = \Illuminate\Support\Str::uuid()->toString();
        
        // 2. Mock Repository to fail
        $repoMock = Mockery::mock(\App\Domain\Notification\Repository\NotificationRepository::class);
        $repoMock->shouldReceive('findById')->once()->andThrow(new \RuntimeException('Transient DB error'));

        // 3. Dispatch job to database queue
        SimulateDeliveryAckJob::dispatch($notificationId)
            ->onConnection('database')
            ->onQueue('default');

        $this->assertDatabaseHas('jobs', [
            'queue' => 'default',
        ]);

        // 4. Try to run the job
        $jobRow = DB::table('jobs')->first();
        $jobData = json_decode($jobRow->payload, true);
        
        $job = unserialize($jobData['data']['command']);
        
        $action = new AcknowledgeDeliveryAction($repoMock);

        try {
            $job->handle($action);
            $this->fail('Should have thrown exception');
        } catch (\Throwable $e) {
            $this->assertEquals('Transient DB error', $e->getMessage());
        }
    }
}
