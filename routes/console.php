<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\GoogleAccount;
use App\Services\GmailSyncService;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('emails:sync-recent', function (GmailSyncService $syncService) {
    $this->info('Starting recent email sync...');
    // You could also chunk this or queue it if there are thousands of users.
    $accounts = GoogleAccount::all();
    $count = 0;
    
    foreach ($accounts as $account) {
        try {
            // '1h' tells Gmail to fetch emails newer than 1 hour
            $syncService->sync($account, '1h');
            $count++;
        } catch (\Exception $e) {
            Log::error("Failed to sync recent emails for account {$account->id}: " . $e->getMessage());
        }
    }
    
    $this->info("Completed sync for $count accounts.");
})->purpose('Sync emails from the last hour for all connected Google accounts');

Schedule::command('emails:sync-recent')->hourly();
Schedule::command('app:send-marketing-emails')->daily();
