<?php

namespace Tests\Feature;

use App\Models\Horario;
use App\Models\Materia;
use App\Models\Semestre;
use App\Services\LectorHorario;
use Illuminate\Http\UploadedFile;

/** CU3 - Importar el horario semestral desde Excel / CSV */
class ImportarHorarioTest extends SistemaTestCase
{
    private function semestreNuevo(): Semestre
    {
        return Semestre::updateOrCreate(['gestion' => '1-2027'], [
            'fecha_inicio' => now()->addMonths(4), 'fecha_fin' => now()->addMonths(9), 'estado' => 'PLANIFICADO',
        ]);
    }

    private function csv(array $filas): UploadedFile
    {
        $texto = "LAB;DIA;INICIO;FIN;SIGLA;MATERIA;GRUPO;DOCENTE\n";
        foreach ($filas as $f) {
            $texto .= implode(';', $f)."\n";
        }

        return UploadedFile::fake()->createWithContent('horario.csv', $texto);
    }

    public function test_revisar_no_guarda_y_marca_cada_error(): void
    {
        $sem = $this->semestreNuevo();
        $this->como('encargado@ficct.edu.bo');
        $archivo = $this->csv([
            ['LAB-41', 'LUNES', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SA', 'DOC-001'],       // ok
            ['LAB-41', 'LUNES', '08:00', '09:30', 'INF412', 'SISTEMAS II', 'SB', 'DOC-002'],          // choca con la fila 2
            ['LAB-46', 'MARTES', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SC', 'DOC-003'],      // lab en mantenimiento
            ['LAB-42', 'DOMINGO', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SD', 'DOC-003'],     // día inválido
            ['LAB-42', 'MIÉRCOLES', '10:00', '09:00', 'INF513', 'TECNOLOGIA WEB', 'SE', 'DOC-003'],   // fin < inicio
            ['LAB-42', 'JUEVES', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SF', '9999'],         // docente inexistente
        ]);

        $r = $this->post('/api/horarios/importar', ['archivo' => $archivo, 'semestre_id' => $sem->id, 'guardar' => 0], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['semestre' => '1-2027', 'validas' => 1, 'errores' => 5])->json('filas');

        $this->assertTrue($r[0]['ok']);
        $this->assertStringContainsString('Choca con la fila 2', $r[1]['mensaje']);
        $this->assertStringContainsString('MANTENIMIENTO', $r[2]['mensaje']);
        $this->assertStringContainsString('Día "DOMINGO"', $r[3]['mensaje']);
        $this->assertSame('La hora de fin debe ser posterior a la de inicio.', $r[4]['mensaje']);
        $this->assertStringContainsString('"9999"', $r[5]['mensaje']);
        $this->assertSame(0, Horario::where('semestre_id', $sem->id)->count());
    }

    public function test_importar_guarda_las_validas_y_crea_materias_nuevas(): void
    {
        $sem = $this->semestreNuevo();
        $this->como('encargado@ficct.edu.bo');
        $archivo = $this->csv([
            ['LAB-41', 'LU', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'sa', 'DOC-001'],
            ['LAB-42', '3', '7:00', '08:30', 'ZZZ999', 'MATERIA DE PRUEBA', 'SB', 'jsuarez@ficct.edu.bo'],
            ['LAB-43', 'SABADO', '07:00', '08:30', 'QQQ111', '', 'SC', 'DOC-003'],  // materia nueva sin nombre
        ]);

        $this->post('/api/horarios/importar', ['archivo' => $archivo, 'semestre_id' => $sem->id, 'guardar' => 1], ['Accept' => 'application/json'])
            ->assertCreated()->assertJson(['creadas' => 2, 'materias_nuevas' => 1, 'errores' => 1]);

        $this->assertSame(2, Horario::where('semestre_id', $sem->id)->count());
        $this->assertTrue(Materia::where('sigla', 'ZZZ999')->exists());
        $this->assertFalse(Materia::where('sigla', 'QQQ111')->exists());
        $this->assertTrue(Horario::where('semestre_id', $sem->id)->where('grupo', 'SA')->where('dia_semana', 1)->exists());

        // Volver a subir el mismo archivo: ahora todas chocan con lo ya importado
        $this->post('/api/horarios/importar', ['archivo' => $this->csv([['LAB-41', 'LU', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SA', 'DOC-001']]),
            'semestre_id' => $sem->id, 'guardar' => 1], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJson(['message' => 'Ninguna fila es válida: no se importó nada.']);
    }

    public function test_lee_la_plantilla_xlsx_generada(): void
    {
        $ruta = LectorHorario::plantilla([['LAB-41', 'LUNES', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SA', 'DOC-001']]);
        $filas = LectorHorario::leer($ruta, 'plantilla.xlsx');
        @unlink($ruta);

        $this->assertCount(1, $filas);
        $this->assertSame(['LAB-41', 'LUNES', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SA', 'DOC-001'], $filas[0]['valores']);
    }

    public function test_plantilla_y_semestre_cerrado(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $this->get('/api/horarios/plantilla')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $cerrado = Semestre::updateOrCreate(['gestion' => '1-2020'], ['fecha_inicio' => '2020-02-01', 'fecha_fin' => '2020-07-01', 'estado' => 'CERRADO']);
        $this->post('/api/horarios/importar', ['archivo' => $this->csv([['LAB-41', 'LU', '07:00', '08:30', 'INF513', 'X', 'SA', 'DOC-001']]), 'semestre_id' => $cerrado->id],
            ['Accept' => 'application/json'])->assertStatus(422)->assertJson(['message' => 'El semestre 1-2020 está cerrado.']);

        $this->post('/api/horarios/importar', ['archivo' => UploadedFile::fake()->create('horario.pdf', 10)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('archivo');
    }

    public function test_un_docente_no_puede_importar(): void
    {
        $this->como('docente@ficct.edu.bo');
        $this->post('/api/horarios/importar', ['archivo' => $this->csv([['LAB-41', 'LU', '07:00', '08:30', 'INF513', 'X', 'SA', 'DOC-001']])],
            ['Accept' => 'application/json'])->assertForbidden();
        $this->get('/api/horarios/plantilla')->assertForbidden();
    }
}
