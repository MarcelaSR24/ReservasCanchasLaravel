<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isPretending = DB::connection()->pretending();
        $hasReservationsTable = $isPretending || Schema::hasTable('reservas');
        if (! $hasReservationsTable && ! app()->environment('testing')) {
            throw new LogicException('La tabla heredada reservas debe existir antes de crear cobros.');
        }

        Schema::create('cobros', function (Blueprint $table) use ($hasReservationsTable): void {
            $table->id();
            $table->integer('reserva_id')->unique();
            $table->decimal('monto', 12, 2);
            $table->char('moneda', 3)->default('COP');
            $table->string('estado', 20)->default('pendiente');
            $table->string('metodo_pago', 30)->nullable();
            $table->string('referencia_transaccion', 100)->nullable();
            $table->dateTime('fecha_generacion');
            $table->dateTime('fecha_pago')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            if ($hasReservationsTable) {
                $table->foreign('reserva_id')->references('id')->on('reservas');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cobros');
    }
};
