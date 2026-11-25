<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CU6 - Configuración TCP/IP de un equipo (1 a 1 con equipos).
 * Ej: IP 172.20.172.5 · máscara 255.255.255.0 · gateway 172.20.172.254 · DNS 10.10.0.251 · proxy 172.20.172.254:3128
 */
class EquipoRed extends Model
{
    protected $table = 'equipos_red';

    protected $fillable = ['equipo_id', 'ip', 'mascara', 'gateway', 'dns1', 'dns2', 'proxy', 'grupo_red'];

    public function equipo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Equipo::class, 'equipo_id');
    }

    /** ¿Es una máscara válida? (unos seguidos de ceros: 255.255.255.0 sí, 255.0.255.0 no) */
    public static function mascaraValida(string $mascara): bool
    {
        $n = ip2long($mascara);
        if ($n === false || $n === 0) {
            return false;
        }
        $invertida = ~$n & 0xFFFFFFFF;

        return ($invertida & ($invertida + 1)) === 0; // los ceros del final forman un bloque continuo
    }

    /** ¿Están las dos IP en la misma subred? (ej: 172.20.172.5 y 172.20.172.254 con /24 → sí) */
    public static function mismaSubred(string $ip1, string $ip2, string $mascara): bool
    {
        $m = ip2long($mascara);

        return (ip2long($ip1) & $m) === (ip2long($ip2) & $m);
    }
}
