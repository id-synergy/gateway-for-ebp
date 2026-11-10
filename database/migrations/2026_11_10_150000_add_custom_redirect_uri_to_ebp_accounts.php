<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            // redirect_uri whitelisté chez EBP, personnalisé par compte.
            // Vide = callback par défaut de la gateway. Rempli (ex. http://localhost:3333/...)
            // = c'est cette URL qui part à l'autorisation ET à l'échange code→token, ce qui
            // permet de débloquer un compte sans attendre la validation de domaine par EBP.
            $table->string('custom_redirect_uri')->nullable()->after('environment');
        });
    }

    public function down(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            $table->dropColumn('custom_redirect_uri');
        });
    }
};
