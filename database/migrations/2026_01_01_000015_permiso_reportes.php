<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU8 - Permiso "reportes.ver" (Encargado, Director y Administrador) y opción "Reportes" en el menú.
 * En una base nueva los crea SeguridadSeeder; aquí llegan a una base que ya tiene datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('permisos')->exists()) {
            return;
        }
        DB::table('permisos')->insertOrIgnore(['codigo' => 'reportes.ver', 'recurso' => 'reportes', 'accion' => 'ver',
            'descripcion' => 'Ver y exportar los reportes (ocupación, reservas, incidencias, asistencia, semestre)']);
        $permiso = DB::table('permisos')->where('codigo', 'reportes.ver')->value('id');
        foreach (DB::table('roles')->whereIn('codigo', ['ADM', 'ENC', 'DIR'])->pluck('id') as $rol) {
            DB::table('rol_permiso')->insertOrIgnore(['rol_id' => $rol, 'permiso_id' => $permiso]);
        }
        if (! DB::table('menus')->where('ruta', '/reportes')->exists()) {
            DB::table('menus')->insert(['grupo' => 'General', 'titulo' => 'Reportes', 'ruta' => '/reportes', 'icono' => 'FileSpreadsheet',
                'permiso_codigo' => 'reportes.ver', 'orden' => 22, 'activo' => true]);
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('ruta', '/reportes')->delete();
        $id = DB::table('permisos')->where('codigo', 'reportes.ver')->value('id');
        DB::table('rol_permiso')->where('permiso_id', $id)->delete();
        DB::table('permisos')->where('id', $id)->delete();
    }
};
