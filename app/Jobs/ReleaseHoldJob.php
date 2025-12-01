<?php

namespace App\Jobs;

use App\Services\HoldServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReleaseHoldJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private int $holdId
    ) {}

    /**
     * Get the unique ID for the job (prevents duplicate processing).
     */
    public function uniqueId(): string
    {
        return "release_hold_{$this->holdId}";
    }

    /**
     * Execute the job.
     */
    public function handle(HoldServiceInterface $holdService): void
    {
        Log::info('Processing hold release job', ['hold_id' => $this->holdId]);

        try {
            $released = $holdService->releaseHold($this->holdId);

            if ($released) {
                Log::info('Hold release job completed', ['hold_id' => $this->holdId]);
            } else {
                Log::info('Hold was already released or used', ['hold_id' => $this->holdId]);
            }
        } catch (\Exception $e) {
            Log::error('Hold release job failed', [
                'hold_id' => $this->holdId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}

