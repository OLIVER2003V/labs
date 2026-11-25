<?php

use App\Support\Encargado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El Encargado de Laboratorios pasa a ser el administrador del sistema:
 *  1. El rol Encargado recibe TODOS los permisos y queda como rol de sistema (protegido).
 *  2. Se elimina el rol "Administrador": sus cuentas pasan a Encargado y se eliminan (borrado lógico).
 *  3. Queda un solo Encargado, el principal (ENCARGADO_PRINCIPAL en .env); los demás se eliminan (borrado lógico).
 * En una base nueva (sin roles todavía) no hace nada: lo arma SeguridadSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('roles')->exists()) {
            return;
        }
        $enc = Encargado::asegurarRol();

        $adm = DB::table('roles')->where('codigo', 'ADM')->value('id');
        if ($adm) {
            $cuentas = DB::table('usuarios')->where('rol_id', $adm)->pluck('id');
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $cuentas)->where('tokenable_type', 'App\\Models\\User')->delete();
            DB::table('usuarios')->whereIn('id', $cuentas)
                ->update(['rol_id' => $enc, 'deleted_at' => DB::raw('COALESCE(deleted_at, CURRENT_TIMESTAMP)'), 'updated_at' => now()]);
            DB::table('rol_permiso')->where('rol_id', $adm)->delete();
            DB::table('roles')->where('id', $adm)->delete();
        }

        Encargado::dejarSoloElPrincipal();
    }

    public function down(): void
    {
        // Vuelve a crear el rol Administrador con todos los permisos (las cuentas eliminadas se restauran desde Usuarios).
        $id = DB::table('roles')->insertGetId(['codigo' => 'ADM', 'nombre' => 'Administrador del Sistema',
            'descripcion' => 'Administra usuarios, roles, permisos y la seguridad del sistema', 'es_sistema' => true,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('rol_permiso')->insertOrIgnore(DB::table('permisos')->pluck('id')->map(fn ($p) => ['rol_id' => $id, 'permiso_id' => $p])->all());
        DB::table('roles')->where('codigo', 'ENC')->update(['es_sistema' => false]);
    }
};
