<?php

use App\Support\Triggers;
use Database\Seeders\ImportarSislabSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU5 - Permutas temporales de turno y chat interno.
 *
 *  permutas: un auxiliar pide que otro cubra SU turno en UNA fecha.
 *            Flujo: PENDIENTE (solicitó) → ACEPTADA (el reemplazante dijo que sí) → APROBADA (el Encargado autorizó).
 *            En cualquier paso puede quedar RECHAZADA, o CANCELADA si el solicitante la retira.
 *  mensajes: chat privado entre usuarios (auxiliares y Encargado) para coordinar.
 */
return new class extends Migration
{
    const PERMISOS = [
        ['permutas.ver', 'permutas', 'ver', 'Ver sus permutas de turno'],
        ['permutas.solicitar', 'permutas', 'solicitar', 'Solicitar y responder permutas de turno'],
        ['permutas.aprobar', 'permutas', 'aprobar', 'Aprobar o rechazar permutas y ver las de todos'],
        ['mensajes.usar', 'mensajes', 'usar', 'Enviar y recibir mensajes internos'],
    ];
    const ROLES = [
        'ENC' => ['permutas.ver', 'permutas.aprobar', 'mensajes.usar'],
        'AUX' => ['permutas.ver', 'permutas.solicitar', 'mensajes.usar'],
    ];
    const MENU = [
        ['Operación', 'Permutas de turno', '/permutas', 'ArrowLeftRight', 'permutas.ver', 115],
        ['General', 'Mensajes', '/mensajes', 'MessagesSquare', 'mensajes.usar', 25],
    ];

    public function up(): void
    {
        Schema::create('permutas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos');
            $table->foreignId('solicitante_id')->constrained('usuarios');
            $table->foreignId('reemplazante_id')->constrained('usuarios');
            $table->date('fecha');
            $table->string('motivo', 200)->nullable();
            $table->string('estado', 10)->default('PENDIENTE');
            $table->foreignId('aprobado_por')->nullable()->constrained('usuarios');
            $table->string('observacion', 200)->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE permutas ADD CONSTRAINT ck_per_estado CHECK (estado IN ('PENDIENTE','ACEPTADA','APROBADA','RECHAZADA','CANCELADA'))");
        DB::statement('ALTER TABLE permutas ADD CONSTRAINT ck_per_actores CHECK (solicitante_id <> reemplazante_id)');
        // Un turno, en una fecha, solo puede tener una permuta en curso
        DB::statement("CREATE UNIQUE INDEX ux_permuta_turno_fecha ON permutas (turno_id, fecha) WHERE estado IN ('PENDIENTE','ACEPTADA','APROBADA')");

        Schema::create('mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remitente_id')->constrained('usuarios');
            $table->foreignId('destinatario_id')->constrained('usuarios');
            $table->text('contenido');
            $table->timestamp('leido_en')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['destinatario_id', 'leido_en']);
            $table->index(['remitente_id', 'destinatario_id']);
        });

        Triggers::instalar(); // agrega la auditoría de la tabla permutas

        // Permisos y menú: en una base nueva los crea SeguridadSeeder; aquí llegan a una base que ya tiene datos
        if (DB::table('permisos')->exists()) {
            foreach (self::PERMISOS as [$codigo, $recurso, $accion, $desc]) {
                DB::table('permisos')->insertOrIgnore(['codigo' => $codigo, 'recurso' => $recurso, 'accion' => $accion, 'descripcion' => $desc]);
            }
            $permisos = DB::table('permisos')->whereIn('codigo', array_column(self::PERMISOS, 0))->pluck('id', 'codigo');
            $roles = DB::table('roles')->pluck('id', 'codigo');
            foreach ($permisos as $id) {
                DB::table('rol_permiso')->insertOrIgnore(['rol_id' => $roles['ADM'], 'permiso_id' => $id]);
            }
            foreach (self::ROLES as $rol => $lista) {
                foreach ($lista as $codigo) {
                    if (isset($roles[$rol])) {
                        DB::table('rol_permiso')->insertOrIgnore(['rol_id' => $roles[$rol], 'permiso_id' => $permisos[$codigo]]);
                    }
                }
            }
            foreach (self::MENU as [$grupo, $titulo, $ruta, $icono, $permiso, $orden]) {
                if (! DB::table('menus')->where('ruta', $ruta)->exists()) {
                    DB::table('menus')->insert(['grupo' => $grupo, 'titulo' => $titulo, 'ruta' => $ruta, 'icono' => $icono,
                        'permiso_codigo' => $permiso, 'orden' => $orden, 'activo' => true]);
                }
            }

            // Si la base ya tiene los datos reales del proyecto 1, se traen también sus permutas y mensajes
            if (ImportarSislabSeeder::disponible() && DB::table('turnos')->exists()) {
                ImportarSislabSeeder::importarPermutasYMensajes();
            }
        }
    }

    public function down(): void
    {
        DB::table('menus')->whereIn('ruta', array_column(self::MENU, 2))->delete();
        $ids = DB::table('permisos')->whereIn('codigo', array_column(self::PERMISOS, 0))->pluck('id');
        DB::table('rol_permiso')->whereIn('permiso_id', $ids)->delete();
        DB::table('permisos')->whereIn('id', $ids)->delete();
        Schema::dropIfExists('mensajes');
        Schema::dropIfExists('permutas');
    }
};
