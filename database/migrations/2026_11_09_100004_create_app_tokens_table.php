<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Jetons des apps clientes. Chaque jeton cible un compte EBP (+ dossier par défaut optionnel).
        Schema::create('app_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash')->unique();   // hash du jeton ; le clair n'est montré qu'à la création
            $table->foreignId('ebp_account_id')->constrained('ebp_accounts')->cascadeOnDelete();
            $table->foreignId('default_folder_id')->nullable()->constrained('ebp_folders')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_tokens');
    }
};
