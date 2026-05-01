<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avoid doctrine/dbal dependency by issuing SQL directly.
        DB::statement('ALTER TABLE field_memories MODIFY field_value LONGTEXT NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE field_memories MODIFY field_value TEXT NOT NULL');
    }
};
