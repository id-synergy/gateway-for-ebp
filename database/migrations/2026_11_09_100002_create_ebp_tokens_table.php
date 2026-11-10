<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un jeu de tokens OAuth par compte EBP (relation 1-1). Secrets chiffrés par le cast du modèle.
        Schema::create('ebp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ebp_account_id')->unique()->constrained('ebp_accounts')->cascadeOnDelete();
            $table->text('access_token')->nullable();           // chiffré (cast), valide 1 h
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();          // chiffré (cast), valide 30 j, usage unique
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->text('last_refresh_error')->nullable();
            $table->enum('state', ['healthy', 'expiring', 'broken'])->default('broken');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebp_tokens');
    }
};
