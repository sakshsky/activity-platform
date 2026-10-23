<?php

namespace App\Jobs;

use App\Services\CaptureDeletion;
use App\Services\Vault;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupDeletedCapture implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $captureId) {}

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(CaptureDeletion $deletion, Vault $vault): void
    {
        $deletion->cleanup($this->captureId, $vault);
    }
}
