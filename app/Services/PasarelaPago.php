<?php

namespace App\Services;

use App\Models\MetodoPago;
use Carbon\Carbon;

/**
 * Pasarela de pagos SIMULADA (no se conecta a ningún banco).
 * Reglas para la demostración:
 *   - Tarjeta que termina en 0002 → RECHAZADA (fondos insuficientes)
 *   - Tarjeta vencida              → RECHAZADA
 *   - Monto mayor a 5000 Bs        → RECHAZADA (excede el límite)
 *   - Cualquier otro caso          → APROBADA con código de autorización
 * Nunca se guarda el número completo de la tarjeta ni el CVV: solo los últimos 4 dígitos y un token.
 */
class PasarelaPago
{
    const LIMITE = 5000;

    /** @return array{aprobada: bool, autorizacion: ?string, mensaje: string} */
    public static function cobrar(MetodoPago $metodo, float $monto): array
    {
        if ($monto > self::LIMITE) {
            return self::rechazo('El monto excede el límite permitido por transacción (Bs '.self::LIMITE.').');
        }
        if ($metodo->tipo === 'TARJETA') {
            if (self::vencida($metodo->vencimiento)) {
                return self::rechazo('La tarjeta está vencida.');
            }
            if ($metodo->ultimos4 === '0002') {
                return self::rechazo('Fondos insuficientes.');
            }
        }

        return ['aprobada' => true, 'autorizacion' => (string) random_int(100000, 999999), 'mensaje' => 'Pago aprobado.'];
    }

    /** Algoritmo de Luhn: valida que el número de tarjeta sea plausible */
    public static function luhn(string $numero): bool
    {
        $suma = 0;
        $alternar = false;
        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $d = (int) $numero[$i];
            if ($alternar) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $suma += $d;
            $alternar = ! $alternar;
        }

        return $suma % 10 === 0;
    }

    public static function marca(string $numero): string
    {
        return match (true) {
            str_starts_with($numero, '4') => 'VISA',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $numero) => 'MASTERCARD',
            (bool) preg_match('/^3[47]/', $numero) => 'AMEX',
            default => 'OTRA',
        };
    }

    /** MM/AA: vencida si el último día de ese mes ya pasó */
    public static function vencida(?string $mmaa): bool
    {
        if (! $mmaa || ! preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $mmaa, $m)) {
            return true;
        }

        return Carbon::create(2000 + (int) $m[2], (int) $m[1], 1)->endOfMonth()->isPast();
    }

    private static function rechazo(string $mensaje): array
    {
        return ['aprobada' => false, 'autorizacion' => null, 'mensaje' => $mensaje];
    }
}
