<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'key' => 'credit_cost_cv_optimization',
                'value' => '5',
                'display_name' => 'CV Optimization Cost',
                'group' => 'credits',
                'type' => 'number',
            ],
            [
                'key' => 'credit_cost_proposal_generation',
                'value' => '2',
                'display_name' => 'Proposal Generation Cost',
                'group' => 'credits',
                'type' => 'number',
            ],
            [
                'key' => 'credit_cost_interview_prep',
                'value' => '10',
                'display_name' => 'Interview Preparation Cost',
                'group' => 'credits',
                'type' => 'number',
            ],
            [
                'key' => 'credit_cost_match_score',
                'value' => '1',
                'display_name' => 'Job Match Analysis Cost',
                'group' => 'credits',
                'type' => 'number',
            ],
            [
                'key' => 'credit_cost_social_bios',
                'value' => '3',
                'display_name' => 'Social Bios Generation Cost',
                'group' => 'credits',
                'type' => 'number',
            ],
        ];

        foreach ($settings as $setting) {
            \App\Models\Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
