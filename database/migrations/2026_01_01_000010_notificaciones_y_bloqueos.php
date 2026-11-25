<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - notificaciones: avisos dentro del sistema (campanita del encabezado). Ej: "Su reserva fue cancelada por un feriado".
 * - Fechas bloqueadas sin duplicados: una misma fecha no puede bloquearse dos veces para el mismo laboratorio
 *   (o dos veces para "todos"). COALESCE convierte el NULL de "todos" en 0 para que el índice lo compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('titulo', 150);
            $table->string('mensaje', 600);
            $table->string('url', 150)->nullable();          // pantalla a la que lleva al hacer clic (ej: /reservas)
            $table->foreignId('reserva_id')->nullable()->constrained('reservas')->nullOnDelete();
            $table->timestamp('leida_en')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['usuario_id', 'leida_en']);
        });

        DB::statement('CREATE UNIQUE INDEX ux_fecha_bloqueada ON fechas_bloqueadas (fecha, COALESCE(laboratorio_id, 0))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_fecha_bloqueada');
        Schema::dropIfExists('notificaciones');
    }
};
