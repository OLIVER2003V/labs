<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ConceptoPago;
use App\Models\Cuota;
use App\Models\Multa;
use App\Models\Transaccion;
use App\Services\Pagos\Stripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cobro con tarjeta por Stripe Checkout (cuotas de un plan, multas y pagos únicos).
 *
 *  1. POST /api/pagos/stripe  { cuota_id | multa_id | concepto_id }
 *        → crea la transacción PENDIENTE y devuelve la URL de la página de pago de Stripe
 *  2. el usuario paga en Stripe y vuelve a /pagos/resultado?trx=ID
 *  3. esa pantalla consulta GET /api/pagos/transacciones/{id}/estado → el servidor le pregunta a Stripe
 *  4. además Stripe avisa a POST /api/pagos/stripe/webhook (firma verificada)
 *  Lo que llegue primero (3 o 4) confirma: transacción APROBADA y cuota / multa PAGADA. Nunca se confirma dos veces.
 */
class PagoStripeController extends Controller
{
    public function iniciar(Request $request): JsonResponse
    {
        $d = $request->validate([
            'cuota_id' => ['required_without_all:concepto_id,multa_id', 'nullable', 'integer'],
            'concepto_id' => ['nullable', 'integer'],
            'multa_id' => ['nullable', 'integer'],
        ], ['cuota_id.required_without_all' => 'Indique la cuota, la multa o el concepto a pagar.']);
        abort_unless(Stripe::configurada(), 503, 'Los pagos con tarjeta no están configurados (falta la clave de Stripe en el servidor).');
        $usuario = $request->user();

        if (! empty($d['multa_id'])) {
            $multa = Multa::findOrFail($d['multa_id']);
            abort_if($multa->usuario_id !== $usuario->id, 403, 'Esta multa no le pertenece.');
            abort_if($multa->estado !== 'PENDIENTE', 422, "La multa está {$multa->estado}.");
            $datos = ['multa_id' => $multa->id, 'concepto_id' => $multa->concepto_id, 'monto' => $multa->monto, 'descripcion' => "Multa: {$multa->motivo}"];
        } elseif (! empty($d['cuota_id'])) {
            $cuota = Cuota::with('plan')->findOrFail($d['cuota_id']);
            abort_if($cuota->plan->usuario_id !== $usuario->id, 403, 'Esta cuota no le pertenece.');
            abort_if($cuota->plan->estado !== 'VIGENTE', 422, "El plan está {$cuota->plan->estado}.");
            abort_if($cuota->estado === 'PAGADA', 422, 'Esta cuota ya fue pagada.');
            abort_if(Cuota::where('plan_id', $cuota->plan_id)->where('numero', '<', $cuota->numero)->where('estado', 'PENDIENTE')->exists(),
                422, 'Debe pagar primero las cuotas anteriores.');
            $datos = ['cuota_id' => $cuota->id, 'monto' => $cuota->monto, 'descripcion' => "Cuota {$cuota->numero}/{$cuota->plan->nro_cuotas} - {$cuota->plan->descripcion}"];
        } else {
            $concepto = ConceptoPago::where('activo', true)->where('codigo', 'not like', 'MULTA-%')->findOrFail($d['concepto_id']);
            $datos = ['concepto_id' => $concepto->id, 'monto' => $concepto->monto, 'descripcion' => $concepto->nombre];
        }

        // Si ya hay una página de pago vigente para lo mismo, se reutiliza (evita cobrar dos veces)
        $vigente = Transaccion::where('usuario_id', $usuario->id)->where('estado', 'PENDIENTE')->where('pasarela', 'stripe')
            ->where('expira_en', '>', now()->addMinutes(2))
            ->where(fn ($q) => match (true) {
                isset($datos['multa_id']) => $q->where('multa_id', $datos['multa_id']),
                isset($datos['cuota_id']) => $q->where('cuota_id', $datos['cuota_id']),
                default => $q->where('concepto_id', $datos['concepto_id'])->whereNull('multa_id'),
            })->latest('id')->first();
        if ($vigente?->url_pago) {
            return $this->respuesta($vigente);
        }

        $trx = Transaccion::create($datos + [
            'codigo' => 'TMP-'.Str::random(12), 'usuario_id' => $usuario->id, 'pasarela' => 'stripe', 'estado' => 'PENDIENTE',
            'mensaje' => 'Esperando el pago en Stripe.', 'descripcion' => mb_substr($datos['descripcion'], 0, 200),
        ]);
        $trx->update(['codigo' => 'TRX-'.str_pad((string) $trx->id, 6, '0', STR_PAD_LEFT)]);

        try {
            $sesion = Stripe::crearSesion($trx, $usuario, rtrim((string) config('app.url'), '/').'/pagos/resultado');
        } catch (Throwable $e) {
            report($e);
            $trx->update(['estado' => 'RECHAZADA', 'mensaje' => mb_substr($e->getMessage(), 0, 200)]);

            return response()->json(['message' => 'No se pudo iniciar el pago con Stripe: '.$e->getMessage()], 502);
        }
        $trx->update(['referencia_externa' => $sesion['id'], 'url_pago' => $sesion['url'], 'expira_en' => now()->setTimestamp($sesion['expira'])]);

        return $this->respuesta($trx->fresh(), 201);
    }

