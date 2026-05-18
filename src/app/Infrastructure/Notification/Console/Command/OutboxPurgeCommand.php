<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Console\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OutboxPurgeCommand extends Command
{
    protected $signature = 'outbox:purge {--days=7 : Days to keep published messages}';

    protected $description = 'Purge published outbox messages';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $date = now()->subDays($days);

        $this->info("Purging outbox messages published before {$date->toDateTimeString()}...");

        $totalDeleted = 0;
        do {
            $deleted = DB::table('outbox_messages')
                ->whereNotNull('published_at')
                ->where('published_at', '<', $date)
                ->limit(5000)
                ->delete();

            $totalDeleted += $deleted;
            $this->info("Deleted {$deleted} messages...");
        } while ($deleted === 5000);

        if ($totalDeleted > 0) {
            Log::info('outbox.purged', [
                'count' => $totalDeleted,
                'before' => $date->toDateTimeString(),
            ]);
        }

        $this->info("Total deleted: {$totalDeleted}");

        return self::SUCCESS;
    }
}
