<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Mail\Day1Mail;
use App\Mail\Day7Mail;
use App\Mail\Day15Mail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendMarketingEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-marketing-emails';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send automated marketing emails based on user account age';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 1. Day 1 Email (24 hours after registration)
        $this->sendToUsers(
            User::whereNull('day1_email_sent_at')
                ->where('created_at', '<=', now()->subDay()),
            Day1Mail::class,
            'day1_email_sent_at'
        );

        // 2. Day 7 Email (7 days after registration)
        $this->sendToUsers(
            User::whereNull('day7_email_sent_at')
                ->where('created_at', '<=', now()->subDays(7)),
            Day7Mail::class,
            'day7_email_sent_at'
        );

        // 3. Day 15 Email (15 days after registration - only for non-Pro users)
        $this->sendToUsers(
            User::whereNull('day15_email_sent_at')
                ->where('created_at', '<=', now()->subDays(15))
                ->where(function ($query) {
                    $query->whereNull('plan_id')
                          ->orWhereHas('plan', function ($q) {
                              $q->where('price_usd', 0);
                          });
                }),
            Day15Mail::class,
            'day15_email_sent_at'
        );

        $this->info('Marketing emails processed.');
    }

    protected function sendToUsers($query, $mailClass, $trackingColumn)
    {
        $users = $query->get();

        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new $mailClass($user));
                $user->update([$trackingColumn => now()]);
                $this->info("Sent {$mailClass} to {$user->email}");
            } catch (\Exception $e) {
                $this->error("Failed to send to {$user->email}: " . $e->getMessage());
            }
        }
    }
}
