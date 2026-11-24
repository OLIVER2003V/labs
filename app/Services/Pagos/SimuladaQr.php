<?php

namespace App\Services\Pagos;

use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pasarela QR SIMULADA: se comporta como PagoFácil pero sin conectarse a ningún banco.
 *  - generarQr(): crea una referencia y el texto del QR
 *  - el "banco" se simula con POST /api/pagos/simulador/{id}/pagar (botón en la pantalla del QR),
 *    que deja el estado PAGADO en caché, igual que si el cliente hubiera escaneado y pagado
 *  - consultar(): lee ese estado, igual que se consultaría a la pasarela real
 */
class SimuladaQr implements PasarelaQr
{
    public function nombre(): string
    {
        return 'simulada';
    }

    public function generarQr(Transaccion $trx, User $usuario): array
    {
        $ref = 'SIM-'.strtoupper(Str::random(10));
        Cache::put("simulador_qr:$ref", 'PENDIENTE', now()->addDay());

        return [
            'referencia' => $ref,
            'qr' => "PAGOSIMULADO|{$ref}|BOB|{$trx->monto}|{$trx->descripcion}",
            'es_imagen' => false,
        ];
    }

    public function consultar(Transaccion $trx): string
    {
        return Cache::get("simulador_qr:{$trx->referencia_externa}", 'PENDIENTE');
    }

    public function leerCallback(Request $request): ?array
    {
        return null; // la simulación no usa callback: se confirma por consulta de estado
    }

    /** Lo que haría la app del banco al pagar el QR */
    public static function simularPagoDelBanco(string $referencia): void
    {
        Cache::put("simulador_qr:$referencia", 'PAGADO', now()->addDay());
    }
}
