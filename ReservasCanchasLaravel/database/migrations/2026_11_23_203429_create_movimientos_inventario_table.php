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
        Schema::create('movimientos_inventario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('implemento_id')->constrained('implementos');
            $table->foreignId('user_id')->constrained('users');
            $table->string('tipo_movimiento', 10);
            $table->unsignedInteger('cantidad');
            $table->unsignedInteger('cantidad_anterior');
            $table->unsignedInteger('cantidad_posterior');
            $table->string('motivo', 500);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movimientos_inventario');
    }
};
