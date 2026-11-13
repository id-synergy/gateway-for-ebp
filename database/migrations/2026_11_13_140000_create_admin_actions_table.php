<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit trail des ACTIONS d'administration du back-office : qui a fait quoi
        //  (création, modification, suppression) sur les ressources sensibles.
        // 3e registre distinct :
        //  - request_logs      : trafic des apps clientes vers EBP ;
        //  - admin_auth_events : connexions/déconnexions des admins (login/logout) ;
        //  - admin_actions     : écritures d'administration (ce registre).
        // On ne stocke JAMAIS de secret dans `properties` (jeton en clair, mot de passe,
        //  client_secret, subscription key…).
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            // nullOnDelete : on conserve l'audit même si l'admin est supprimé plus tard.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Ex. account.created, account.deleted, token.revoked, ip.blocked, admin.deactivated…
            $table->string('action');
            // Relation polymorphe (nullable) vers la ressource visée. On n'ajoute pas de FK :
            //  la ressource peut être supprimée alors que l'audit, lui, doit survivre.
            $table->nullableMorphs('subject');
            // Libellé lisible FIGÉ au moment de l'action (nom du compte/jeton/IP…),
            //  car la ressource visée peut disparaître ensuite.
            $table->string('label');
            // Contexte/diff utile — JAMAIS de secret.
            $table->json('properties')->nullable();
            $table->string('ip')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};
