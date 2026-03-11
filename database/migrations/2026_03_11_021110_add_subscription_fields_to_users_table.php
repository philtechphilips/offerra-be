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
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('subscription_provider')->nullable(); // 'polar', 'paystack'
            $table->string('subscription_id')->nullable()->index();
            $table->string('subscription_status')->nullable(); // 'active', 'canceled', 'non-renewing'
            $table->timestamp('subscription_ends_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn(['plan_id', 'subscription_provider', 'subscription_id', 'subscription_status', 'subscription_ends_at']);
        });
    }
};
