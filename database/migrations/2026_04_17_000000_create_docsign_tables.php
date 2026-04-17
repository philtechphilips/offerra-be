<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('file_path');
            $table->string('signed_path')->nullable();
            $table->string('status')->default('pending'); // pending, signed
            $table->timestamp('signed_at')->nullable();
            $table->json('metadata')->nullable(); // For field positions, etc.
            $table->timestamps();
        });

        Schema::create('field_memories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->string('field_name'); // e.g. "Full Name", "address"
            $table->text('field_value');
            $table->timestamps();

            $table->unique(['user_id', 'field_name']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('field_memories');
        Schema::dropIfExists('documents');
    }
};
