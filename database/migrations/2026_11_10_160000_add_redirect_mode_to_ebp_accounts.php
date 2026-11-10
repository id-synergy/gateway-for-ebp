<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            // Mode de redirection OAuth du compte :
            //  - gateway  : callback par défaut de la gateway (domaine validé chez EBP)
            //  - localhost: redirect_uri localhost whitelisté d'origine (config), saisie manuelle du retour
            //  - custom   : redirect_uri personnalisé (custom_redirect_uri), saisie manuelle du retour
            $table->string('redirect_mode', 16)->default('gateway')->after('environment');
        });

        // Les comptes qui avaient déjà un custom_redirect_uri passent en mode custom.
        \Illuminate\Support\Facades\DB::table('ebp_accounts')
            ->whereNotNull('custom_redirect_uri')
            ->update(['redirect_mode' => 'custom']);
    }

    public function down(): void
    {
        Schema::table('ebp_accounts', function (Blueprint $table) {
            $table->dropColumn('redirect_mode');
        });
    }
};
