<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('professional_headline');
            $table->boolean('public_profile_enabled')->default(false)->after('username');
            $table->string('location', 100)->nullable()->after('public_profile_enabled');
            $table->string('linkedin_url')->nullable()->after('location');
            $table->string('github_url')->nullable()->after('linkedin_url');
            $table->string('twitter_url')->nullable()->after('github_url');
            $table->string('portfolio_url')->nullable()->after('twitter_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username', 'public_profile_enabled', 'location',
                'linkedin_url', 'github_url', 'twitter_url', 'portfolio_url',
            ]);
        });
    }
};
