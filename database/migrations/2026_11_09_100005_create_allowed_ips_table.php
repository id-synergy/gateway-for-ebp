<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IP au débit large/illimité (rate-limit applicatif). Les autres IP sont fortement bridées.
        Schema::create('allowed_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip');                 // IPv4/IPv6 (CIDR éventuel : à décider)
            $table->string('label');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_ips');
    }
};
