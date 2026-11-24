<?php

namespace Tests\Feature;

use App\Models\ConceptoPago;
use App\Models\Cuota;
use App\Models\PlanPago;
use App\Models\Transaccion;
use App\Services\Pagos\PagoFacilQr;
use App\Services\Pagos\PasarelaQr;
use Illuminate\Support\Facades\Http;

/** Cobro por QR (PagoFácil / simulado) */
class PagoQrTest extends SistemaTestCase
{
    public function test_flujo_qr_simulado_genera_qr_y_se_confirma_al_pagar_en_el_banco(): void
    {
        $docente = $this->como('docente@ficct.edu.bo');
        $plan = PlanPago::where('usuario_id', $docente->id)->where('estado', 'VIGENTE')->firstOrFail();
        $cuota = Cuota::where('plan_id', $plan->id)->where('estado', 'PENDIENTE')->orderBy('numero')->firstOrFail();

        $r = $this->postJson('/api/pagos/qr', ['cuota_id' => $cuota->id])->assertCreated()
            ->assertJson(['simulada' => true, 'data' => ['estado' => 'PENDIENTE', 'pasarela' => 'simulada']]);
        $id = $r->json('data.id');
        $this->assertNotEmpty($r->json('qr'));

        // Mismo QR si se vuelve a pedir (no se crea un segundo cobro)
        $this->postJson('/api/pagos/qr', ['cuota_id' => $cuota->id])->assertOk()->assertJsonPath('data.id', $id);

        $this->getJson("/api/pagos/transacciones/$id/estado")->assertJsonPath('data.estado', 'PENDIENTE');
        $this->postJson("/api/pagos/simulador/$id/pagar")->assertOk();
        $this->getJson("/api/pagos/transacciones/$id/estado")->assertJsonPath('data.estado', 'APROBADA');

        $this->assertSame('PAGADA', $cuota->fresh()->estado);
    }

    public function test_qr_vencido_queda_expirado(): void
    {
        $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/pagos/qr', ['concepto_id' => ConceptoPago::value('id')])->assertCreated()->json('data.id');
        Transaccion::whereKey($id)->update(['expira_en' => now()->subMinute()]);

        $this->getJson("/api/pagos/transacciones/$id/estado")->assertJsonPath('data.estado', 'EXPIRADA');
    }

    public function test_no_se_puede_consultar_ni_pagar_el_qr_de_otro_usuario(): void
    {
        $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/pagos/qr', ['concepto_id' => ConceptoPago::value('id')])->json('data.id');

        $this->como('auxiliar@ficct.edu.bo');
        $this->getJson("/api/pagos/transacciones/$id/estado")->assertStatus(403);
        $this->postJson("/api/pagos/simulador/$id/pagar")->assertStatus(404);
    }

    public function test_conector_pagofacil_genera_qr_y_procesa_el_callback(): void
    {
        config(['pagos.pasarela' => 'pagofacil', 'pagos.pagofacil.url' => 'https://pagofacil.test', 'pagos.pagofacil.token_servicio' => 'svc',
            'pagos.pagofacil.token_secreto' => 'sec', 'pagos.pagofacil.comercio' => '123']);
        $this->app->bind(PasarelaQr::class, fn () => new PagoFacilQr);
        Http::fake([
            'pagofacil.test/login' => Http::response(['values' => ['accessToken' => 'tok']]),
            'pagofacil.test/generar-qr' => Http::response(['values' => ['transactionId' => 'PF-999', 'qrBase64' => 'iVBORw0KGgo=']]),
            'pagofacil.test/consultar' => Http::response(['values' => ['estado' => 'PENDIENTE']]),
        ]);

        $this->como('docente@ficct.edu.bo');
        $r = $this->postJson('/api/pagos/qr', ['concepto_id' => ConceptoPago::value('id')])->assertCreated()
            ->assertJson(['qr_es_imagen' => true, 'simulada' => false, 'data' => ['referencia_externa' => 'PF-999']]);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/generar-qr') && $req->hasHeader('Authorization', 'Bearer tok'));

        // PagoFácil avisa que se pagó (ruta pública)
        $this->postJson('/api/pagos/pagofacil/callback', ['transactionId' => 'PF-999', 'estado' => 'PAGADO'])->assertOk();
        $this->assertSame('APROBADA', Transaccion::find($r->json('data.id'))->estado);
        $this->postJson('/api/pagos/pagofacil/callback', ['nada' => 1])->assertStatus(422);
    }

    public function test_pagofacil_sin_credenciales_responde_error_claro(): void
    {
        config(['pagos.pasarela' => 'pagofacil', 'pagos.pagofacil.url' => '']);
        $this->app->bind(PasarelaQr::class, fn () => new PagoFacilQr);
        $this->como('docente@ficct.edu.bo');

        $this->postJson('/api/pagos/qr', ['concepto_id' => ConceptoPago::value('id')])
            ->assertStatus(502)->assertJson(['message' => 'No se pudo generar el QR de pago. Intente más tarde.']);
    }
}
