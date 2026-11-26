<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ajustes del sistema que cambia el Encargado desde la pantalla (tabla configuracion, auditada).
 *   reservas.aprobacion_automatica = 1 → las reservas de docentes y del director se aprueban solas (según la jerarquía)
 */
class Configuracion
{
    const APROBACION_AUTOMATICA = 'reservas.aprobacion_automatica';

    public static function obtener(string $clave, ?string $porDefecto = null): ?string
    {
        return DB::table('configuracion')->where('clave', $clave)->value('valor') ?? $porDefecto;
    }

    public static function activo(string $clave): bool
    {
        return self::obtener($clave, '0') === '1';
    }

    public static function guardar(string $clave, string $valor): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => $clave], ['valor' => $valor, 'updated_at' => now()]);
    }
}
