<?php

namespace App\Services\Pagos;

use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Conector con Stripe Checkout (sin librerías: la API de Stripe es REST con formularios).
 *
 *   1. crearSesion()  → Stripe devuelve una URL de su página de pago; el usuario paga ahí con tarjeta
 *   2. Stripe lo devuelve a nuestra página /pagos/resultado
 *   3. consultarSesion() confirma el pago preguntándole a Stripe  (y además…)
 *   4. Stripe avisa por su cuenta al webhook; firmaValida() comprueba que el aviso viene de Stripe
 *
 * Los datos de la tarjeta NUNCA pasan por nuestro servidor. Claves en .env (nunca en git):
 *   PAGOS_STRIPE_SECRETA=sk_test_...      PAGOS_STRIPE_WEBHOOK=whsec_...
 * En modo prueba se paga con la tarjeta 4242 4242 4242 4242, cualquier fecha futura y cualquier CVC.
 */
class Stripe
{
    const API = 'https://api.stripe.com/v1';

    public static function configurada(): bool
    {
        return str_starts_with((string) config('pagos.stripe.secreta'), 'sk_');
    }

    public static function modoPrueba(): bool
    {
        return str_starts_with((string) config('pagos.stripe.secreta'), 'sk_test_');
    }

    /** @return array{id: string, url: string, expira: int} */
    public static function crearSesion(Transaccion $trx, User $usuario, string $urlRetorno): array
    {
        $respuesta = self::http()->post(self::API.'/checkout/sessions', [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('pagos.stripe.moneda'),
                    'unit_amount' => (int) round((float) $trx->monto * 100), // Stripe trabaja en centavos
                    'product_data' => ['name' => mb_substr($trx->descripcion, 0, 250), 'description' => "Transacción {$trx->codigo} · Laboratorios FICCT"],
                ],
            ]],
            'customer_email' => $usuario->email,
            'client_reference_id' => $trx->codigo,
            'metadata' => ['transaccion_id' => $trx->id, 'codigo' => $trx->codigo],
            'payment_intent_data' => ['metadata' => ['transaccion_id' => $trx->id, 'codigo' => $trx->codigo]],
            'locale' => 'es',
            'success_url' => "$urlRetorno?trx={$trx->id}",
            'cancel_url' => "$urlRetorno?trx={$trx->id}&cancelado=1",
            'expires_at' => now()->addMinutes(max(30, (int) config('pagos.stripe.minutos')))->timestamp, // Stripe exige mínimo 30 min
        ]);
        $datos = self::revisar($respuesta);

        return ['id' => $datos['id'], 'url' => $datos['url'], 'expira' => $datos['expires_at']];
    }

    /**
     * Estado de la sesión en Stripe: PAGADO | PENDIENTE | EXPIRADO
     * @return array{estado: string, autorizacion: ?string}
     */
    public static function consultarSesion(string $sesionId): array
    {
        $s = self::revisar(self::http()->get(self::API.'/checkout/sessions/'.urlencode($sesionId)));

        return [
            'estado' => match (true) {
                ($s['payment_status'] ?? '') === 'paid' => 'PAGADO',
                ($s['status'] ?? '') === 'expired' => 'EXPIRADO',
                default => 'PENDIENTE',
            },
            'autorizacion' => is_string($s['payment_intent'] ?? null) ? $s['payment_intent'] : null,
        ];
    }

    /**
     * Verifica la firma del webhook (cabecera Stripe-Signature: "t=123,v1=abc...").
     * Es un HMAC-SHA256 de "t.cuerpo" con el secreto del webhook; se rechazan avisos de más de 5 minutos.
     */
    public static function firmaValida(string $cuerpo, ?string $cabecera, ?string $secreto = null): bool
    {
        $secreto ??= (string) config('pagos.stripe.webhook');
        if (! $cabecera || ! $secreto) {
            return false;
        }
        $t = null;
        $firmas = [];
        foreach (explode(',', $cabecera) as $parte) {
            [$k, $v] = array_pad(explode('=', trim($parte), 2), 2, '');
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $firmas[] = $v;
            }
        }
        if (! $t || ! ctype_digit($t) || abs(time() - (int) $t) > 300) {
            return false;
        }
        $esperada = hash_hmac('sha256', "$t.$cuerpo", $secreto);
        foreach ($firmas as $f) {
            if (hash_equals($esperada, $f)) {
                return true;
            }
        }

        return false;
    }

    private static function http()
    {
        if (! self::configurada()) {
            throw new RuntimeException('Stripe no está configurado: falta PAGOS_STRIPE_SECRETA en el archivo .env.');
        }

        return Http::withBasicAuth((string) config('pagos.stripe.secreta'), '')->asForm()->timeout(20)->acceptJson();
    }

    private static function revisar($respuesta): array
    {
        if ($respuesta->failed()) {
            throw new RuntimeException('Stripe respondió: '.($respuesta->json('error.message') ?? 'error '.$respuesta->status()));
        }

        return $respuesta->json();
    }
}
