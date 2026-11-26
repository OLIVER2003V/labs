<?php

namespace Tests\Feature;

use App\Models\Laboratorio;
use App\Models\Multa;
use App\Models\Reserva;
use App\Models\Turno;
use App\Models\User;
use App\Services\Disponibilidad;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** CU9 - Multas por atraso, falta y reserva no utilizada; pago por QR; bloqueo por multas vencidas */
class MultasTest extends SistemaTestCase
{
    private string $martes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->martes = $this->martesLibre();
    }

    private function id(string $email): int
    {
        return User::where('email', $email)->value('id');
    }

    private function a(string $hora): void
    {
        $this->travelTo(Carbon::parse("{$this->martes} $hora"));
    }

    private function multasDe(string $email)
    {
        return Multa::where('usuario_id', $this->id($email));
    }

    public function test_atraso_genera_multa_y_se_paga_con_qr(): void
    {
        $this->como('auxiliar@ficct.edu.bo');
        $this->a('07:20');
        $this->postJson('/api/asistencias/entrada')->assertCreated()
            ->assertJsonFragment(['message' => 'Entrada registrada a las 07:20 (ATRASO). Se registró una multa de Bs 10.00 por atraso.']);
        $multa = $this->multasDe('auxiliar@ficct.edu.bo')->firstOrFail();
        $this->assertSame(['ATRASO', 'PENDIENTE', '10.00'], [$multa->tipo, $multa->estado, $multa->monto]);
        $this->assertSame(Carbon::parse($this->martes)->addDays(7)->toDateString(), $multa->vence_en->toDateString());

        // Pago con tarjeta en Stripe (API simulada con Http::fake): página de pago → Stripe confirma → PAGADA
        config(['pagos.stripe.secreta' => 'sk_test_prueba']);
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test_multa', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_multa', 'expires_at' => now()->addMinutes(30)->timestamp]),
            'api.stripe.com/v1/checkout/sessions/*' => Http::response(['id' => 'cs_test_multa', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_test_multa']),
        ]);
        $trx = $this->postJson('/api/pagos/stripe', ['multa_id' => $multa->id])->assertCreated()->json('data');
        $this->assertEquals(10, $trx['monto']);
        $this->getJson("/api/pagos/transacciones/{$trx['id']}/estado")->assertOk()->assertJsonPath('data.estado', 'APROBADA');
        $this->assertSame('PAGADA', $multa->fresh()->estado);
        $this->postJson('/api/pagos/stripe', ['multa_id' => $multa->id])->assertStatus(422); // no se paga dos veces

        // Otro auxiliar no puede pagar ni ver la multa ajena
        $this->como('vflores@ficct.edu.bo');
        $this->postJson('/api/pagos/stripe', ['multa_id' => $multa->id])->assertForbidden();
        $this->assertCount(0, $this->getJson('/api/multas')->json('data'));
    }

    public function test_falta_genera_multa_y_un_permiso_la_anula(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $this->a('07:31');
        $this->getJson('/api/asistencias')->assertOk(); // registra las faltas del día
        $multa = $this->multasDe('auxiliar@ficct.edu.bo')->where('tipo', 'FALTA')->firstOrFail();
        $this->assertEquals(50, $multa->monto);

        $this->postJson('/api/asistencias/permiso', ['auxiliar_id' => $this->id('auxiliar@ficct.edu.bo'), 'fecha' => $this->martes, 'motivo' => 'Cita médica'])
            ->assertCreated();
        $this->assertSame('ANULADA', $multa->fresh()->estado);
        $this->assertStringContainsString('Cita médica', $multa->fresh()->observacion);
    }

    public function test_multa_vencida_bloquea_reservas_y_permutas(): void
    {
        $this->como('auxiliar@ficct.edu.bo');
        $this->a('07:20');
        $this->postJson('/api/asistencias/entrada')->assertCreated();
        $multa = $this->multasDe('auxiliar@ficct.edu.bo')->firstOrFail();

        // Pasa el plazo sin pagar
        $this->travelTo(Carbon::parse($this->martes)->addDays(8)->setTime(9, 0));
        $msg = 'Tiene 1 multa(s) vencida(s) por Bs 10.00. Páguela(s) en Pagos → Multas para poder';
        $this->getJson('/api/multas/resumen')->assertJsonPath('bloqueado', true);
        $this->postJson('/api/reservas', ['laboratorio_id' => Laboratorio::where('estado', 'DISPONIBLE')->value('id'), 'fecha' => now()->addDays(3)->toDateString(),
            'hora_inicio' => '20:00', 'hora_fin' => '21:00', 'tipo' => 'PRACTICA', 'motivo' => 'Práctica de prueba'])
            ->assertStatus(422)->assertJsonFragment(['message' => "$msg solicitar reservas."]);
        $turno = Turno::where('auxiliar_id', $this->id('auxiliar@ficct.edu.bo'))->where('dia_semana', 1)->first();
        $this->postJson('/api/permutas', ['turno_id' => $turno->id, 'fecha' => now()->next(Carbon::MONDAY)->toDateString(),
            'reemplazante_id' => $this->id('vflores@ficct.edu.bo'), 'motivo' => 'Examen final'])
            ->assertStatus(422)->assertJsonFragment(['message' => "$msg solicitar permutas."]);

        // El Encargado la anula: se desbloquea
        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/multas/{$multa->id}/anular", ['observacion' => 'Presentó justificación del atraso'])->assertOk();
        $this->como('auxiliar@ficct.edu.bo');
        $this->getJson('/api/multas/resumen')->assertJsonPath('bloqueado', false);
    }

    public function test_reserva_no_utilizada_genera_multa_al_solicitante(): void
    {
        $lab = Laboratorio::where('estado', 'DISPONIBLE')->orderBy('codigo')->get()
            ->first(fn ($l) => ! Disponibilidad::conflictos($l->id, $this->martes, '20:00', '21:30'));
        $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/reservas', ['laboratorio_id' => $lab->id, 'fecha' => $this->martes, 'hora_inicio' => '20:00', 'hora_fin' => '21:30',
            'tipo' => 'PRACTICA', 'motivo' => 'Práctica de redes'])->assertCreated()->json('data.id');
        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/reservas/$id/aprobar")->assertOk();

        $this->como('auxiliar@ficct.edu.bo');
        $this->a('20:10'); // todavía dentro de los 15 min de tolerancia
        $this->putJson("/api/reservas/$id/uso", ['uso' => 'NO_UTILIZADA'])->assertStatus(422);
        $this->a('20:16');
        $this->putJson("/api/reservas/$id/uso", ['uso' => 'NO_UTILIZADA'])->assertOk();
        $multa = $this->multasDe('docente@ficct.edu.bo')->where('reserva_id', $id)->firstOrFail();
        $this->assertSame(['RESERVA', '20.00'], [$multa->tipo, $multa->monto]);
        $this->assertSame('NO_UTILIZADA', Reserva::find($id)->uso);

        // Se corrige: sí se usó → la multa se anula
        $this->putJson("/api/reservas/$id/uso", ['uso' => 'UTILIZADA'])->assertOk();
        $this->assertSame('ANULADA', $multa->fresh()->estado);

        // Un docente no registra el uso
        $this->como('docente@ficct.edu.bo');
        $this->putJson("/api/reservas/$id/uso", ['uso' => 'NO_UTILIZADA'])->assertForbidden();
    }

    public function test_concepto_desactivado_no_genera_multa_y_reporte(): void
    {
        DB::table('conceptos_pago')->where('codigo', 'MULTA-ATRASO')->update(['activo' => false]);
        $this->como('auxiliar@ficct.edu.bo');
        $this->a('07:20');
        $this->postJson('/api/asistencias/entrada')->assertCreated()->assertJsonPath('multa', null);
        $this->assertSame(0, $this->multasDe('auxiliar@ficct.edu.bo')->count());

        $this->como('encargado@ficct.edu.bo');
        $this->getJson("/api/reportes/multas?desde={$this->martes}&hasta={$this->martes}")->assertOk()->assertJsonPath('titulo', 'Reporte de multas');
    }

    public function test_solo_docente_y_auxiliar_pagan_director_consulta_y_estudiante_no_tiene_multas(): void
    {
        // Estudiante: sin multas ni pagos
        $est = User::create(['codigo' => '219999001', 'nombre' => 'Ana', 'apellido' => 'Prueba', 'email' => 'ana.prueba@ficct.edu.bo',
            'telefono' => '70000099', 'rol_id' => DB::table('roles')->where('codigo', 'EST')->value('id'), 'estado' => 'ACTIVO', 'password' => 'Clave2026']);
        $this->como($est->email);
        $this->getJson('/api/multas')->assertForbidden();
        $this->getJson('/api/pagos/transacciones')->assertForbidden();

        // Director: consulta las multas de todos, pero no paga ni anula
        $multa = Multa::create(['usuario_id' => $this->id('auxiliar@ficct.edu.bo'), 'tipo' => 'ATRASO', 'monto' => 10, 'motivo' => 'Atraso de prueba',
            'concepto_id' => DB::table('conceptos_pago')->where('codigo', 'MULTA-ATRASO')->value('id'),
            'fecha' => today()->toDateString(), 'estado' => 'PENDIENTE', 'vence_en' => today()->addDays(7)]);
        $this->como('director@ficct.edu.bo');
        $ids = collect($this->getJson('/api/multas?por_pagina=100')->assertOk()->json('data'))->pluck('usuario_id');
        $this->assertTrue($ids->contains(fn ($id) => $id !== User::where('email', 'director@ficct.edu.bo')->value('id')));
        $this->postJson('/api/pagos/stripe', ['multa_id' => $multa->id])->assertForbidden();
        $this->putJson("/api/multas/{$multa->id}/anular", ['observacion' => 'No corresponde'])->assertForbidden();

        // Una reserva del Director o del Estudiante que no se usa no genera multa
        foreach (['director@ficct.edu.bo', $est->email] as $email) {
            $r = Reserva::create(['laboratorio_id' => Laboratorio::value('id'), 'solicitante_id' => User::where('email', $email)->value('id'),
                'fecha' => $this->martes, 'hora_inicio' => '07:00', 'hora_fin' => '07:30', 'tipo' => 'CONFERENCIA', 'motivo' => 'Prueba', 'estado' => 'APROBADA']);
            $this->assertNull(\App\Services\Multas::porReservaNoUtilizada($r));
            $r->delete();
        }
    }
}
