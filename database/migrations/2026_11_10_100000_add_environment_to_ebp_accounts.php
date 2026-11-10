<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            // Environnement EBP ciblé : production ou préproduction.
            $table->enum('environment', ['production', 'preproduction'])
                ->default('production')
                ->after('active_subscription_key');
        });
    }

    public function down(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};
