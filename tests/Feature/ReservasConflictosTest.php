<?php

namespace Tests\Feature;

use App\Models\Horario;
use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Models\User;
use App\Services\Disponibilidad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** CU4 - Solicitudes que chocan, auto-aprobación del Encargado, prioridad de la clase y laboratorios alternativos */
class ReservasConflictosTest extends SistemaTestCase
{
    private string $fecha;
    private Laboratorio $lab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fecha = $this->martesLibre();
        // Un laboratorio libre ese martes de 20:00 a 21:30
        $this->lab = Laboratorio::where('estado', 'DISPONIBLE')->orderBy('codigo')->get()
            ->first(fn ($l) => ! Disponibilidad::conflictos($l->id, $this->fecha, '20:00', '21:30'));
    }

    private function solicitud(array $cambios = []): array
    {
        return array_merge(['laboratorio_id' => $this->lab->id, 'fecha' => $this->fecha, 'hora_inicio' => '20:00', 'hora_fin' => '21:30',
            'tipo' => 'EXAMEN', 'motivo' => 'Segundo parcial de prueba'], $cambios);
    }

    private function notificaciones(string $email): int
    {
        return DB::table('notificaciones')->where('usuario_id', User::where('email', $email)->value('id'))->count();
    }

    public function test_al_aprobar_se_rechazan_las_solicitudes_que_piden_el_mismo_horario(): void
    {
        $this->como('docente@ficct.edu.bo');
        $a = $this->postJson('/api/reservas', $this->solicitud())->assertCreated()->json('data.id');
        $this->como('jsuarez@ficct.edu.bo');
        $b = $this->postJson('/api/reservas', $this->solicitud(['hora_inicio' => '21:00', 'hora_fin' => '22:00']))->assertCreated()->json('data.id');
        $c = $this->postJson('/api/reservas', $this->solicitud(['hora_inicio' => '07:00', 'hora_fin' => '07:30', 'motivo' => 'Otra hora, no choca']))->json('data.id');
        $antes = $this->notificaciones('jsuarez@ficct.edu.bo');

        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/reservas/$a/aprobar")->assertOk()
            ->assertJsonFragment(['message' => 'Reserva aprobada. Se rechazaron 1 solicitud(es) que pedían el mismo horario y se avisó a sus solicitantes.']);

        $this->assertSame('RECHAZADA', Reserva::find($b)->estado);
        $this->assertStringContainsString("asignado a la reserva #$a", Reserva::find($b)->observacion);
        $this->assertNotSame('RECHAZADA', Reserva::find($c)?->estado); // otro horario: no se toca
        $this->assertSame($antes + 1, $this->notificaciones('jsuarez@ficct.edu.bo'));
    }

    public function test_la_reserva_del_encargado_queda_aprobada_y_la_del_docente_pendiente(): void
    {
        $this->como('docente@ficct.edu.bo');
        $pendiente = $this->postJson('/api/reservas', $this->solicitud())->assertCreated()
            ->assertJsonPath('data.estado', 'PENDIENTE')->json('data.id');

        $this->como('encargado@ficct.edu.bo');
        $this->postJson('/api/reservas', $this->solicitud(['motivo' => 'Reunión de coordinación']))->assertCreated()
            ->assertJsonPath('data.estado', 'APROBADA')
            ->assertJsonFragment(['message' => 'Reserva registrada y aprobada. Se rechazaron 1 solicitud(es) pendiente(s) que pedían ese horario.']);
        $this->assertSame('RECHAZADA', Reserva::find($pendiente)->estado);
    }

    public function test_ante_un_choque_propone_laboratorios_libres_con_su_id(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $this->postJson('/api/reservas', $this->solicitud())->assertCreated();

        $this->como('docente@ficct.edu.bo');
        $alt = $this->postJson('/api/reservas', $this->solicitud())->assertStatus(422)->json('alternativas');
        $this->assertNotEmpty($alt);
        $this->assertSame('laboratorio_id', $alt[0]['campo']);
        $this->assertNotContains($this->lab->id, array_column($alt, 'id'));

        // Con el laboratorio sugerido, la solicitud pasa
        $this->postJson('/api/reservas', $this->solicitud(['laboratorio_id' => $alt[0]['id']]))->assertCreated();
    }

    /** Una clase de los martes afecta TODOS los martes del semestre: se descartan otras reservas de los datos de prueba en ese horario */
    private function sinOtrasReservasLosMartes(): void
    {
        Reserva::where('laboratorio_id', $this->lab->id)->whereIn('estado', ['PENDIENTE', 'APROBADA'])
            ->whereRaw('EXTRACT(ISODOW FROM fecha) = 2')->where('hora_inicio', '<', '21:45:00')->where('hora_fin', '>', '20:00:00')
            ->update(['estado' => 'CANCELADA']);
    }

    public function test_una_clase_nueva_tiene_prioridad_y_cancela_las_reservas_que_pisa(): void
    {
        $this->sinOtrasReservasLosMartes();
        $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/reservas', $this->solicitud())->assertCreated()->json('data.id');
        $antes = $this->notificaciones('docente@ficct.edu.bo');

        $docente = User::whereHas('rol', fn ($r) => $r->where('codigo', 'DOC'))->where('estado', 'ACTIVO')->get()
            ->first(fn ($u) => ! Horario::where('docente_id', $u->id)->where('dia_semana', 2)->where('hora_fin', '>', '20:00:00')->exists());
        $clase = ['laboratorio_id' => $this->lab->id, 'materia_id' => DB::table('materias')->value('id'), 'docente_id' => $docente->id,
            'grupo' => 'ZZ', 'dia_semana' => 2, 'hora_inicio' => '20:15', 'hora_fin' => '21:45'];

        $this->como('encargado@ficct.edu.bo');
        $this->postJson('/api/horarios', $clase)->assertStatus(409)->assertJsonPath('requiere_confirmacion', true);
        $this->assertSame('PENDIENTE', Reserva::find($id)->estado); // sin confirmar no se toca nada

        $this->postJson('/api/horarios', $clase + ['confirmar' => true])->assertCreated()
            ->assertJsonFragment(['message' => 'Clase registrada y se cancelaron 1 reserva(s) con aviso a sus solicitantes.']);
        $this->assertSame('CANCELADA', Reserva::find($id)->estado);
        $this->assertSame($antes + 1, $this->notificaciones('docente@ficct.edu.bo'));
    }

    public function test_importar_horario_avisa_y_cancela_las_reservas_que_pisa(): void
    {
        $this->sinOtrasReservasLosMartes();
        $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/reservas', $this->solicitud())->assertCreated()->json('data.id');

        $docente = User::whereHas('rol', fn ($r) => $r->where('codigo', 'DOC'))->where('estado', 'ACTIVO')->get()
            ->first(fn ($u) => ! Horario::where('docente_id', $u->id)->where('dia_semana', 2)->where('hora_fin', '>', '20:00:00')->exists());
        $csv = "LAB;DIA;INICIO;FIN;SIGLA;MATERIA;GRUPO;DOCENTE\n{$this->lab->codigo};MARTES;20:00;21:30;ZZZ998;PRUEBA;ZZ;{$docente->codigo}\n";
        $datos = ['semestre_id' => Semestre::activo()->id];

        $this->como('encargado@ficct.edu.bo');
        $revision = $this->post('/api/horarios/importar', $datos + ['archivo' => UploadedFile::fake()->createWithContent('h.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->json('filas.0');
        $this->assertSame(1, $revision['reservas']);
        $this->assertSame('PENDIENTE', Reserva::find($id)->estado);

        $this->post('/api/horarios/importar', $datos + ['guardar' => 1, 'archivo' => UploadedFile::fake()->createWithContent('h.csv', $csv)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('reservas_canceladas', 1);
        $this->assertSame('CANCELADA', Reserva::find($id)->estado);
    }
}
