<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ConceptoPago;
use App\Models\Cuota;
use App\Models\Transaccion;
use App\Services\Pagos\PasarelaQr;
use App\Services\Pagos\SimuladaQr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cobro por QR (PagoFácil / simulado):
 *  1. POST /api/pagos/qr                       → crea la transacción PENDIENTE y devuelve el QR
 *  2. el cliente escanea y paga con la app de su banco
 *  3a. PagoFácil avisa a POST /api/pagos/pagofacil/callback  → se confirma
 *  3b. o la pantalla consulta GET /api/pagos/transacciones/{id}/estado cada 3 s → se confirma
 *  4. al confirmar: transacción APROBADA y cuota PAGADA (el trigger cierra el plan si era la última)
 */
class PagoQrController extends Controller
{
    public function __construct(private PasarelaQr $pasarela) {}

    public function iniciar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'cuota_id' => ['required_without:concepto_id', 'nullable', 'integer'],
            'concepto_id' => ['required_without:cuota_id', 'nullable', 'integer'],
        ], ['cuota_id.required_without' => 'Indique la cuota o el concepto a pagar.']);
        $usuario = $request->user();

        if (! empty($d['cuota_id'])) {
            $cuota = Cuota::with('plan')->findOrFail($d['cuota_id']);
            abort_if($cuota->plan->usuario_id !== $usuario->id, 403, 'Esta cuota no le pertenece.');
            abort_if($cuota->plan->estado !== 'VIGENTE', 422, "El plan está {$cuota->plan->estado}.");
            abort_if($cuota->estado === 'PAGADA', 422, 'Esta cuota ya fue pagada.');
            abort_if(Cuota::where('plan_id', $cuota->plan_id)->where('numero', '<', $cuota->numero)->where('estado', 'PENDIENTE')->exists(),
                422, 'Debe pagar primero las cuotas anteriores.');
            $datos = ['cuota_id' => $cuota->id, 'monto' => $cuota->monto,
                'descripcion' => "Cuota {$cuota->numero}/{$cuota->plan->nro_cuotas} - {$cuota->plan->descripcion}"];
        } else {
            $concepto = ConceptoPago::where('activo', true)->findOrFail($d['concepto_id']);
            $datos = ['concepto_id' => $concepto->id, 'monto' => $concepto->monto, 'descripcion' => $concepto->nombre];
        }

        // Si ya hay un QR vigente para lo mismo, se reutiliza (evita cobrar dos veces)
        $vigente = Transaccion::where('usuario_id', $usuario->id)->where('estado', 'PENDIENTE')
            ->where('pasarela', $this->pasarela->nombre())->where('expira_en', '>', now())
            ->where(fn ($q) => isset($datos['cuota_id']) ? $q->where('cuota_id', $datos['cuota_id']) : $q->where('concepto_id', $datos['concepto_id']))
            ->latest('id')->first();
        if ($vigente) {
            return $this->respuesta($vigente);
        }

        $trx = Transaccion::create($datos + [
            'codigo' => 'TMP-'.Str::random(12), 'usuario_id' => $usuario->id, 'pasarela' => $this->pasarela->nombre(),
            'estado' => 'PENDIENTE', 'mensaje' => 'Esperando el pago por QR.', 'expira_en' => now()->addMinutes(config('pagos.minutos_qr')),
            'descripcion' => substr($datos['descripcion'], 0, 200),
        ]);
        $trx->update(['codigo' => 'TRX-'.str_pad((string) $trx->id, 6, '0', STR_PAD_LEFT)]);

        try {
            $qr = $this->pasarela->generarQr($trx, $usuario);
        } catch (Throwable $e) {
            report($e);
            $trx->update(['estado' => 'RECHAZADA', 'mensaje' => 'La pasarela no pudo generar el QR.']);

            return response()->json(['message' => 'No se pudo generar el QR de pago. Intente más tarde.'], 502);
        }
        $trx->update(['referencia_externa' => $qr['referencia'], 'qr_contenido' => ($qr['es_imagen'] ? 'img:' : 'txt:').$qr['qr']]);

        return $this->respuesta($trx->fresh(), 201);
    }

    /** La pantalla del QR consulta esto cada pocos segundos */
    public function estado(Request $request, int $id): JsonResponse
    {
        $trx = Transaccion::findOrFail($id);
        abort_if($trx->usuario_id !== $request->user()->id && ! $request->user()->tienePermiso('pagos.ver_todos'), 403, 'Transacción ajena.');

        if ($trx->estado === 'PENDIENTE' && $trx->pasarela === $this->pasarela->nombre()) {
            if ($trx->expira_en && $trx->expira_en->isPast()) {
                $trx->update(['estado' => 'EXPIRADA', 'mensaje' => 'El QR venció sin recibir el pago.']);
            } else {
                try {
                    match ($this->pasarela->consultar($trx)) {
                        'PAGADO' => self::confirmar($trx, null),
                        'FALLIDO' => $trx->update(['estado' => 'RECHAZADA', 'mensaje' => 'La pasarela informó que el pago falló.']),
                        default => null,
                    };
                } catch (Throwable $e) {
                    report($e); // si la pasarela no responde, se sigue esperando
                }
            }
        }

        return $this->respuesta($trx->fresh());
    }

    /** SOLO en modo simulado: hace de "app del banco" que paga el QR */
    public function simularPago(Request $request, int $id): JsonResponse
    {
        abort_unless($this->pasarela instanceof SimuladaQr, 404, 'El simulador solo existe con PAGOS_PASARELA=simulada.');
        $trx = Transaccion::where('usuario_id', $request->user()->id)->findOrFail($id);
        abort_if($trx->estado !== 'PENDIENTE', 422, "La transacción está {$trx->estado}.");
        SimuladaQr::simularPagoDelBanco($trx->referencia_externa);

        return response()->json(['message' => 'El banco simulado registró el pago. Confirmando…']);
    }

    /** Aviso de PagoFácil (webhook). Ruta pública: no lleva token del usuario. */
    public function callback(Request $request): JsonResponse
    {
        $aviso = $this->pasarela->leerCallback($request);
        Bitacora::create([
            'evento' => 'ACCESO', 'metodo' => 'POST', 'recurso' => 'pagofacil', 'ruta' => 'api/pagos/pagofacil/callback',
            'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'codigo_http' => $aviso ? 200 : 422, 'detalle' => substr('Callback: '.json_encode($request->all()), 0, 300),
        ]);
        if (! $aviso) {
            return response()->json(['error' => 1, 'message' => 'Aviso inválido.'], 422);
        }

        $trx = Transaccion::where('pasarela', $this->pasarela->nombre())->where('referencia_externa', $aviso['referencia'])->first()
            ?? Transaccion::where('codigo', $aviso['referencia'])->first();
        if (! $trx) {
            return response()->json(['error' => 1, 'message' => 'Transacción no encontrada.'], 404);
        }
        if ($aviso['estado'] === 'PAGADO') {
            self::confirmar($trx, $aviso['autorizacion']);
        } elseif ($trx->estado === 'PENDIENTE') {
            $trx->update(['estado' => 'RECHAZADA', 'mensaje' => 'La pasarela informó que el pago falló.']);
        }

        return response()->json(['error' => 0, 'status' => 1, 'message' => 'Recibido.']);
    }

    /** Confirma un pago una sola vez (con bloqueo, por si llegan el callback y la consulta a la vez) */
    public static function confirmar(Transaccion $trx, ?string $autorizacion): void
    {
        DB::transaction(function () use ($trx, $autorizacion) {
            $t = Transaccion::lockForUpdate()->find($trx->id);
            if ($t->estado !== 'PENDIENTE') {
                return; // ya procesada
            }
            $mensaje = 'Pago QR confirmado por la pasarela.';
            if ($t->cuota_id) {
                $cuota = Cuota::lockForUpdate()->find($t->cuota_id);
                if ($cuota->estado === 'PAGADA') {
                    $mensaje = 'Pago recibido, pero la cuota ya estaba pagada: corresponde devolución.';
                } else {
                    $cuota->update(['estado' => 'PAGADA', 'pagado_en' => now()]); // el trigger cierra el plan si era la última
                }
            }
            $t->update(['estado' => 'APROBADA', 'autorizacion' => $autorizacion ?? (string) random_int(100000, 999999), 'mensaje' => $mensaje]);
        });
    }

    private function respuesta(Transaccion $trx, int $codigo = 200): JsonResponse
    {
        [$tipo, $qr] = array_pad(explode(':', (string) $trx->qr_contenido, 2), 2, null);

        return response()->json([
            'message' => match ($trx->estado) {
                'APROBADA' => "Pago aprobado. Transacción {$trx->codigo}, autorización {$trx->autorizacion}.",
                'PENDIENTE' => 'Escanee el QR con la app de su banco para pagar.',
                default => $trx->mensaje,
            },
            'data' => $trx->makeHidden('qr_contenido'),
            'qr' => $qr, 'qr_es_imagen' => $tipo === 'img',
            'pasarela' => $trx->pasarela, 'simulada' => $this->pasarela instanceof SimuladaQr,
        ], $codigo);
    }
}
