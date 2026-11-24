<?php

namespace App\Services\Pagos;

use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Conector PagoFácil (Bolivia) — cobro por QR.
 *
 * ⚠ COMPLETAR CON LA DOCUMENTACIÓN OFICIAL al recibir las credenciales.
 *   Las rutas (/login, /generar-qr, /consultar) y los nombres de campos marcados con "TODO"
 *   son provisionales: ajústelos a lo que indique PagoFácil. Todo lo demás del sistema
 *   (transacciones, cuotas, callback, pantalla del QR, consulta de estado) ya funciona y no cambia.
 *
 * Variables .env:  PAGOS_PASARELA=pagofacil, PAGOFACIL_URL, PAGOFACIL_TOKEN_SERVICIO,
 *                  PAGOFACIL_TOKEN_SECRETO, PAGOFACIL_COMERCIO, PAGOFACIL_CALLBACK
 */
class PagoFacilQr implements PasarelaQr
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('pagos.pagofacil');
    }

    public function nombre(): string
    {
        return 'pagofacil';
    }

    /** Cliente HTTP autenticado (el token de acceso se guarda en caché mientras sea válido) */
    private function http(): PendingRequest
    {
        if (! $this->cfg['url'] || ! $this->cfg['token_servicio'] || ! $this->cfg['token_secreto']) {
            throw new RuntimeException('PagoFácil no está configurado: complete PAGOFACIL_URL y los tokens en .env, o use PAGOS_PASARELA=simulada.');
        }
        $token = Cache::remember('pagofacil_token', now()->addMinutes(50), function () {
            $r = Http::baseUrl($this->cfg['url'])->acceptJson()->timeout(20)
                ->post('/login', [ // TODO: ruta y campos de autenticación según la documentación
                    'tokenServicio' => $this->cfg['token_servicio'],
                    'tokenSecret' => $this->cfg['token_secreto'],
                ])->throw()->json();

            return $r['values']['accessToken'] ?? $r['token'] ?? throw new RuntimeException('PagoFácil no devolvió token de acceso.'); // TODO
        });

        return Http::baseUrl($this->cfg['url'])->acceptJson()->timeout(20)->withToken($token);
    }

    public function generarQr(Transaccion $trx, User $usuario): array
    {
        $r = $this->http()->post('/generar-qr', [ // TODO: ruta y campos según la documentación
            'comercio' => $this->cfg['comercio'],
            'numeroPago' => $trx->codigo,           // nuestro identificador (TRX-000123)
            'monto' => (float) $trx->monto,
            'moneda' => 'BOB',
            'descripcion' => $trx->descripcion,
            'nombreCliente' => $usuario->nombre_completo,
            'correo' => $usuario->email,
            'telefono' => $usuario->telefono,
            'urlCallback' => $this->cfg['callback'],
        ])->throw()->json();

        return [
            'referencia' => (string) ($r['values']['transactionId'] ?? $r['transaccion'] ?? $trx->codigo), // TODO
            'qr' => (string) ($r['values']['qrBase64'] ?? $r['qr'] ?? ''),                                // TODO
            'es_imagen' => true, // las pasarelas bolivianas suelen devolver la imagen del QR en base64
        ];
    }

    public function consultar(Transaccion $trx): string
    {
        $r = $this->http()->post('/consultar', ['transaccion' => $trx->referencia_externa])->throw()->json(); // TODO

        $estado = strtoupper((string) ($r['values']['estado'] ?? $r['estado'] ?? '')); // TODO: valores reales

        return match (true) {
            in_array($estado, ['PAGADO', 'COMPLETADO', 'APROBADO', '2'], true) => 'PAGADO',
            in_array($estado, ['ANULADO', 'RECHAZADO', 'FALLIDO', '3'], true) => 'FALLIDO',
            default => 'PENDIENTE',
        };
    }

    public function leerCallback(Request $request): ?array
    {
        // TODO: si PagoFácil firma el callback, validar aquí la firma con el token secreto.
        $ref = $request->input('transactionId') ?? $request->input('transaccion') ?? $request->input('PedidoID');
        if (! $ref) {
            return null;
        }
        $estado = strtoupper((string) ($request->input('estado') ?? $request->input('Estado') ?? 'PAGADO'));

        return [
            'referencia' => (string) $ref,
            'estado' => in_array($estado, ['PAGADO', 'COMPLETADO', 'APROBADO', '2'], true) ? 'PAGADO' : 'FALLIDO',
            'autorizacion' => $request->input('autorizacion') ?? $request->input('codigoAutorizacion'),
        ];
    }
}
