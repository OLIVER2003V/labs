<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU1 - Registro de estudiantes de la FICCT (ej: auxiliares de cátedra que necesitan reservar laboratorios).
 *  - usuarios.estado admite PENDIENTE: la cuenta se crea así y el Encargado la aprueba.
 *  - datos del registro: carrera y para qué necesita los laboratorios.
 *  - rol EST "Estudiante FICCT" con permisos mínimos (ver laboratorios/disponibilidad, sus reservas).
 */
return new class extends Migration
{
    const PERMISOS_EST = ['laboratorios.ver', 'disponibilidad.ver', 'reservas.ver', 'reservas.crear', 'reservas.cancelar'];

    public function up(): void
    {
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT ck_usuarios_estado');
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_estado CHECK (estado IN ('ACTIVO','INACTIVO','PENDIENTE'))");

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('carrera', 80)->nullable()->after('telefono');
            $table->string('detalle_registro', 300)->nullable()->after('carrera'); // para qué necesita los laboratorios
            $table->foreignId('aprobado_por')->nullable()->after('detalle_registro')->constrained('usuarios')->nullOnDelete();
            $table->timestamp('aprobado_en')->nullable()->after('aprobado_por');
        });

        // El rol se crea aquí (y no solo en el seeder) para que también llegue a una base que ya tiene datos
        // En una base nueva (sin permisos aún) el rol lo crea SeguridadSeeder
        if (DB::table('permisos')->exists() && ! DB::table('roles')->where('codigo', 'EST')->exists()) {
            $rol = DB::table('roles')->insertGetId([
                'codigo' => 'EST', 'nombre' => 'Estudiante FICCT', 'es_sistema' => false,
                'descripcion' => 'Estudiante de la FICCT (ej: auxiliar de cátedra) que solicita reservas de laboratorio',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach (DB::table('permisos')->whereIn('codigo', self::PERMISOS_EST)->pluck('id') as $permiso) {
                DB::table('rol_permiso')->insert(['rol_id' => $rol, 'permiso_id' => $permiso]);
            }
        }
    }

    public function down(): void
    {
        $rol = DB::table('roles')->where('codigo', 'EST')->value('id');
        if ($rol && ! DB::table('usuarios')->where('rol_id', $rol)->exists()) {
            DB::table('rol_permiso')->where('rol_id', $rol)->delete();
            DB::table('roles')->where('id', $rol)->delete();
        }
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprobado_por');
            $table->dropColumn(['carrera', 'detalle_registro', 'aprobado_en']);
        });
        DB::statement("UPDATE usuarios SET estado = 'INACTIVO' WHERE estado = 'PENDIENTE'");
        DB::statement('ALTER TABLE usuarios DROP CONSTRAINT ck_usuarios_estado');
        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
    }
};
