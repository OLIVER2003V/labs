<?php

use App\Support\Encargado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU9 - Quién paga y quién consulta las multas:
 *  - Pagan solo Docente y Auxiliar.
 *  - El Director consulta las multas y los pagos de todos (permiso nuevo multas.ver_todas) y ya no paga.
 *  - El Encargado consulta y anula, y tampoco paga (pagos.pagar pasa a Encargado::EXCLUIDOS).
 *  - El Estudiante no tiene multas ni pagos.
 * Las multas PENDIENTES que tuvieran directores o estudiantes se anulan (ya no pueden pagarlas y les bloquearían las reservas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('permisos')->exists()) {
            return; // base nueva: lo crea SeguridadSeeder
        }
        DB::table('permisos')->insertOrIgnore(['codigo' => 'multas.ver_todas', 'recurso' => 'multas', 'accion' => 'ver_todas',
            'descripcion' => 'Consultar las multas de todos (sin anular)']);
        $permiso = fn ($c) => DB::table('permisos')->where('codigo', $c)->value('id');
        $rol = fn ($c) => DB::table('roles')->where('codigo', $c)->value('id');

        if ($dir = $rol('DIR')) {
            DB::table('rol_permiso')->insertOrIgnore(['rol_id' => $dir, 'permiso_id' => $permiso('multas.ver_todas')]);
            DB::table('rol_permiso')->where('rol_id', $dir)->where('permiso_id', $permiso('pagos.pagar'))->delete();
        }
        if ($est = $rol('EST')) {
            DB::table('rol_permiso')->where('rol_id', $est)
                ->whereIn('permiso_id', DB::table('permisos')->whereIn('codigo', ['multas.ver', 'pagos.ver', 'pagos.pagar'])->pluck('id'))->delete();
        }
        if ($rol(Encargado::ROL)) {
            Encargado::asegurarRol(); // recibe multas.ver_todas y pierde pagos.pagar
        }

        $usuarios = DB::table('usuarios')->whereIn('rol_id', array_filter([$dir, $est]))->pluck('id');
        DB::table('multas')->whereIn('usuario_id', $usuarios)->where('estado', 'PENDIENTE')
            ->update(['estado' => 'ANULADA', 'observacion' => 'Anulada: los directores y estudiantes no pagan multas.']);
    }

    public function down(): void
    {
        DB::table('permisos')->where('codigo', 'multas.ver_todas')->delete();
    }
};
