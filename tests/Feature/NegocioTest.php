<?php

namespace Tests\Feature;

use App\Models\Cuota;
use App\Models\Laboratorio;
use App\Models\MetodoPago;
use App\Models\PlanPago;
use App\Models\Reserva;
use App\Models\User;
use App\Services\Disponibilidad;
use App\Support\Respaldo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Reglas de negocio: reservas y choques, triggers, pagos y respaldos */
class NegocioTest extends SistemaTestCase
{
    private function claseEn(string $lab, string $fecha, string $ini, string $fin): void
    {
        DB::table('horarios')->insert([
            'semestre_id' => DB::table('semestres')->where('estado', 'ACTIVO')->value('id'),
            'laboratorio_id' => Laboratorio::where('codigo', $lab)->value('id'),
            'materia_id' => DB::table('materias')->value('id'),
            'docente_id' => User::where('email', 'docente@ficct.edu.bo')->value('id'),
            'grupo' => 'TT', 'dia_semana' => date('N', strtotime($fecha)), 'hora_inicio' => $ini, 'hora_fin' => $fin,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_franjas_contiguas_no_chocan_pero_superpuestas_si(): void
    {
        $fecha = $this->martesLibre();
        $this->claseEn('LAB-41', $fecha, '08:00', '10:00');
        $lab = Laboratorio::where('codigo', 'LAB-41')->value('id');

        $this->assertEmpty(array_filter(Disponibilidad::conflictos($lab, $fecha, '10:00', '12:00'), fn ($c) => $c['detalle'] === 'Clase '.DB::table('materias')->value('sigla').' grupo TT (Carla Vaca Justiniano)'));
        $this->assertNotEmpty(Disponibilidad::conflictos($lab, $fecha, '09:00', '11:00'));
    }

    public function test_reserva_con_choque_es_rechazada_y_sugiere_laboratorios_libres(): void
    {
        $fecha = $this->martesLibre();
        $this->claseEn('LAB-41', $fecha, '08:00', '10:00');
        $this->como('docente@ficct.edu.bo');

        $r = $this->postJson('/api/reservas', [
            'laboratorio_id' => Laboratorio::where('codigo', 'LAB-41')->value('id'), 'fecha' => $fecha,
            'hora_inicio' => '09:00', 'hora_fin' => '11:00', 'tipo' => 'EXAMEN', 'motivo' => 'Parcial de prueba',
        ]);

        $r->assertStatus(422)->assertJsonStructure(['message', 'conflictos', 'sugerencias']);
        $this->assertStringContainsString('no está disponible', $r->json('message'));
    }

    public function test_flujo_reserva_solicitar_aprobar_y_trigger_impide_duplicado_por_sql(): void
    {
        $fecha = $this->martesLibre();
        $lab = Laboratorio::where('codigo', 'LAB-42')->value('id');
        $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/reservas', [
            'laboratorio_id' => $lab, 'fecha' => $fecha, 'hora_inicio' => '21:30', 'hora_fin' => '22:00',
            'tipo' => 'PRACTICA', 'motivo' => 'Práctica de prueba',
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/reservas/$id/aprobar")->assertStatus(403); // el docente no aprueba

        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/reservas/$id/aprobar")->assertOk();
        $this->assertSame('APROBADA', Reserva::find($id)->estado);

        // Aunque alguien inserte por SQL directo, el trigger tg_reserva_sin_choque lo impide
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('CHOQUE');
        DB::table('reservas')->insert(['laboratorio_id' => $lab, 'solicitante_id' => 4, 'fecha' => $fecha, 'hora_inicio' => '21:00',
            'hora_fin' => '22:00', 'tipo' => 'EXAMEN', 'motivo' => 'x', 'estado' => 'APROBADA', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_no_se_reserva_en_fecha_pasada_ni_domingo(): void
    {
        $this->como('docente@ficct.edu.bo');
        $base = ['laboratorio_id' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '10:00', 'tipo' => 'EXAMEN', 'motivo' => 'Parcial de prueba'];

        $this->postJson('/api/reservas', $base + ['fecha' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('fecha');
        $this->postJson('/api/reservas', $base + ['fecha' => now()->addWeek()->next('Sunday')->toDateString()])
            ->assertStatus(422)->assertJson(['message' => 'Los laboratorios no atienden los domingos.']);
    }

    public function test_trigger_de_incidencia_pone_el_equipo_en_reparacion_y_lo_devuelve(): void
    {
        $equipo = DB::table('equipos')->where('estado', 'OPERATIVO')->first();
        $this->como('auxiliar@ficct.edu.bo');
        $id = $this->postJson('/api/incidencias', ['equipo_id' => $equipo->id, 'descripcion' => 'El monitor no enciende al iniciar'])
            ->assertCreated()->json('data.id');
        $this->assertSame('EN_REPARACION', DB::table('equipos')->where('id', $equipo->id)->value('estado'));

        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/incidencias/$id", ['estado' => 'RESUELTA', 'solucion' => 'Se cambió el cable'])->assertOk();
        $this->assertSame('OPERATIVO', DB::table('equipos')->where('id', $equipo->id)->value('estado'));
    }

    public function test_auditoria_registra_cambios_hechos_directo_en_la_bd(): void
    {
        DB::table('laboratorios')->where('codigo', 'LAB-41')->update(['nombre' => 'Modificado por SQL']);

        $a = DB::table('auditoria')->where('tabla', 'laboratorios')->where('operacion', 'UPDATE')->latest('id')->first();
        $this->assertNotNull($a);
        $this->assertNull($a->usuario_app_id); // no vino desde la aplicación
        $this->assertStringContainsString('Modificado por SQL', $a->datos_despues);
    }

    public function test_pago_de_cuota_rechazado_y_luego_aprobado_cierra_el_plan(): void
    {
        $docente = $this->como('docente@ficct.edu.bo');
        $plan = PlanPago::where('usuario_id', $docente->id)->where('estado', 'VIGENTE')->firstOrFail();
        $cuota = Cuota::where('plan_id', $plan->id)->where('estado', 'PENDIENTE')->orderBy('numero')->firstOrFail();
        $sinFondos = MetodoPago::where('usuario_id', $docente->id)->where('ultimos4', '0002')->firstOrFail();
        $visa = MetodoPago::where('usuario_id', $docente->id)->where('ultimos4', '1111')->firstOrFail();

        $this->postJson("/api/pagos/cuotas/{$cuota->id}/pagar", ['metodo_pago_id' => $sinFondos->id])->assertStatus(402);
        $this->assertSame('PENDIENTE', $cuota->fresh()->estado);
        $this->assertDatabaseHas('transacciones', ['cuota_id' => $cuota->id, 'estado' => 'RECHAZADA']);

        $this->postJson("/api/pagos/cuotas/{$cuota->id}/pagar", ['metodo_pago_id' => $visa->id])->assertCreated();
        $this->assertSame('PAGADA', $cuota->fresh()->estado);
        $this->postJson("/api/pagos/cuotas/{$cuota->id}/pagar", ['metodo_pago_id' => $visa->id])->assertStatus(422); // no se paga dos veces
        if (! Cuota::where('plan_id', $plan->id)->where('estado', 'PENDIENTE')->exists()) {
            $this->assertSame('PAGADO', $plan->fresh()->estado); // trigger tg_cuota_cierra_plan
        }
    }

    public function test_tarjeta_invalida_se_rechaza_al_registrarla(): void
    {
        $this->como('docente@ficct.edu.bo');
        $this->postJson('/api/pagos/metodos', ['tipo' => 'TARJETA', 'alias' => 'Mala', 'titular' => 'Carla Vaca',
            'numero_tarjeta' => '4111111111111112', 'vencimiento' => '12/30', 'cvv' => '123'])
            ->assertStatus(422)->assertJson(['message' => 'El número de tarjeta no es válido.']);
    }

    public function test_respaldo_y_restauracion_recuperan_datos_borrados(): void
    {
        [$ruta] = Respaldo::crear('prueba_unitaria.json');
        $total = DB::table('objetos_encontrados')->count();
        DB::table('objetos_encontrados')->delete();

        Respaldo::restaurar($ruta);
        File::delete($ruta);

        $this->assertSame($total, DB::table('objetos_encontrados')->count());
    }
}
