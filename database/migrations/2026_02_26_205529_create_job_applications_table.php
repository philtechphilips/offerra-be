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
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('company')->nullable();
            $table->string('location')->nullable();
            $table->string('type')->nullable(); // Full-time, etc.
            $table->boolean('is_remote')->default(false);
            $table->string('salary')->nullable();
            $table->string('job_url')->nullable();
            $table->string('company_url')->nullable();
            $table->text('contact_info')->nullable(); // AI will fill this
            $table->text('summary')->nullable(); // AI will summarize the JD
            $table->json('tech_stack')->nullable(); // AI will extract tags
            $table->enum('status', ['applied', 'tracking', 'interview', 'rejected', 'offer'])->default('tracking');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
