<?php

namespace Tests\Feature;

use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** PSA y CUP: bloqueos por horario, en varios laboratorios y antes del inicio del semestre */
class PsaCupTest extends SistemaTestCase
{
    private function lab(string $codigo): int
    {
        return Laboratorio::where('codigo', $codigo)->value('id');
    }

    private function diaLibre(int $offset = 20): string
    {
        $d = Carbon::today()->addDays($offset)->next(Carbon::TUESDAY)->toDateString();
        DB::table('fechas_bloqueadas')->whereDate('fecha', $d)->delete();

        return $d;
    }

    public function test_psa_se_puede_bloquear_antes_del_inicio_del_semestre_hasta_90_dias(): void
    {
        $inicio = Carbon::today()->addMonths(5)->startOfWeek();
        $sem = Semestre::updateOrCreate(['gestion' => '1-2027'], ['fecha_inicio' => $inicio, 'fecha_fin' => $inicio->copy()->addMonths(5), 'estado' => 'PLANIFICADO']);
        $this->como('encargado@ficct.edu.bo');
        $base = ['semestre_id' => $sem->id, 'tipo' => 'PSA', 'motivo' => 'Prueba de Suficiencia Académica', 'laboratorios' => [$this->lab('LAB-41')]];

        $psa = $inicio->copy()->subDays(30)->next(Carbon::MONDAY)->toDateString();
        $this->postJson('/api/fechas-bloqueadas', $base + ['fecha_desde' => $psa])->assertCreated();

        $this->postJson('/api/fechas-bloqueadas', $base + ['fecha_desde' => $inicio->copy()->subDays(120)->toDateString()])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Para el semestre 1-2027 se puede bloquear del '.$inicio->copy()->subDays(90)->format('d/m/Y')
                .' (90 días antes del inicio, para PSA o CUP) al '.$inicio->copy()->addMonths(5)->format('d/m/Y').'.']);
    }

    public function test_bloqueo_por_horas_en_varios_laboratorios_deja_libre_el_resto_del_dia(): void
    {
        $dia = $this->diaLibre();
        $this->como('encargado@ficct.edu.bo');
        $this->postJson('/api/fechas-bloqueadas', [
            'semestre_id' => Semestre::where('estado', 'ACTIVO')->value('id'), 'fecha_desde' => $dia, 'tipo' => 'PSA', 'motivo' => 'PSA mañana',
            'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'laboratorios' => [$this->lab('LAB-41'), $this->lab('LAB-42')],
        ])->assertCreated()->assertJson(['creados' => 2]);

        $this->como('docente@ficct.edu.bo');
        $reserva = fn ($lab, $hi, $hf) => $this->postJson('/api/reservas', ['laboratorio_id' => $this->lab($lab), 'fecha' => $dia,
            'hora_inicio' => $hi, 'hora_fin' => $hf, 'tipo' => 'PRACTICA', 'motivo' => 'Práctica de prueba']);

        $reserva('LAB-41', '09:00', '10:00')->assertStatus(422)->assertJsonPath('conflictos.0.tipo', 'BLOQUEO'); // dentro de la PSA
        $reserva('LAB-41', '21:00', '22:00')->assertCreated();                                                  // fuera del horario de la PSA
        $this->assertSame(0, DB::table('fechas_bloqueadas')->whereDate('fecha', $dia)->where('laboratorio_id', $this->lab('LAB-43'))->count());
    }

    public function test_psa_en_la_manana_y_cup_en_la_tarde_el_mismo_dia_pero_no_superpuestos(): void
    {
        $dia = $this->diaLibre();
        $this->como('encargado@ficct.edu.bo');
        $base = ['semestre_id' => Semestre::where('estado', 'ACTIVO')->value('id'), 'fecha_desde' => $dia, 'laboratorios' => [$this->lab('LAB-44')]];

        $this->postJson('/api/fechas-bloqueadas', $base + ['tipo' => 'PSA', 'motivo' => 'PSA', 'hora_inicio' => '08:00', 'hora_fin' => '12:00'])->assertCreated();
        $this->postJson('/api/fechas-bloqueadas', $base + ['tipo' => 'CUP', 'motivo' => 'Curso Preuniversitario', 'hora_inicio' => '14:00', 'hora_fin' => '18:00'])->assertCreated();
        $this->postJson('/api/fechas-bloqueadas', $base + ['tipo' => 'CUP', 'motivo' => 'Se cruza', 'hora_inicio' => '11:00', 'hora_fin' => '13:00'])
            ->assertStatus(422)->assertJson(['message' => 'Ya existe un bloqueo para esa fecha y horario.']);
    }

    public function test_bloqueo_por_horas_solo_cancela_reservas_que_se_cruzan(): void
    {
        $dia = $this->diaLibre();
        $doc = User::where('email', 'docente@ficct.edu.bo')->value('id');
        $tarde = Reserva::create(['laboratorio_id' => $this->lab('LAB-45'), 'solicitante_id' => $doc, 'fecha' => $dia, 'hora_inicio' => '21:00',
            'hora_fin' => '22:00', 'tipo' => 'EXAMEN', 'motivo' => 'Parcial noche', 'estado' => 'APROBADA']);
        $this->como('encargado@ficct.edu.bo');

        // CUP de 08:00 a 12:00: la reserva de la noche no se toca y no se pide confirmar
        $this->postJson('/api/fechas-bloqueadas', ['semestre_id' => Semestre::where('estado', 'ACTIVO')->value('id'), 'fecha_desde' => $dia,
            'tipo' => 'CUP', 'motivo' => 'CUP', 'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'laboratorios' => [$this->lab('LAB-45')]])
            ->assertCreated()->assertJson(['canceladas' => 0]);
        $this->assertSame('APROBADA', $tarde->fresh()->estado);
    }

    public function test_se_puede_reservar_fuera_del_semestre_y_sin_semestre_activo(): void
    {
        $this->como('docente@ficct.edu.bo');
        $lejos = Carbon::today()->addMonths(6)->next(Carbon::WEDNESDAY)->toDateString(); // después del fin del semestre activo
        $datos = ['laboratorio_id' => $this->lab('LAB-42'), 'hora_inicio' => '10:00', 'hora_fin' => '12:00', 'tipo' => 'EVENTO', 'motivo' => 'Curso de verano'];

        $this->postJson('/api/reservas', $datos + ['fecha' => $lejos])->assertCreated();

        Semestre::where('estado', 'ACTIVO')->update(['estado' => 'CERRADO']);
        $this->postJson('/api/reservas', $datos + ['fecha' => $this->diaLibre(10)])->assertCreated();

        $this->postJson('/api/reservas', $datos + ['fecha' => Carbon::today()->addYears(2)->toDateString()])
            ->assertStatus(422)->assertJson(['message' => 'Solo se puede reservar con hasta un año de anticipación.']);
    }
}
