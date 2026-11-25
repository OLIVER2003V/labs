<?php

namespace Tests\Feature;

use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** CU2 - Fechas bloqueadas (rangos, sin duplicados, reservas afectadas) + notificaciones */
class BloqueosNotificacionesTest extends SistemaTestCase
{
    private function lunes(): Carbon
    {
        $d = Carbon::today()->addDays(14)->next(Carbon::MONDAY);
        DB::table('fechas_bloqueadas')->whereBetween('fecha', [$d->toDateString(), $d->copy()->addDays(13)->toDateString()])->delete();

        return $d;
    }

    private function bloquear(array $datos)
    {
        return $this->postJson('/api/fechas-bloqueadas', $datos + [
            'semestre_id' => Semestre::where('estado', 'ACTIVO')->value('id'), 'tipo' => 'FERIADO', 'motivo' => 'Prueba de bloqueo',
        ]);
    }

    private function reservaAprobada(string $lab, string $fecha): Reserva
    {
        return Reserva::create(['laboratorio_id' => Laboratorio::where('codigo', $lab)->value('id'), 'solicitante_id' => User::where('email', 'docente@ficct.edu.bo')->value('id'),
            'fecha' => $fecha, 'hora_inicio' => '21:30', 'hora_fin' => '22:00', 'tipo' => 'EXAMEN', 'motivo' => 'Parcial de prueba', 'estado' => 'APROBADA']);
    }

    public function test_bloquear_un_rango_crea_un_dia_por_fecha_sin_domingos(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $lunes = $this->lunes();

        $this->bloquear(['fecha_desde' => $lunes->toDateString(), 'fecha_hasta' => $lunes->copy()->addDays(6)->toDateString()])
            ->assertCreated()->assertJson(['creados' => 6]); // lunes a domingo = 7 días, menos el domingo
        $this->assertSame(6, DB::table('fechas_bloqueadas')->whereBetween('fecha', [$lunes->toDateString(), $lunes->copy()->addDays(6)->toDateString()])->count());
    }

