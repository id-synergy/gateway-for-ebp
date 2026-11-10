<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passe environment d'enum (production/preproduction) à string, pour accepter aussi
        // 'demo' (serveur simulé) sans contrainte CHECK rigide. La validation applicative garde la main.
        Schema::table('ebp_accounts', function (Blueprint $table) {
            $table->string('environment')->default('production')->change();
        });
    }

    public function down(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            $table->enum('environment', ['production', 'preproduction'])->default('production')->change();
        });
    }
};
