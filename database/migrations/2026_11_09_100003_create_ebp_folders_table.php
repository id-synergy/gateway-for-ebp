<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dossiers d'un compte EBP (un compte -> un ou plusieurs dossiers).
        Schema::create('ebp_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ebp_account_id')->constrained('ebp_accounts')->cascadeOnDelete();
            $table->string('folder_id');            // FolderId EBP (ex. "3691")
            $table->string('short_name')->nullable(); // ex. "INV40"
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['ebp_account_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebp_folders');
    }
};
