<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Identify all app tables with ID or User relations
        $tables = ['users', 'plans', 'job_applications', 'user_profiles', 'google_accounts', 'settings', 'personal_access_tokens', 'portfolios', 'sessions'];

        // 2. Add 'new_uuid' to everything with an ID
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'new_uuid')) {
                Schema::table($table, function (Blueprint $tableObj) {
                    $tableObj->uuid('new_uuid')->nullable()->after('id');
                });
                
                DB::table($table)->get()->each(function ($row) use ($table) {
                    DB::table($table)->where('id', $row->id)->update(['new_uuid' => (string) Str::uuid()]);
                });
            }
        }

        // 3. Add mapping columns for foreign keys
        $foreignMappings = [
            'users' => ['new_plan_id' => 'plan_id'],
            'job_applications' => ['new_user_id' => 'user_id'],
            'user_profiles' => ['new_user_id' => 'user_id'],
            'google_accounts' => ['new_user_id' => 'user_id'],
            'portfolios' => ['new_user_id' => 'user_id'],
            'sessions' => ['new_user_id' => 'user_id'],
            'personal_access_tokens' => ['new_tokenable_id' => 'tokenable_id']
        ];

        foreach ($foreignMappings as $table => $cols) {
            if (!Schema::hasTable($table)) continue;
            foreach ($cols as $newCol => $oldCol) {
                if (!Schema::hasColumn($table, $newCol) && Schema::hasColumn($table, $oldCol)) {
                    Schema::table($table, function (Blueprint $tableObj) use ($newCol) {
                        $tableObj->string($newCol)->nullable();
                    });
                }
            }
        }

        // 4. Map values
        // User -> Plan
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'plan_id')) {
            DB::table('users')->join('plans', 'users.plan_id', '=', 'plans.id')->update(['users.new_plan_id' => DB::raw('plans.new_uuid')]);
        }
        // Others -> User
        foreach (['job_applications', 'user_profiles', 'google_accounts', 'portfolios', 'sessions'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
                DB::table($table)->join('users', "$table.user_id", '=', 'users.id')->update(["$table.new_user_id" => DB::raw('users.new_uuid')]);
            }
        }
        // Sanctum
        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('tokenable_type', 'App\Models\User')->join('users', 'personal_access_tokens.tokenable_id', '=', 'users.id')->update(['personal_access_tokens.new_tokenable_id' => DB::raw('users.new_uuid')]);
        }

        // 5. ATOMIC SWAP
        Schema::disableForeignKeyConstraints();

        // 5.1 Drop ALL possible FKs to Users or Plans
        $fkDrops = [
            'users' => ['users_plan_id_foreign'],
            'job_applications' => ['job_applications_user_id_foreign'],
            'user_profiles' => ['user_profiles_user_id_foreign'],
            'google_accounts' => ['google_accounts_user_id_foreign'],
            'portfolios' => ['portfolios_user_id_foreign'],
        ];
        foreach ($fkDrops as $table => $fks) {
            if (!Schema::hasTable($table)) continue;
            foreach ($fks as $fk) {
                try { DB::statement("ALTER TABLE $table DROP FOREIGN KEY $fk"); } catch (\Exception $e) {}
            }
        }

        // 5.2 Drop old columns
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $tableObj) use ($table) {
                if (Schema::hasColumn($table, 'id')) { $tableObj->dropColumn('id'); }
            });
        }
        foreach ($foreignMappings as $table => $cols) {
            if (!Schema::hasTable($table)) continue;
            foreach ($cols as $newCol => $oldCol) {
                Schema::table($table, function (Blueprint $tableObj) use ($table, $oldCol) {
                    if (Schema::hasColumn($table, $oldCol)) { $tableObj->dropColumn($oldCol); }
                });
            }
        }

        // 5.3 Rename and Set PK
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) continue;
            Schema::table($table, function (Blueprint $tableObj) use ($table) {
                if (Schema::hasColumn($table, 'new_uuid')) { $tableObj->renameColumn('new_uuid', 'id'); }
            });
            Schema::table($table, function (Blueprint $tableObj) { $tableObj->primary('id'); });
        }
        foreach ($foreignMappings as $table => $cols) {
            if (!Schema::hasTable($table)) continue;
            foreach ($cols as $newCol => $oldCol) {
                Schema::table($table, function (Blueprint $tableObj) use ($table, $newCol, $oldCol) {
                    if (Schema::hasColumn($table, $newCol)) { $tableObj->renameColumn($newCol, $oldCol); }
                });
            }
        }

        // 6. Re-add Constraints (Only for active tables)
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('set null');
        });
        Schema::table('job_applications', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void {}
};
