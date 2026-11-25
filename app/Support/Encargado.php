<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * El Encargado de Laboratorios es el ADMINISTRADOR del sistema (no hay un rol "Administrador" aparte):
 *  - su rol (ENC) es "de sistema": tiene TODOS los permisos y no se puede modificar ni eliminar;
 *  - hay un único Encargado principal, definido en .env (ENCARGADO_PRINCIPAL = su correo).
 * Lo usan la migración 000020, los seeders y el kit de recuperación (sistema:verificar / sistema:reparar).
 */
class Encargado
{
    const ROL = 'ENC';
    const DESCRIPCION = 'Administra todo el sistema: usuarios, roles y permisos, laboratorios, horarios, reservas, inventario, '
        .'auxiliares, cobros, bitácora y auditoría';

    public static function email(): string
    {
        return strtolower((string) config('app.encargado_principal'));
    }

    public static function rolId(): ?int
    {
        return DB::table('roles')->where('codigo', self::ROL)->value('id');
    }

    /** El rol Encargado: de sistema y con todos los permisos (los nuevos permisos también le llegan) */
    public static function asegurarRol(): int
    {
        DB::table('roles')->updateOrInsert(['codigo' => self::ROL],
            ['nombre' => 'Encargado de Laboratorios', 'descripcion' => self::DESCRIPCION, 'es_sistema' => true, 'updated_at' => now()]);
        $rol = self::rolId();
        DB::table('rol_permiso')->insertOrIgnore(DB::table('permisos')->pluck('id')->map(fn ($p) => ['rol_id' => $rol, 'permiso_id' => $p])->all());

        return $rol;
    }

    /**
     * Deja un solo Encargado: el principal. Los demás Encargados se eliminan con BORRADO LÓGICO
     * (no pueden entrar, pero su historial se conserva y se pueden restaurar desde Usuarios → eliminados).
     * Si el principal no existe en esta base, no se toca nada (por seguridad).
     * @return int cuántas cuentas se eliminaron
     */
    public static function dejarSoloElPrincipal(): int
    {
        $rol = self::rolId();
        $principal = DB::table('usuarios')->where('email', self::email())->whereNull('deleted_at')->value('id');
        if (! $rol || ! $principal) {
            return 0;
        }
        DB::table('usuarios')->where('id', $principal)->update(['rol_id' => $rol, 'estado' => 'ACTIVO', 'updated_at' => now()]);
        $otros = DB::table('usuarios')->where('rol_id', $rol)->where('id', '<>', $principal)->whereNull('deleted_at')->pluck('id');
        DB::table('personal_access_tokens')->whereIn('tokenable_id', $otros)->where('tokenable_type', 'App\\Models\\User')->delete();
        DB::table('usuarios')->whereIn('id', $otros)->update(['deleted_at' => now(), 'updated_at' => now()]);

        return $otros->count();
    }
}
