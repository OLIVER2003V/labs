<?php

namespace Tests\Feature;

use App\Models\Asistencia;
use App\Models\Turno;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** CU7 - Ventana de marcado, faltas automáticas, permisos (con justificación retroactiva), feriados y salida anticipada */
class AsistenciaReglasTest extends SistemaTestCase
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

    /** Turno del martes de auxiliar@ (07:00–11:00) */
    private function turno(string $email = 'auxiliar@ficct.edu.bo'): Turno
    {
        return Turno::where('auxiliar_id', $this->id($email))->where('dia_semana', 2)->firstOrFail();
    }

    private function a(string $hora): void
    {
        $this->travelTo(Carbon::parse("{$this->martes} $hora"));
    }

    public function test_ventana_de_entrada_15_antes_10_puntual_30_limite(): void
    {
        $this->como('auxiliar@ficct.edu.bo');
        $this->a('06:40');
        $this->postJson('/api/asistencias/entrada')->assertStatus(422)
            ->assertJsonFragment(['message' => 'Todavía no puede marcar: su turno empieza a las 07:00 y puede marcar desde las 06:45.']);
        $this->getJson('/api/asistencias/hoy')->assertOk()->assertJsonPath('0.puede_entrar', false)->assertJsonPath('0.limite', '07:30');

        $this->a('07:25');
        $this->getJson('/api/asistencias/hoy')->assertJsonPath('0.puede_entrar', true);
        $this->postJson('/api/asistencias/entrada')->assertCreated()->assertJsonPath('data.estado', 'ATRASO');
        $this->postJson('/api/asistencias/entrada')->assertStatus(422);

        // Salida anticipada: se registra con aviso
        $this->a('10:30');
        $this->postJson('/api/asistencias/salida')->assertOk()->assertJsonPath('anticipada', true)
            ->assertJsonFragment(['message' => 'Salida registrada a las 10:30. Atención: salió 30 min antes del fin de su turno (11:00).']);
        $this->assertStringContainsString('Salida anticipada', Asistencia::where('turno_id', $this->turno()->id)->whereDate('fecha', $this->martes)->value('observacion'));
    }

    public function test_pasados_30_minutos_no_marca_y_queda_falta_automatica(): void
    {
        $this->como('auxiliar@ficct.edu.bo');
        $this->a('07:31');
        $this->postJson('/api/asistencias/entrada')->assertStatus(422);

        // Al abrir la pantalla se registran las faltas de los turnos que pasaron su límite (no las de turnos que aún no empiezan)
        $this->getJson('/api/asistencias')->assertOk();
        $this->assertSame('FALTA', Asistencia::where('turno_id', $this->turno()->id)->whereDate('fecha', $this->martes)->value('estado'));
        $this->assertFalse(Asistencia::where('turno_id', $this->turno('vflores@ficct.edu.bo')->id)->whereDate('fecha', $this->martes)->exists()); // su turno es a las 11:00

        // Un permiso posterior la justifica (retroactivo)
        $this->como('encargado@ficct.edu.bo');
        $this->postJson('/api/asistencias/permiso', ['auxiliar_id' => $this->id('auxiliar@ficct.edu.bo'), 'fecha' => $this->martes, 'motivo' => 'Atención médica'])
            ->assertCreated()->assertJsonFragment(['message' => 'Permiso registrado. 1 falta(s) de ese día pasaron a PERMISO.']);
        $this->assertSame('PERMISO', Asistencia::where('turno_id', $this->turno()->id)->whereDate('fecha', $this->martes)->value('estado'));
    }

    public function test_permiso_previo_convierte_la_falta_en_permiso_y_reglas_del_permiso(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $this->travelTo(Carbon::parse($this->martes)->subDays(2)->setTime(9, 0));
        $id = $this->postJson('/api/asistencias/permiso', ['auxiliar_id' => $this->id('vflores@ficct.edu.bo'), 'fecha' => $this->martes, 'motivo' => 'Examen final de INF412'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/asistencias/permiso', ['auxiliar_id' => $this->id('vflores@ficct.edu.bo'), 'fecha' => $this->martes, 'motivo' => 'Otro motivo'])->assertStatus(422);
        $this->postJson('/api/asistencias/permiso', ['auxiliar_id' => $this->id('docente@ficct.edu.bo'), 'fecha' => $this->martes, 'motivo' => 'No es auxiliar'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'El permiso solo se registra para auxiliares.']);

        $this->a('11:45');
        $this->postJson('/api/asistencias/faltas', ['fecha' => $this->martes])->assertOk();
        $a = Asistencia::where('turno_id', $this->turno('vflores@ficct.edu.bo')->id)->whereDate('fecha', $this->martes)->first();
        $this->assertSame('PERMISO', $a->estado);
        $this->assertSame('Permiso: Examen final de INF412', $a->observacion);

        // Un permiso pasado no se anula; uno futuro sí
        $this->deleteJson("/api/asistencias/permisos/$id")->assertStatus(422);
        $futuro = $this->postJson('/api/asistencias/permiso', ['auxiliar_id' => $this->id('vflores@ficct.edu.bo'), 'fecha' => Carbon::parse($this->martes)->addWeek()->toDateString(), 'motivo' => 'Congreso'])->json('data.id');
        $this->deleteJson("/api/asistencias/permisos/$futuro")->assertOk();

        // El auxiliar ve sus permisos; otro auxiliar no los ve
        $this->como('vflores@ficct.edu.bo');
        $this->assertCount(1, $this->getJson('/api/asistencias/permisos')->json('data'));
        $this->como('aquiroga@ficct.edu.bo');
        $this->assertCount(0, $this->getJson('/api/asistencias/permisos')->json('data'));
    }

    public function test_en_feriado_no_se_cuentan_faltas(): void
    {
        DB::table('fechas_bloqueadas')->insert(['fecha' => $this->martes, 'tipo' => 'FERIADO', 'motivo' => 'Feriado de prueba',
            'semestre_id' => DB::table('semestres')->where('estado', 'ACTIVO')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $this->como('encargado@ficct.edu.bo');
        $this->a('23:00');
        $this->postJson('/api/asistencias/faltas', ['fecha' => $this->martes])->assertOk()->assertJsonPath('faltas', 0);
        $this->assertSame(0, Asistencia::whereDate('fecha', $this->martes)->count());
    }
}