    public function test_no_se_permiten_fechas_duplicadas(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $lunes = $this->lunes();
        $this->bloquear(['fecha_desde' => $lunes->toDateString()])->assertCreated();

        $this->bloquear(['fecha_desde' => $lunes->toDateString()])->assertStatus(422)->assertJson(['message' => 'Esa fecha ya está bloqueada.']);
        // Un rango que incluye el día ya bloqueado: solo crea los nuevos y avisa los omitidos
        $r = $this->bloquear(['fecha_desde' => $lunes->toDateString(), 'fecha_hasta' => $lunes->copy()->addDays(2)->toDateString()])->assertCreated();
        $this->assertSame(2, $r->json('creados'));
        $this->assertStringContainsString('se omitieron', $r->json('message'));

        // Y la base de datos lo impide aunque se inserte por SQL directo
        $this->expectException(QueryException::class);
        DB::table('fechas_bloqueadas')->insert(['semestre_id' => Semestre::where('estado', 'ACTIVO')->value('id'), 'fecha' => $lunes->toDateString(),
            'tipo' => 'OTRO', 'motivo' => 'duplicado', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_bloquear_una_fecha_con_reservas_pide_confirmar_las_cancela_y_notifica(): void
    {
        $lunes = $this->lunes();
        $reserva = $this->reservaAprobada('LAB-41', $lunes->toDateString());
        $this->como('encargado@ficct.edu.bo');

        $r = $this->bloquear(['fecha_desde' => $lunes->toDateString()])->assertStatus(409)->assertJson(['requiere_confirmacion' => true]);
        $this->assertStringContainsString("#{$reserva->id}", $r->json('detalle.0'));
        $this->assertSame('APROBADA', $reserva->fresh()->estado); // sin confirmar no se toca nada
        $this->assertSame(0, DB::table('fechas_bloqueadas')->whereDate('fecha', $lunes)->count());

        $this->bloquear(['fecha_desde' => $lunes->toDateString(), 'confirmar' => true])->assertCreated()->assertJson(['canceladas' => 1]);
        $this->assertSame('CANCELADA', $reserva->fresh()->estado);
        $this->assertDatabaseHas('notificaciones', ['usuario_id' => $reserva->solicitante_id, 'reserva_id' => $reserva->id, 'titulo' => "Su reserva #{$reserva->id} fue cancelada"]);
    }

    public function test_bloqueo_de_un_solo_laboratorio_no_afecta_reservas_de_otros(): void
    {
        $lunes = $this->lunes();
        $otra = $this->reservaAprobada('LAB-42', $lunes->toDateString());
        $this->como('encargado@ficct.edu.bo');

        $this->bloquear(['fecha_desde' => $lunes->toDateString(), 'laboratorio_id' => Laboratorio::where('codigo', 'LAB-41')->value('id')])->assertCreated();
        $this->assertSame('APROBADA', $otra->fresh()->estado);
    }

    public function test_laboratorio_a_mantenimiento_cancela_sus_reservas_futuras_y_notifica(): void
    {
        $reserva = $this->reservaAprobada('LAB-43', $this->lunes()->toDateString());
        $lab = Laboratorio::where('codigo', 'LAB-43')->first();
        $this->como('encargado@ficct.edu.bo');
        $datos = $lab->only(['codigo', 'nombre', 'capacidad', 'ubicacion', 'tarifa_hora']) + ['estado' => 'MANTENIMIENTO'];

        $this->putJson("/api/laboratorios/{$lab->id}", $datos)->assertStatus(409)->assertJson(['requiere_confirmacion' => true]);
        $this->assertSame('DISPONIBLE', $lab->fresh()->estado);

        $this->putJson("/api/laboratorios/{$lab->id}", $datos + ['confirmar' => true])->assertOk();
        $this->assertSame('MANTENIMIENTO', $lab->fresh()->estado);
        $this->assertSame('CANCELADA', $reserva->fresh()->estado);
        $this->assertDatabaseHas('notificaciones', ['usuario_id' => $reserva->solicitante_id, 'reserva_id' => $reserva->id]);
    }

    public function test_notificaciones_de_reservas_y_campanita(): void
    {
        $lunes = $this->lunes();
        $docente = $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/reservas', ['laboratorio_id' => Laboratorio::where('codigo', 'LAB-42')->value('id'), 'fecha' => $lunes->toDateString(),
            'hora_inicio' => '21:30', 'hora_fin' => '22:00', 'tipo' => 'PRACTICA', 'motivo' => 'Práctica de prueba'])->assertCreated()->json('data.id');

        // El Encargado recibe el aviso de la nueva solicitud
        $enc = User::where('email', 'encargado@ficct.edu.bo')->first();
        $this->assertDatabaseHas('notificaciones', ['usuario_id' => $enc->id, 'titulo' => "Nueva solicitud de reserva #$id"]);

        // La aprueba y el docente recibe el aviso
        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/reservas/$id/aprobar")->assertOk();
        $this->como('docente@ficct.edu.bo');
        $r = $this->getJson('/api/notificaciones')->assertOk();
        $this->assertSame("Su reserva #$id fue APROBADA", $r->json('data.0.titulo'));
        $this->assertGreaterThanOrEqual(1, $r->json('no_leidas'));

        // Marcar como leída / todas; no se pueden leer las de otro usuario
        $this->putJson('/api/notificaciones/'.$r->json('data.0.id').'/leer')->assertOk();
        $ajena = DB::table('notificaciones')->where('usuario_id', $enc->id)->value('id');
        $this->putJson("/api/notificaciones/$ajena/leer")->assertStatus(404);
        $this->putJson('/api/notificaciones/leer-todas')->assertOk();
        $this->assertSame(0, $this->getJson('/api/notificaciones')->json('no_leidas'));
        $this->assertSame($docente->id, DB::table('notificaciones')->where('reserva_id', $id)->where('titulo', 'like', '%APROBADA%')->value('usuario_id'));
    }
}
