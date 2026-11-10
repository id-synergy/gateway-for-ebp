<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            // Cache des lectures, réglé PAR COMPTE depuis le back-office.
            // cache_ttl null = repli sur la config globale (ebp.gateway.cache.default_ttl).
            $table->boolean('cache_enabled')->default(true)->after('is_active');
            $table->unsignedInteger('cache_ttl')->nullable()->after('cache_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            $table->dropColumn(['cache_enabled', 'cache_ttl']);
        });
    }
};
