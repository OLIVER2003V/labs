<?php

namespace Tests\Feature;

use App\Models\Cuota;
use App\Models\PlanPago;
use App\Models\Transaccion;
use App\Services\Pagos\Stripe;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;

/**
 * Pagos con Stripe Checkout. La API de Stripe se simula con Http::fake (las pruebas no salen a internet
 * ni necesitan claves reales).
 */
class PagoStripeTest extends SistemaTestCase
{
    const WEBHOOK = 'whsec_prueba';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pagos.stripe.secreta' => 'sk_test_prueba', 'pagos.stripe.webhook' => self::WEBHOOK, 'pagos.stripe.moneda' => 'bob']);
    }

    /** Estado del pago que "responde" el Stripe simulado: unpaid | paid */
    private string $pago = 'unpaid';
    private bool $simulado = false;

    private function stripeResponde(string $pago = 'unpaid'): void
    {
        $this->pago = $pago;
        if ($this->simulado) {
            return; // Http::fake conserva la primera respuesta registrada: la respuesta lee $this->pago
        }
        $this->simulado = true;
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                'expires_at' => now()->addMinutes(30)->timestamp]),
            'api.stripe.com/v1/checkout/sessions/*' => fn () => Http::response(['id' => 'cs_test_123', 'status' => $this->pago === 'paid' ? 'complete' : 'open',
                'payment_status' => $this->pago, 'payment_intent' => $this->pago === 'paid' ? 'pi_test_123' : null]),
        ]);
    }

    /** Arma un aviso de Stripe firmado como lo firma Stripe (HMAC-SHA256 de "t.cuerpo") */
    private function webhook(array $evento, ?string $secreto = self::WEBHOOK)
    {
        $cuerpo = json_encode($evento);
        $t = time();
        $firma = hash_hmac('sha256', "$t.$cuerpo", $secreto);

        return $this->call('POST', '/api/pagos/stripe/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t=$t,v1=$firma",
        ], $cuerpo);
    }

    private function cuotaDelDocente(): Cuota
    {
        $plan = PlanPago::where('usuario_id', $this->como('docente@ficct.edu.bo')->id)->where('estado', 'VIGENTE')->firstOrFail();

        return Cuota::where('plan_id', $plan->id)->where('estado', 'PENDIENTE')->orderBy('numero')->firstOrFail();
    }

    public function test_pagar_una_cuota_crea_la_pagina_de_stripe_y_al_confirmar_queda_pagada(): void
    {
        $cuota = $this->cuotaDelDocente();
        $this->stripeResponde('unpaid');

        $r = $this->postJson('/api/pagos/stripe', ['cuota_id' => $cuota->id])->assertCreated()
            ->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/cs_test_123')->assertJsonPath('data.estado', 'PENDIENTE');
        $id = $r->json('data.id');

        // Se mandó a Stripe el monto en centavos, en bolivianos, y la URL de vuelta
        Http::assertSent(fn (PeticionHttp $p) => str_ends_with($p->url(), '/checkout/sessions')
            && $p['line_items'][0]['price_data']['unit_amount'] === (int) round($cuota->monto * 100)
            && $p['line_items'][0]['price_data']['currency'] === 'bob'
            && str_contains($p['success_url'], "/pagos/resultado?trx=$id"));

        // Volver a pedir el pago reutiliza la misma página (no se crea otra transacción)
        $this->postJson('/api/pagos/stripe', ['cuota_id' => $cuota->id])->assertOk()->assertJsonPath('data.id', $id);

        // Todavía no pagó
        $this->getJson("/api/pagos/transacciones/$id/estado")->assertJsonPath('data.estado', 'PENDIENTE');
        $this->assertSame('PENDIENTE', $cuota->fresh()->estado);

        // Pagó en Stripe
        $this->stripeResponde('paid');
        $this->getJson("/api/pagos/transacciones/$id/estado")->assertJsonPath('data.estado', 'APROBADA')->assertJsonPath('data.autorizacion', 'pi_test_123')
            ->assertJsonPath('url', null);
        $this->assertSame('PAGADA', $cuota->fresh()->estado);
        $this->postJson('/api/pagos/stripe', ['cuota_id' => $cuota->id])->assertStatus(422); // no se paga dos veces
    }

    public function test_el_webhook_confirma_solo_con_firma_valida_y_una_sola_vez(): void
    {
        $cuota = $this->cuotaDelDocente();
        $this->stripeResponde('unpaid');
        $id = $this->postJson('/api/pagos/stripe', ['cuota_id' => $cuota->id])->json('data.id');
        $evento = ['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_123', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_webhook']]];

        $this->webhook($evento, 'whsec_falso')->assertStatus(400);                   // firma falsa: rechazado
        $this->assertSame('PENDIENTE', Transaccion::find($id)->estado);

        $this->webhook($evento)->assertOk();                                          // firma válida: confirma
        $this->assertSame('APROBADA', Transaccion::find($id)->estado);
        $this->assertSame('PAGADA', $cuota->fresh()->estado);
        $this->webhook($evento)->assertOk();                                          // Stripe reintenta: no se duplica
        $this->assertSame(1, Transaccion::where('cuota_id', $cuota->id)->where('estado', 'APROBADA')->count());
        if (! Cuota::where('plan_id', $cuota->plan_id)->where('estado', 'PENDIENTE')->exists()) {
            $this->assertSame('PAGADO', PlanPago::find($cuota->plan_id)->estado); // trigger tg_cuota_cierra_plan
        }
    }

    public function test_firma_vieja_o_mal_formada_se_rechaza(): void
    {
        $cuerpo = '{"type":"checkout.session.completed"}';
        $vieja = time() - 600;
        $this->assertFalse(Stripe::firmaValida($cuerpo, "t=$vieja,v1=".hash_hmac('sha256', "$vieja.$cuerpo", self::WEBHOOK)));
        $this->assertFalse(Stripe::firmaValida($cuerpo, 'basura'));
        $this->assertFalse(Stripe::firmaValida($cuerpo, null));
        $t = time();
        $this->assertTrue(Stripe::firmaValida($cuerpo, "t=$t,v1=".hash_hmac('sha256', "$t.$cuerpo", self::WEBHOOK)));
    }

    public function test_sin_clave_de_stripe_avisa_claramente(): void
    {
        config(['pagos.stripe.secreta' => '']);
        $cuota = $this->cuotaDelDocente();
        $this->postJson('/api/pagos/stripe', ['cuota_id' => $cuota->id])->assertStatus(503)
            ->assertJson(['message' => 'Los pagos con tarjeta no están configurados (falta la clave de Stripe en el servidor).']);
    }

    public function test_cuota_ajena_y_error_de_stripe(): void
    {
        $cuota = $this->cuotaDelDocente();
        $this->como('director@ficct.edu.bo');
        $this->postJson('/api/pagos/stripe', ['cuota_id' => $cuota->id])->assertForbidden();

        $this->como('docente@ficct.edu.bo');
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);
        $this->postJson('/api/pagos/stripe', ['cuota_id' => $cuota->id])->assertStatus(502)
            ->assertJson(['message' => 'No se pudo iniciar el pago con Stripe: Stripe respondió: Invalid API Key provided']);
        $this->assertSame('RECHAZADA', Transaccion::where('cuota_id', $cuota->id)->latest('id')->value('estado'));
    }
}
