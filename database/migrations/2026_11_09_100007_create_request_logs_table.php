<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Métadonnées des appels (360 j). Le corps de réponse volumineux est en fichier (response_body_path).
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->foreignId('app_token_id')->nullable()->constrained('app_tokens')->nullOnDelete();
            $table->foreignId('ebp_account_id')->nullable()->constrained('ebp_accounts')->nullOnDelete();
            $table->foreignId('ebp_folder_id')->nullable()->constrained('ebp_folders')->nullOnDelete();
            $table->foreignId('gateway_job_id')->nullable()->constrained('gateway_jobs')->nullOnDelete();
            $table->string('ip')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('gateway_route')->nullable();
            $table->string('ebp_endpoint')->nullable();
            $table->json('params')->nullable();          // sans secrets
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedBigInteger('response_size')->nullable();
            $table->string('response_body_path')->nullable();
            $table->timestamps();

            $table->index(['ebp_account_id', 'occurred_at']);
            $table->index('http_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
