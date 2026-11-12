<?php

use App\Models\AppToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Règles d'accès d'un jeton d'app : liste blanche de routes EBP relayables.
        //
        // Sémantique (défaut OUVERT) : un jeton SANS aucune règle garde l'accès au périmètre
        // global (allowed_prefixes / EbpPath). Un jeton AVEC au moins une règle est restreint
        // à ces règles uniquement (en intersection avec le périmètre global : une règle ne peut
        // pas élargir au-delà de ce que la gateway autorise déjà).
        Schema::create('app_token_route_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(AppToken::class)->constrained()->cascadeOnDelete();
            // Verbes HTTP autorisés par la règle, ex. ["GET"] (lecture) ou ["GET","POST","PUT","PATCH","DELETE"].
            $table->json('methods');
            // Motif de chemin EBP, ex. "gescom/api/v1/Folders/*/Items/*".
            // '*' = un segment ; '**' = le reste du chemin (zéro ou plusieurs segments).
            $table->string('pattern');
            // Ordre d'affichage (les règles sont en OU : l'ordre n'influe pas sur le matching).
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['app_token_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_token_route_rules');
    }
};
