<?php

use App\Support\Triggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU9 - Multas y pagos.
 *
 *  multas:   una multa por evento (un atraso, una falta o una reserva no utilizada), con su plazo de pago.
 *            PENDIENTE → PAGADA (QR PagoFácil o tarjeta) · o ANULADA (permiso posterior o decisión del Encargado).
 *  reservas: el auxiliar o el Encargado registra si la reserva se UTILIZÓ o NO se utilizó (esto último genera multa).
 *  conceptos_pago: el monto de cada tipo de multa es un concepto (MULTA-ATRASO, MULTA-FALTA, MULTA-RESERVA),
 *            así el Encargado lo cambia en "Conceptos de cobro" o lo desactiva para dejar de multar ese tipo.
 */
return new class extends Migration
{
    const CONCEPTOS = [
        ['MULTA-ATRASO', 'Multa por atraso del auxiliar a su turno', 10],
        ['MULTA-FALTA', 'Multa por falta injustificada del auxiliar', 50],
        ['MULTA-RESERVA', 'Multa por reserva de laboratorio no utilizada', 20],
    ];
    const PERMISOS = [
        ['multas.ver', 'multas', 'ver', 'Ver y pagar sus multas'],
        ['multas.gestionar', 'multas', 'gestionar', 'Ver las multas de todos y anularlas'],
        ['reservas.registrar_uso', 'reservas', 'registrar_uso', 'Registrar si una reserva se utilizó o no (genera multa)'],
    ];
    const ROLES = [
        'ENC' => ['multas.ver', 'multas.gestionar', 'reservas.registrar_uso'],
        'AUX' => ['multas.ver', 'reservas.registrar_uso'],
        'DOC' => ['multas.ver'],
        'DIR' => ['multas.ver'],
        'EST' => ['multas.ver', 'pagos.ver', 'pagos.pagar'], // el estudiante también puede recibir y pagar una multa por reserva
    ];

    public function up(): void
    {
        Schema::create('multas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->string('tipo', 10);                                   // ATRASO, FALTA, RESERVA
            $table->foreignId('concepto_id')->nullable()->constrained('conceptos_pago');
            $table->decimal('monto', 10, 2);
            $table->string('motivo', 200);
            $table->date('fecha');                                        // día del atraso / falta / reserva
            $table->date('vence_en');                                     // plazo para pagar
            $table->string('estado', 10)->default('PENDIENTE');
            $table->foreignId('asistencia_id')->nullable()->constrained('asistencias')->nullOnDelete();
            $table->foreignId('reserva_id')->nullable()->constrained('reservas')->nullOnDelete();
            $table->timestamp('pagada_en')->nullable();
            $table->foreignId('anulada_por')->nullable()->constrained('usuarios');
            $table->string('observacion', 200)->nullable();
            $table->timestamps();
            $table->index(['usuario_id', 'estado']);
        });
        DB::statement("ALTER TABLE multas ADD CONSTRAINT ck_mul_tipo CHECK (tipo IN ('ATRASO','FALTA','RESERVA'))");
        DB::statement("ALTER TABLE multas ADD CONSTRAINT ck_mul_estado CHECK (estado IN ('PENDIENTE','PAGADA','ANULADA'))");
        DB::statement('ALTER TABLE multas ADD CONSTRAINT ck_mul_monto CHECK (monto > 0)');
        // Un mismo atraso, falta o reserva no puede multarse dos veces
        DB::statement('CREATE UNIQUE INDEX ux_multa_asistencia ON multas (asistencia_id) WHERE asistencia_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ux_multa_reserva ON multas (reserva_id) WHERE reserva_id IS NOT NULL');

        Schema::table('transacciones', function (Blueprint $table) {
            $table->foreignId('multa_id')->nullable()->after('concepto_id')->constrained('multas');
        });

        Schema::table('reservas', function (Blueprint $table) {
            $table->string('uso', 12)->nullable();                         // UTILIZADA, NO_UTILIZADA
            $table->foreignId('uso_registrado_por')->nullable()->constrained('usuarios');
            $table->timestamp('uso_registrado_en')->nullable();
        });
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_uso CHECK (uso IN ('UTILIZADA','NO_UTILIZADA'))");

        foreach (self::CONCEPTOS as [$codigo, $nombre, $monto]) {
            DB::table('conceptos_pago')->insertOrIgnore(['codigo' => $codigo, 'nombre' => $nombre, 'monto' => $monto, 'activo' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }

        Triggers::instalar(); // auditoría de multas

        // Permisos y menú: en una base nueva los crea SeguridadSeeder; aquí llegan a una base que ya tiene datos
        if (DB::table('permisos')->exists()) {
            foreach (self::PERMISOS as [$codigo, $recurso, $accion, $desc]) {
                DB::table('permisos')->insertOrIgnore(['codigo' => $codigo, 'recurso' => $recurso, 'accion' => $accion, 'descripcion' => $desc]);
            }
            $permisos = DB::table('permisos')->pluck('id', 'codigo');
            $roles = DB::table('roles')->pluck('id', 'codigo');
            foreach (array_column(self::PERMISOS, 0) as $codigo) {
                if (isset($roles['ADM'])) {
                    DB::table('rol_permiso')->insertOrIgnore(['rol_id' => $roles['ADM'], 'permiso_id' => $permisos[$codigo]]);
                }
            }
            foreach (self::ROLES as $rol => $lista) {
                foreach ($lista as $codigo) {
                    if (isset($roles[$rol], $permisos[$codigo])) {
                        DB::table('rol_permiso')->insertOrIgnore(['rol_id' => $roles[$rol], 'permiso_id' => $permisos[$codigo]]);
                    }
                }
            }
            if (! DB::table('menus')->where('ruta', '/multas')->exists()) {
                DB::table('menus')->insert(['grupo' => 'Pagos', 'titulo' => 'Multas', 'ruta' => '/multas', 'icono' => 'Gavel',
                    'permiso_codigo' => 'multas.ver', 'orden' => 135, 'activo' => true]);
            }
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('ruta', '/multas')->delete();
        $ids = DB::table('permisos')->whereIn('codigo', array_column(self::PERMISOS, 0))->pluck('id');
        DB::table('rol_permiso')->whereIn('permiso_id', $ids)->delete();
        DB::table('permisos')->whereIn('id', $ids)->delete();
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT IF EXISTS ck_res_uso');
        Schema::table('reservas', fn (Blueprint $t) => $t->dropConstrainedForeignId('uso_registrado_por'));
        Schema::table('reservas', fn (Blueprint $t) => $t->dropColumn(['uso', 'uso_registrado_en']));
        Schema::table('transacciones', fn (Blueprint $t) => $t->dropConstrainedForeignId('multa_id'));
        Schema::dropIfExists('multas');
        DB::table('conceptos_pago')->whereIn('codigo', array_column(self::CONCEPTOS, 0))->whereNotExists(
            fn ($q) => $q->from('transacciones')->whereColumn('transacciones.concepto_id', 'conceptos_pago.id'))->delete();
    }
};
