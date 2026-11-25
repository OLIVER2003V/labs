<?php

namespace Tests\Feature;

use App\Models\Asistencia;
use App\Models\Permuta;
use App\Models\Turno;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** CU5 - Permutas de turno (3 fases + asistencia del reemplazante) y chat interno */
class PermutasMensajesTest extends SistemaTestCase
{
    private function id(string $email): int
    {
        return User::where('email', $email)->value('id');
    }

    /** Turno del lunes de auxiliar@ (07:00–11:00) y el próximo lunes */
    private function turnoLunes(): array
    {
        $turno = Turno::where('auxiliar_id', $this->id('auxiliar@ficct.edu.bo'))->where('dia_semana', 1)->firstOrFail();

        return [$turno, Carbon::today()->next(Carbon::MONDAY)->toDateString()];
    }

    private function solicitar(array $cambios = []): \Illuminate\Testing\TestResponse
    {
        [$turno, $lunes] = $this->turnoLunes();

        return $this->postJson('/api/permutas', array_merge([
            'turno_id' => $turno->id, 'fecha' => $lunes, 'reemplazante_id' => $this->id('vflores@ficct.edu.bo'), 'motivo' => 'Examen final de INF412',
        ], $cambios));
    }

    private function notificaciones(string $email): int
    {
        return DB::table('notificaciones')->where('usuario_id', $this->id($email))->count();
    }

    public function test_flujo_completo_y_la_asistencia_pasa_al_reemplazante(): void
    {
        [$turno, $lunes] = $this->turnoLunes();
        $this->como('auxiliar@ficct.edu.bo');
        $id = $this->solicitar()->assertCreated()->assertJsonPath('data.estado', 'PENDIENTE')->json('data.id');
        $this->assertSame(1, $this->notificaciones('vflores@ficct.edu.bo'));

        // El Encargado no puede aprobar antes de que el reemplazante acepte
        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/permutas/$id/aprobar")->assertStatus(422)->assertJsonFragment(['message' => 'El reemplazante todavía no aceptó esta permuta.']);

        // Solo el reemplazante responde
        $this->como('aquiroga@ficct.edu.bo');
        $this->putJson("/api/permutas/$id/responder", ['aceptar' => true])->assertForbidden();
        $this->como('vflores@ficct.edu.bo');
        $this->putJson("/api/permutas/$id/responder", ['aceptar' => true])->assertOk()->assertJsonPath('data.estado', 'ACEPTADA');

        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/permutas/$id/aprobar")->assertOk()->assertJsonPath('data.estado', 'APROBADA');

        // Ese lunes a las 07:05: marca el reemplazante, no el titular
        $this->travelTo(Carbon::parse("$lunes 07:05"));
        $this->como('auxiliar@ficct.edu.bo');
        $this->postJson('/api/asistencias/entrada')->assertStatus(422);
        $this->como('vflores@ficct.edu.bo');
        $this->postJson('/api/asistencias/entrada')->assertCreated()->assertJsonPath('data.turno_id', $turno->id);

        // Si nadie hubiera marcado, la falta sería del reemplazante
        Asistencia::where('turno_id', $turno->id)->delete();
        $this->travelTo(Carbon::parse("$lunes 23:00"));
        $this->como('encargado@ficct.edu.bo');
        $this->postJson('/api/asistencias/faltas', ['fecha' => $lunes])->assertOk();
        $this->assertSame($this->id('vflores@ficct.edu.bo'), Asistencia::where('turno_id', $turno->id)->value('auxiliar_id'));
    }

