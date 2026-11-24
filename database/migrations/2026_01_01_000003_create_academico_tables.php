<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración semestral, laboratorios, horario base y reservas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratorios', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nombre', 60);
            $table->integer('capacidad');
            $table->string('ubicacion', 100)->nullable();
            $table->string('estado', 15)->default('DISPONIBLE');
            $table->decimal('tarifa_hora', 10, 2)->default(0); // costo de alquiler para eventos externos
            $table->timestamps();
        });
        DB::statement("ALTER TABLE laboratorios ADD CONSTRAINT ck_lab_capacidad CHECK (capacidad > 0)");
        DB::statement("ALTER TABLE laboratorios ADD CONSTRAINT ck_lab_estado CHECK (estado IN ('DISPONIBLE','MANTENIMIENTO','INHABILITADO'))");

        Schema::create('semestres', function (Blueprint $table) {
            $table->id();
            $table->string('gestion', 10)->unique(); // ej: 2-2026
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('estado', 12)->default('PLANIFICADO');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE semestres ADD CONSTRAINT ck_sem_fechas CHECK (fecha_fin > fecha_inicio)");
        DB::statement("ALTER TABLE semestres ADD CONSTRAINT ck_sem_estado CHECK (estado IN ('PLANIFICADO','ACTIVO','CERRADO'))");
        // Solo un semestre activo a la vez
        DB::statement("CREATE UNIQUE INDEX ux_semestre_activo ON semestres (estado) WHERE estado = 'ACTIVO'");

        Schema::create('fechas_bloqueadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semestre_id')->constrained('semestres')->cascadeOnDelete();
            $table->date('fecha');
            $table->string('tipo', 15);
            $table->string('motivo', 200)->nullable();
            $table->foreignId('laboratorio_id')->nullable()->constrained('laboratorios'); // null = todos
            $table->timestamps();
        });
        DB::statement("ALTER TABLE fechas_bloqueadas ADD CONSTRAINT ck_feb_tipo CHECK (tipo IN ('FERIADO','PSA','MANTENIMIENTO','OTRO'))");

        Schema::create('materias', function (Blueprint $table) {
            $table->id();
            $table->string('sigla', 10)->unique();
            $table->string('nombre', 100);
            $table->timestamps();
        });

        Schema::create('horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semestre_id')->constrained('semestres');
            $table->foreignId('laboratorio_id')->constrained('laboratorios');
            $table->foreignId('materia_id')->constrained('materias');
            $table->foreignId('docente_id')->constrained('usuarios');
            $table->string('grupo', 5);
            $table->smallInteger('dia_semana'); // 1=lunes ... 6=sábado
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE horarios ADD CONSTRAINT ck_hor_dia CHECK (dia_semana BETWEEN 1 AND 6)");
        DB::statement("ALTER TABLE horarios ADD CONSTRAINT ck_hor_horas CHECK (hora_fin > hora_inicio)");

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratorio_id')->constrained('laboratorios');
            $table->foreignId('solicitante_id')->constrained('usuarios');
            $table->date('fecha');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->string('tipo', 15);
            $table->string('motivo', 200);
            $table->string('estado', 10)->default('PENDIENTE');
            $table->foreignId('resuelto_por')->nullable()->constrained('usuarios');
            $table->string('observacion', 200)->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_horas CHECK (hora_fin > hora_inicio)");
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_tipo CHECK (tipo IN ('EXAMEN','RECUPERACION','PRACTICA','EVENTO'))");
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_estado CHECK (estado IN ('PENDIENTE','APROBADA','RECHAZADA','CANCELADA'))");
        DB::statement("CREATE INDEX ix_reservas_lab_fecha ON reservas (laboratorio_id, fecha)");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('horarios');
        Schema::dropIfExists('materias');
        Schema::dropIfExists('fechas_bloqueadas');
        Schema::dropIfExists('semestres');
        Schema::dropIfExists('laboratorios');
    }
};
