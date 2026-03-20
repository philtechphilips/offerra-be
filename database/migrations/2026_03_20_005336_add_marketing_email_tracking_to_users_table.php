<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_email_sent_at')->nullable();
            $table->timestamp('day1_email_sent_at')->nullable();
            $table->timestamp('day7_email_sent_at')->nullable();
            $table->timestamp('day15_email_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'welcome_email_sent_at',
                'day1_email_sent_at',
                'day7_email_sent_at',
                'day15_email_sent_at'
            ]);
        });
    }
};
