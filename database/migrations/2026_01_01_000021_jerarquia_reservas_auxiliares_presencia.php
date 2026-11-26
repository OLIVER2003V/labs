<?php

use App\Support\Encargado;
use App\Support\Prioridad;
use App\Support\Triggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU4 - Jerarquía de reservas, auxiliares asignados a una reserva y aprobación automática. CU5 - "En línea / última vez".
 *
 *  - Tipos de reserva según la jerarquía (App\Support\Prioridad). Los tipos retirados se convierten:
 *    RECUPERACION y DEFENSA → EXAMEN · EVENTO → CONFERENCIA.
 *  - reservas.auxiliares_solicitados + tabla reserva_auxiliares (quién apoya cada reserva; asignado_por NULL = automático).
 *  - configuracion: ajustes que cambia el Encargado (ej: aprobación automática de reservas de docentes y director).
 *  - presencia: última vez que cada usuario usó el sistema (para el chat). No se audita: cambia a cada rato.
 *  - Permiso reservas.pedir_auxiliares (Docente y Director). El Encargado deja de entregar objetos y asignar turnos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->smallInteger('auxiliares_solicitados')->default(0);
        });
        DB::statement('ALTER TABLE reservas ADD CONSTRAINT ck_res_auxiliares CHECK (auxiliares_solicitados BETWEEN 0 AND 20)');

        Schema::create('reserva_auxiliares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->foreignId('auxiliar_id')->constrained('usuarios');
            $table->foreignId('asignado_por')->nullable()->constrained('usuarios'); // NULL = lo asignó el sistema (su turno coincide)
            $table->timestamps();
            $table->unique(['reserva_id', 'auxiliar_id']);
        });

        Schema::create('configuracion', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('valor', 200);
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('configuracion')->insert(['clave' => 'reservas.aprobacion_automatica', 'valor' => '0', 'updated_at' => now()]);

        Schema::create('presencia', function (Blueprint $table) {
            $table->foreignId('usuario_id')->primary()->constrained('usuarios')->cascadeOnDelete();
            $table->timestamp('visto_en');
        });

        Triggers::instalar(); // auditoría de las tablas nuevas + el trigger de choques con la jerarquía

        // Tipos retirados → el equivalente de la jerarquía (sin el trigger de choques: los datos ya existían así)
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT ck_res_tipo');
        DB::statement('ALTER TABLE reservas DISABLE TRIGGER tg_reserva_sin_choque');
        DB::table('reservas')->whereIn('tipo', ['RECUPERACION', 'DEFENSA'])->update(['tipo' => 'EXAMEN']);
        DB::table('reservas')->where('tipo', 'EVENTO')->update(['tipo' => 'CONFERENCIA']);
        DB::statement('ALTER TABLE reservas ENABLE TRIGGER tg_reserva_sin_choque');
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_tipo CHECK (tipo IN ('".implode("','", Prioridad::codigos())."'))");

        // Permisos (en una base nueva los crea SeguridadSeeder)
        if (DB::table('permisos')->exists()) {
            DB::table('permisos')->insertOrIgnore(['codigo' => 'reservas.pedir_auxiliares', 'recurso' => 'reservas', 'accion' => 'pedir_auxiliares',
                'descripcion' => 'Pedir auxiliares de apoyo al solicitar una reserva']);
            $permiso = DB::table('permisos')->where('codigo', 'reservas.pedir_auxiliares')->value('id');
            foreach (DB::table('roles')->whereIn('codigo', ['DOC', 'DIR'])->pluck('id') as $rol) {
                DB::table('rol_permiso')->insertOrIgnore(['rol_id' => $rol, 'permiso_id' => $permiso]);
            }
            if (DB::table('roles')->where('codigo', Encargado::ROL)->exists()) {
                Encargado::asegurarRol(); // recibe el permiso nuevo y deja de tener entregar objetos / asignar turnos
            }
        }
    }

    public function down(): void
    {
        DB::table('permisos')->where('codigo', 'reservas.pedir_auxiliares')->delete();
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT ck_res_tipo');
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_tipo CHECK (tipo IN ('EXAMEN','RECUPERACION','PRACTICA','EVENTO','DEFENSA','HACKATHON','CUP','PSA','CAPACITACION','CONFERENCIA'))");
        Schema::dropIfExists('presencia');
        Schema::dropIfExists('configuracion');
        Schema::dropIfExists('reserva_auxiliares');
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT IF EXISTS ck_res_auxiliares');
        Schema::table('reservas', fn (Blueprint $t) => $t->dropColumn('auxiliares_solicitados'));
    }
};
