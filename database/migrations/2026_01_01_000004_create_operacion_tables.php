<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operación diaria: inventario, incidencias, objetos encontrados, turnos y asistencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->foreignId('laboratorio_id')->constrained('laboratorios');
            $table->string('tipo', 15);
            $table->string('descripcion', 200);
            $table->string('estado', 15)->default('OPERATIVO');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE equipos ADD CONSTRAINT ck_equ_tipo CHECK (tipo IN ('COMPUTADORA','PROYECTOR','COMPONENTE','SOFTWARE','OTRO'))");
        DB::statement("ALTER TABLE equipos ADD CONSTRAINT ck_equ_estado CHECK (estado IN ('OPERATIVO','EN_REPARACION','BAJA'))");

        Schema::create('incidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('equipos');
            $table->foreignId('reportado_por')->constrained('usuarios');
            $table->string('descripcion', 300);
            $table->string('estado', 12)->default('PENDIENTE');
            $table->string('solucion', 300)->nullable();
            $table->timestamp('fecha_solucion')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE incidencias ADD CONSTRAINT ck_inc_estado CHECK (estado IN ('PENDIENTE','EN_ATENCION','RESUELTA'))");

        Schema::create('objetos_encontrados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laboratorio_id')->constrained('laboratorios');
            $table->string('descripcion', 200);
            $table->date('fecha_hallazgo');
            $table->foreignId('registrado_por')->constrained('usuarios');
            $table->string('estado', 12)->default('EN_CUSTODIA');
            $table->string('entregado_a', 100)->nullable();
            $table->string('ci_receptor', 15)->nullable();
            $table->timestamp('fecha_entrega')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE objetos_encontrados ADD CONSTRAINT ck_obj_estado CHECK (estado IN ('EN_CUSTODIA','ENTREGADO'))");

        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('semestre_id')->constrained('semestres');
            $table->foreignId('auxiliar_id')->constrained('usuarios');
            $table->foreignId('laboratorio_id')->nullable()->constrained('laboratorios');
            $table->smallInteger('dia_semana');
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE turnos ADD CONSTRAINT ck_tur_dia CHECK (dia_semana BETWEEN 1 AND 6)");
        DB::statement("ALTER TABLE turnos ADD CONSTRAINT ck_tur_horas CHECK (hora_fin > hora_inicio)");

        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos');
            $table->foreignId('auxiliar_id')->constrained('usuarios');
            $table->date('fecha');
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->string('estado', 10);
            $table->string('observacion', 200)->nullable();
            $table->timestamps();
            $table->unique(['turno_id', 'fecha']);
        });
        DB::statement("ALTER TABLE asistencias ADD CONSTRAINT ck_asi_estado CHECK (estado IN ('PUNTUAL','ATRASO','FALTA','PERMISO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
        Schema::dropIfExists('turnos');
        Schema::dropIfExists('objetos_encontrados');
        Schema::dropIfExists('incidencias');
        Schema::dropIfExists('equipos');
    }
};
