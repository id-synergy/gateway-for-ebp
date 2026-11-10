<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // État public des jobs exposé aux apps (jobId public, progression, résultat). Résultats conservés 360 j.
        Schema::create('gateway_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();       // jobId rendu à l'app
            $table->enum('type', ['simple', 'compose', 'batch'])->default('simple');
            $table->foreignId('app_token_id')->nullable()->constrained('app_tokens')->nullOnDelete();
            $table->foreignId('ebp_account_id')->nullable()->constrained('ebp_accounts')->nullOnDelete();
            $table->foreignId('ebp_folder_id')->nullable()->constrained('ebp_folders')->nullOnDelete();
            $table->enum('status', ['pending', 'running', 'done', 'partial', 'failed'])->default('pending');
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->string('result_path')->nullable();  // résultat volumineux -> fichier
            $table->json('result_inline')->nullable();   // résultat petit -> base
            $table->json('sub_errors')->nullable();      // sous-jobs en erreur (mode partial)
            $table->uuid('laravel_batch_id')->nullable(); // lien vers le batch Horizon
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_jobs');
    }
};
