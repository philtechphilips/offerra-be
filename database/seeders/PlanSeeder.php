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
                'name' => 'Starter Pack',
                'slug' => 'starter-pack',
                'price_usd' => 0,
                'price_ngn' => 0,
                'credits' => 10,
                'description' => 'Free credits for new users to explore.',
                'features' => [
                    '10 AI Power Credits',
                    'Full Dashboard Access',
                    'Real-time status tracking',
                    'Email notifications'
                ],
                'not_included' => [],
                'is_popular' => false,
                'btn_text' => 'Get Started'
            ],
            [
                'name' => 'Pro Booster',
                'slug' => 'pro-booster',
                'price_usd' => 9,
                'price_ngn' => 15000,
                'credits' => 100,
                'description' => '100 credits for active job seekers.',
                'features' => [
                    '100 AI Power Credits',
                    'AI CV Optimization',
                    'Proposal Generator',
                    'Gmail Status Sync',
                    'Priority Support'
                ],
                'not_included' => [],
                'is_popular' => true,
                'btn_text' => 'Buy Credits'
            ],
            [
                'name' => 'Elite Growth',
                'slug' => 'elite-growth',
                'price_usd' => 19,
                'price_ngn' => 30000,
                'credits' => 250,
                'description' => '250 credits for high-volume searching.',
                'features' => [
                    '250 AI Power Credits',
                    'AI Interview Coaching',
                    'Predicted Answers',
                    'Strategic Success Path',
                    'Dedicated Career Scout'
                ],
                'not_included' => [],
                'is_popular' => false,
                'btn_text' => 'Get More Credits'
            ]
        ];

        foreach ($plans as $plan) {
            \App\Models\Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
