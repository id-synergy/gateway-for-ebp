<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table unique de gestion des IP :
        //  - status=allowed  -> exemptée du rate-limit applicatif (débit illimité) ;
        //  - status=blocked  -> rejetée en 403 par le middleware BlockBannedIps ;
        //  - status=neutral  -> simplement nommée (label), ni autorisée ni bloquée.
        // Le label survit aux changements de statut.
        Schema::create('ip_entries', function (Blueprint $table) {
            $table->id();
            $table->string('ip');                 // IPv4/IPv6
            $table->string('label')->nullable();
            $table->string('status')->default('neutral'); // allowed | blocked | neutral
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('ip');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_entries');
    }
};
