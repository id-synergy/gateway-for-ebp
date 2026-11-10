<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comptes EBP connectés. Plusieurs comptes possibles ; secrets chiffrés par le cast du modèle.
        Schema::create('ebp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->text('client_id');                    // chiffré (cast)
            $table->text('client_secret');                // chiffré (cast)
            $table->text('subscription_key_primary');     // chiffré (cast)
            $table->text('subscription_key_secondary')->nullable(); // chiffré (cast)
            $table->enum('active_subscription_key', ['primary', 'secondary'])->default('primary');
            $table->enum('status', ['connected', 'disconnected', 'error'])->default('disconnected');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebp_accounts');
    }
};
