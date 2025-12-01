<?php

namespace App\Console\Commands;

use App\Jobs\ReleaseHoldJob;
use App\Services\HoldServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireHoldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holds:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and release expired holds';

    /**
     * Execute the console command.
     */
    public function handle(HoldServiceInterface $holdService): int
    {
        $this->info('Checking for expired holds...');

        $expiredHolds = $holdService->getExpiredHolds();
        $count = $expiredHolds->count();

        if ($count === 0) {
            $this->info('No expired holds found.');
            return self::SUCCESS;
        }

        $this->info("Found {$count} expired holds. Dispatching release jobs...");

        foreach ($expiredHolds as $hold) {
            ReleaseHoldJob::dispatch($hold->id);
            
            Log::info('Dispatched release job for expired hold', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
            ]);
        }

        $this->info("Dispatched {$count} release jobs.");
        return self::SUCCESS;
    }
}

