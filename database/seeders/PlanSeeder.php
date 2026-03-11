<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Essential',
                'slug' => 'essential',
                'price_usd' => 0,
                'price_ngn' => 0,
                'description' => 'Perfect for beginners starting their journey.',
                'features' => [
                    'Track 5 jobs /mo',
                    'Basic Dashboard',
                    'Real-time status tracking',
                    'Email notifications'
                ],
                'not_included' => [
                    'AI CV Optimization',
                    'Interview Practice',
                    'Unlimited History',
                    'Advanced Analytics'
                ],
                'is_popular' => false,
                'btn_text' => 'Current Plan'
            ],
            [
                'name' => 'Pro Track',
                'slug' => 'pro-track',
                'price_usd' => 9,
                'price_ngn' => 15000,
                'description' => 'The sweet spot for active job seekers.',
                'features' => [
                    'Unlimited Auto Tracking',
                    'AI CV Optimization',
                    'Proposal Generator',
                    'Momentum Analytics',
                    'Gmail Status Sync',
                    'Priority Support'
                ],
                'is_popular' => true,
                'btn_text' => 'Upgrade to Pro'
            ],
            [
                'name' => 'Elite',
                'slug' => 'elite',
                'price_usd' => 19,
                'price_ngn' => 30000,
                'description' => 'For professionals who want the best edge.',
                'features' => [
                    'Everything in Pro',
                    'AI Interview Coaching',
                    'Predicted Answers',
                    'Strategic Success Path',
                    'Dedicated Career Scout',
                    'Direct Referral Network'
                ],
                'is_popular' => false,
                'btn_text' => 'Go Elite'
            ]
        ];

        foreach ($plans as $plan) {
            \App\Models\Plan::create($plan);
        }
    }
}
