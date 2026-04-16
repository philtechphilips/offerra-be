<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable()->after('description');
            $table->text('follow_up_note')->nullable()->after('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::table('job_applications', function (Blueprint $table) {
            $table->dropColumn(['follow_up_date', 'follow_up_note']);
        });
    }
};
