<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_tokens', function (Blueprint $table) {
            // Jeton d'app en clair, CHIFFRÉ au repos (APP_KEY) — permet de ré-afficher le
            // QR d'appairage (même jeton sur plusieurs appareils) sans régénérer la clé.
            // Nullable : les jetons créés avant cette migration n'ont pas de clair stocké
            // (il faut les régénérer une fois pour les amorcer).
            $table->text('encrypted_token')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('app_tokens', function (Blueprint $table) {
            $table->dropColumn('encrypted_token');
        });
    }
};