    /** La pantalla de resultado consulta esto: si sigue PENDIENTE, se le pregunta a Stripe */
    public function estado(Request $request, int $id): JsonResponse
    {
        $trx = Transaccion::findOrFail($id);
        abort_if($trx->usuario_id !== $request->user()->id && ! $request->user()->tienePermiso('pagos.ver_todos'), 403, 'Transacción ajena.');

        if ($trx->estado === 'PENDIENTE' && $trx->pasarela === 'stripe' && $trx->referencia_externa) {
            try {
                $s = Stripe::consultarSesion($trx->referencia_externa);
                match ($s['estado']) {
                    'PAGADO' => self::confirmar($trx, $s['autorizacion']),
                    'EXPIRADO' => $trx->update(['estado' => 'EXPIRADA', 'mensaje' => 'La página de pago venció sin recibir el pago.']),
                    default => null,
                };
            } catch (Throwable $e) {
                report($e); // si Stripe no responde, se sigue esperando
            }
        }

        return $this->respuesta($trx->fresh());
    }

    /** Aviso de Stripe (webhook). Ruta pública: se acepta solo si la firma es válida. */
    public function webhook(Request $request): JsonResponse
    {
        $cuerpo = $request->getContent();
        $valida = Stripe::firmaValida($cuerpo, $request->header('Stripe-Signature'));
        $evento = json_decode($cuerpo, true) ?: [];
        Bitacora::create([
            'evento' => $valida ? 'ACCESO' : 'DENEGADO', 'metodo' => 'POST', 'recurso' => 'stripe', 'ruta' => 'api/pagos/stripe/webhook',
            'ip' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'codigo_http' => $valida ? 200 : 400,
            'detalle' => substr('Webhook Stripe: '.($evento['type'] ?? '?').' '.($evento['data']['object']['id'] ?? ''), 0, 300),
        ]);
        if (! $valida) {
            return response()->json(['message' => 'Firma inválida.'], 400);
        }

        $sesion = $evento['data']['object'] ?? [];
        $trx = Transaccion::where('pasarela', 'stripe')->where('referencia_externa', $sesion['id'] ?? '')->first();
        if ($trx) {
            match ($evento['type'] ?? '') {
                'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($sesion['payment_status'] ?? '') === 'paid'
                    ? self::confirmar($trx, is_string($sesion['payment_intent'] ?? null) ? $sesion['payment_intent'] : null) : null,
                'checkout.session.expired' => $trx->estado === 'PENDIENTE' ? $trx->update(['estado' => 'EXPIRADA', 'mensaje' => 'La página de pago venció sin recibir el pago.']) : null,
                'checkout.session.async_payment_failed' => $trx->estado === 'PENDIENTE' ? $trx->update(['estado' => 'RECHAZADA', 'mensaje' => 'Stripe informó que el pago falló.']) : null,
                default => null,
            };
        }

        return response()->json(['recibido' => true]); // Stripe solo necesita un 200
    }

    /** Confirma un pago una sola vez (con bloqueo, por si llegan la consulta y el webhook a la vez) */
    public static function confirmar(Transaccion $trx, ?string $autorizacion): void
    {
        DB::transaction(function () use ($trx, $autorizacion) {
            $t = Transaccion::lockForUpdate()->find($trx->id);
            if ($t->estado !== 'PENDIENTE') {
                return; // ya procesada
            }
            $mensaje = 'Pago con tarjeta confirmado por Stripe.';
            if ($t->cuota_id) {
                $cuota = Cuota::lockForUpdate()->find($t->cuota_id);
                if ($cuota->estado === 'PAGADA') {
                    $mensaje = 'Pago recibido, pero la cuota ya estaba pagada: corresponde devolución.';
                } else {
                    $cuota->update(['estado' => 'PAGADA', 'pagado_en' => now()]); // el trigger cierra el plan si era la última
                }
            }
            if ($t->multa_id) {
                $multa = Multa::lockForUpdate()->find($t->multa_id);
                if ($multa->estado === 'PENDIENTE') {
                    $multa->update(['estado' => 'PAGADA', 'pagada_en' => now()]);
                } else {
                    $mensaje = "Pago recibido, pero la multa ya estaba {$multa->estado}: corresponde devolución.";
                }
            }
            $t->update(['estado' => 'APROBADA', 'autorizacion' => mb_substr($autorizacion ?? 'stripe', 0, 40), 'mensaje' => $mensaje]);

            // El Encargado recibe el aviso de cada pago (campanita y, si hay correo configurado, también por email)
            $t->loadMissing('usuario');
            \App\Services\Notificador::aEncargados('Pago recibido: Bs '.number_format((float) $t->monto, 2),
                "{$t->usuario?->nombre_completo} pagó Bs ".number_format((float) $t->monto, 2)." por \"{$t->descripcion}\" con tarjeta (Stripe). "
                ."Transacción {$t->codigo}. $mensaje", '/pagos');
        });
    }

    private function respuesta(Transaccion $trx, int $codigo = 200): JsonResponse
    {
        return response()->json([
            'message' => match ($trx->estado) {
                'APROBADA' => "Pago aprobado. Transacción {$trx->codigo}.",
                'PENDIENTE' => 'Complete el pago en la página de Stripe.',
                default => $trx->mensaje,
            },
            'data' => $trx->load(['cuota.plan', 'multa']),
            'url' => $trx->estado === 'PENDIENTE' ? $trx->url_pago : null,
            'modo_prueba' => Stripe::modoPrueba(),
        ], $codigo);
    }
}
