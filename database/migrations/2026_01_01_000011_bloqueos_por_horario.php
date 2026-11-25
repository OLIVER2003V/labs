<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloqueos por horario: la PSA o el CUP ocupan algunos laboratorios solo en ciertas horas
 * (ej: 08:00–12:00); fuera de ese horario el laboratorio sigue disponible para reservas.
 *  - hora_inicio / hora_fin opcionales (NULL = todo el día)
 *  - nuevo tipo CUP (Curso Preuniversitario)
 *  - el índice anti-duplicados ahora también considera la hora de inicio
 *    (el mismo día puede tener la PSA en la mañana y el CUP en la tarde)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechas_bloqueadas', function (Blueprint $table) {
            $table->time('hora_inicio')->nullable()->after('fecha');
            $table->time('hora_fin')->nullable()->after('hora_inicio');
        });
        DB::statement("ALTER TABLE fechas_bloqueadas ADD CONSTRAINT ck_feb_horas CHECK ((hora_inicio IS NULL AND hora_fin IS NULL) OR (hora_inicio IS NOT NULL AND hora_fin > hora_inicio))");
        DB::statement('ALTER TABLE fechas_bloqueadas DROP CONSTRAINT ck_feb_tipo');
        DB::statement("ALTER TABLE fechas_bloqueadas ADD CONSTRAINT ck_feb_tipo CHECK (tipo IN ('FERIADO','PSA','CUP','MANTENIMIENTO','OTRO'))");
        DB::statement('DROP INDEX IF EXISTS ux_fecha_bloqueada');
        DB::statement("CREATE UNIQUE INDEX ux_fecha_bloqueada ON fechas_bloqueadas (fecha, COALESCE(laboratorio_id, 0), COALESCE(hora_inicio, '00:00'::time))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_fecha_bloqueada');
        DB::statement("DELETE FROM fechas_bloqueadas WHERE hora_inicio IS NOT NULL OR tipo = 'CUP'");
        DB::statement('CREATE UNIQUE INDEX ux_fecha_bloqueada ON fechas_bloqueadas (fecha, COALESCE(laboratorio_id, 0))');
        DB::statement('ALTER TABLE fechas_bloqueadas DROP CONSTRAINT ck_feb_tipo');
        DB::statement("ALTER TABLE fechas_bloqueadas ADD CONSTRAINT ck_feb_tipo CHECK (tipo IN ('FERIADO','PSA','MANTENIMIENTO','OTRO'))");
        DB::statement('ALTER TABLE fechas_bloqueadas DROP CONSTRAINT ck_feb_horas');
        Schema::table('fechas_bloqueadas', fn (Blueprint $t) => $t->dropColumn(['hora_inicio', 'hora_fin']));
    }
};