    public function test_reglas_al_solicitar(): void
    {
        [$turno, $lunes] = $this->turnoLunes();
        $this->como('vflores@ficct.edu.bo');
        $this->solicitar(['reemplazante_id' => $this->id('aquiroga@ficct.edu.bo')])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Solo puede pedir permuta de sus propios turnos.']);

        $this->como('auxiliar@ficct.edu.bo');
        $this->solicitar(['fecha' => Carbon::parse($lunes)->addDay()->toDateString()])->assertStatus(422)
            ->assertJsonFragment(['message' => 'El '.Carbon::parse($lunes)->addDay()->format('d/m/Y').' es martes, pero el turno es los lunes.']);
        $this->solicitar(['reemplazante_id' => $this->id('ltorrez@ficct.edu.bo')])->assertStatus(422); // también trabaja de 07:00 a 11:00
        $this->solicitar(['reemplazante_id' => $this->id('docente@ficct.edu.bo')])->assertStatus(422)
            ->assertJsonFragment(['message' => 'El reemplazante debe ser un auxiliar activo.']);
        $this->solicitar(['reemplazante_id' => $this->id('auxiliar@ficct.edu.bo')])->assertStatus(422);

        $this->solicitar()->assertCreated();
        $this->solicitar(['reemplazante_id' => $this->id('aquiroga@ficct.edu.bo')])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Ese turno ya tiene una permuta en curso para esa fecha.']);

        // Un docente no tiene permutas
        $this->como('docente@ficct.edu.bo');
        $this->getJson('/api/permutas')->assertForbidden();
    }

    public function test_cada_auxiliar_ve_solo_sus_permutas_y_puede_cancelar_la_suya(): void
    {
        $this->como('auxiliar@ficct.edu.bo');
        $id = $this->solicitar()->json('data.id');

        $this->como('aquiroga@ficct.edu.bo');
        $this->assertNotContains($id, array_column($this->getJson('/api/permutas')->json('data'), 'id'));
        $this->putJson("/api/permutas/$id/cancelar")->assertForbidden();

        $this->como('encargado@ficct.edu.bo');
        $this->assertContains($id, array_column($this->getJson('/api/permutas')->json('data'), 'id'));

        $this->como('auxiliar@ficct.edu.bo');
        $this->putJson("/api/permutas/$id/cancelar")->assertOk();
        $this->assertSame('CANCELADA', Permuta::find($id)->estado);
        $this->solicitar()->assertCreated(); // cancelada ya no ocupa el turno
    }

    public function test_chat_entre_auxiliar_y_encargado(): void
    {
        $enc = $this->id('encargado@ficct.edu.bo');
        $this->como('auxiliar@ficct.edu.bo');
        $this->postJson('/api/mensajes', ['destinatario_id' => $enc, 'contenido' => '  Hola, ¿me aprueba la permuta del lunes?  '])
            ->assertCreated()->assertJsonPath('data.contenido', 'Hola, ¿me aprueba la permuta del lunes?');
        $this->postJson('/api/mensajes', ['destinatario_id' => $this->id('auxiliar@ficct.edu.bo'), 'contenido' => 'yo'])->assertStatus(422);
        $this->postJson('/api/mensajes', ['destinatario_id' => $this->id('docente@ficct.edu.bo'), 'contenido' => 'hola'])->assertStatus(422);
        $this->postJson('/api/mensajes', ['destinatario_id' => $enc, 'contenido' => '   '])->assertStatus(422);

        $this->como('encargado@ficct.edu.bo');
        $this->getJson('/api/notificaciones')->assertJsonPath('avisos./mensajes', 1);
        $this->getJson('/api/mensajes')->assertJsonPath('no_leidos', 1);
        $bitacoraAntes = DB::table('bitacora')->count();
        $this->getJson('/api/mensajes/'.$this->id('auxiliar@ficct.edu.bo'))->assertOk()
            ->assertJsonCount(1, 'mensajes')->assertJsonPath('mensajes.0.mio', false);
        $this->assertSame($bitacoraAntes, DB::table('bitacora')->count()); // las consultas automáticas no llenan la bitácora
        $this->getJson('/api/mensajes')->assertJsonPath('no_leidos', 0);

        $this->como('docente@ficct.edu.bo');
        $this->getJson('/api/mensajes')->assertForbidden();
    }
}
