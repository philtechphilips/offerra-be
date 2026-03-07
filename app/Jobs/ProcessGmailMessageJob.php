<?php

namespace App\Jobs;

use App\Models\GoogleAccount;
use App\Services\GmailSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessGmailMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    protected $account;
    protected $messageId;

    /**
     * Create a new job instance.
     */
    public function __construct(GoogleAccount $account, string $messageId)
    {
        $this->account = $account;
        $this->messageId = $messageId;
    }

    /**
     * Execute the job.
     */
    public function handle(GmailSyncService $syncService): void
    {
        $syncService->processSingleMessage($this->account, $this->messageId);
    }
}
