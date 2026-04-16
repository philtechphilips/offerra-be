<?php

namespace App\Console\Commands;

use App\Models\JobApplication;
use App\Notifications\GenericNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendFollowUpReminders extends Command
{
    protected $signature = 'jobs:send-followup-reminders';
    protected $description = 'Send follow-up reminder notifications for job applications due today';

    public function handle(): void
    {
        $today = now()->toDateString();

        $jobs = JobApplication::with('user')
            ->whereDate('follow_up_date', $today)
            ->whereNotIn('status', ['rejected', 'offer'])
            ->get();

        $this->info("Found {$jobs->count()} follow-up reminder(s) for {$today}.");

        foreach ($jobs as $job) {
            try {
                $note = $job->follow_up_note
                    ? " — \"{$job->follow_up_note}\""
                    : '';

                $job->user->notify(new GenericNotification(
                    title: "Follow-up reminder: {$job->company}",
                    message: "Time to follow up on your application for {$job->title} at {$job->company}.{$note}",
                    type: 'follow_up',
                    action_url: '/dashboard/applications',
                ));

                Log::info("Follow-up reminder sent for job #{$job->id} to user #{$job->user_id}");
            } catch (\Exception $e) {
                Log::error("Failed to send follow-up reminder for job #{$job->id}: " . $e->getMessage());
            }
        }

        $this->info('Follow-up reminders dispatched.');
    }
}
