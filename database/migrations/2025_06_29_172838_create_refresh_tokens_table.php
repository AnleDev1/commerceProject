<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Token como string largo (64+ caracteres)
            $table->string('token', 128)->unique();

            // ¿El token ha sido revocado?
            $table->boolean('revoked')->default(false);

            // Fecha exacta de expiración 
            $table->timestamp('expires_at')->nullable();

            // Timestamps de Laravel (created_at, updated_at)
            $table->timestamps();

            // Índices para búsquedas rápidas
            $table->index(['token', 'revoked', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
