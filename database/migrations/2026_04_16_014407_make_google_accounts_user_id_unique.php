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
        Schema::table('google_accounts', function (Blueprint $table) {
            // First, remove duplicates if they exist (unlikely but safe)
            // SQL: DELETE t1 FROM google_accounts t1 INNER JOIN google_accounts t2 WHERE t1.id < t2.id AND t1.user_id = t2.user_id;
            
            // Add unique constraint
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};
