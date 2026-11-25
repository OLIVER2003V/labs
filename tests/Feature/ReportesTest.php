<?php

namespace Tests\Feature;

use App\Models\Laboratorio;
use App\Models\Semestre;
use App\Services\Disponibilidad;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/** CU8 - Reportes: cálculos, permisos y exportación a Excel con formato */
class ReportesTest extends SistemaTestCase
{
    public function test_los_cinco_reportes_responden_con_la_misma_estructura(): void
    {
        $this->como('encargado@ficct.edu.bo');
        foreach (['ocupacion', 'reservas', 'incidencias', 'asistencia', 'semestre'] as $tipo) {
            $this->getJson("/api/reportes/$tipo")->assertOk()
                ->assertJsonStructure(['titulo', 'subtitulo', 'resumen' => [['etiqueta', 'valor']], 'secciones' => [['titulo', 'columnas', 'filas']], 'grafico']);
        }
        $this->getJson('/api/reportes/inventado')->assertNotFound();
        $this->getJson('/api/reportes/reservas?desde=2025-01-01&hasta=2026-06-01')->assertStatus(422);

        $this->como('director@ficct.edu.bo');
        $this->getJson('/api/reportes/reservas')->assertOk();
        $this->como('docente@ficct.edu.bo');
        $this->getJson('/api/reportes/reservas')->assertForbidden();
    }

    public function test_ocupacion_suma_clases_y_reservas_y_descuenta_feriados(): void
    {
        $martes = $this->martesLibre();
        $lab = Laboratorio::where('estado', 'DISPONIBLE')->orderBy('codigo')->get()
            ->first(fn ($l) => ! Disponibilidad::conflictos($l->id, $martes, '20:00', '21:30'));
        $clases = (float) DB::table('horarios')->where('semestre_id', Semestre::activo()->id)->where('laboratorio_id', $lab->id)->where('dia_semana', 2)
            ->selectRaw('COALESCE(SUM(EXTRACT(EPOCH FROM (hora_fin - hora_inicio)) / 3600), 0) AS h')->value('h');

        $this->como('encargado@ficct.edu.bo'); // su reserva queda aprobada
        $this->postJson('/api/reservas', ['laboratorio_id' => $lab->id, 'fecha' => $martes, 'hora_inicio' => '20:00', 'hora_fin' => '21:30',
            'tipo' => 'EXAMEN', 'motivo' => 'Examen para el reporte'])->assertCreated();

        $fila = collect($this->getJson("/api/reportes/ocupacion?desde=$martes&hasta=$martes")->json('secciones.0.filas'))->firstWhere(0, $lab->codigo);
        $this->assertSame(1, $fila[3]);                                     // 1 día hábil
        $this->assertEquals(15, $fila[4]);                                  // 15 h disponibles
        $this->assertEquals(round($clases, 1), $fila[5]);                   // horas de clase del martes
        $this->assertEquals(1.5, $fila[6]);                                 // 1.5 h reservadas
        $this->assertEquals(round(($clases + 1.5) * 100 / 15, 1), $fila[8]); // % de ocupación

        // Feriado: el día deja de contar
        DB::table('fechas_bloqueadas')->insert(['fecha' => $martes, 'tipo' => 'FERIADO', 'motivo' => 'Feriado de prueba',
            'semestre_id' => Semestre::activo()->id, 'created_at' => now(), 'updated_at' => now()]);
        $fila = collect($this->getJson("/api/reportes/ocupacion?desde=$martes&hasta=$martes")->json('secciones.0.filas'))->firstWhere(0, $lab->codigo);
        $this->assertSame(0, $fila[3]);
    }

    public function test_excel_del_reporte_y_de_una_lista_salen_con_formato(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $resp = $this->get('/api/reportes/reservas/excel')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('reporte_de_reservas_', $resp->headers->get('Content-Disposition'));

        $ruta = $resp->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $estilos = $zip->getFromName('xl/styles.xml');
        $this->assertStringContainsString('<left style="thin">', $estilos);   // celdas con bordes
        $this->assertStringContainsString('FF0F2A4A', $estilos);               // encabezado azul de la marca
        $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet5.xml'));  // resumen + 4 tablas = 5 hojas
        $this->assertStringContainsString('Resumen', $zip->getFromName('xl/workbook.xml'));
        $zip->close();

        // Botón "Excel" de cualquier lista
        $this->post('/api/exportar/excel', ['titulo' => 'Inventario', 'subtitulo' => 'Estado: OPERATIVO', 'columnas' => ['Código', 'Estado'],
            'filas' => [['PC-01', 'OPERATIVO'], ['PC-02', 'OPERATIVO']]], ['Accept' => 'application/json'])->assertOk();
        $this->postJson('/api/exportar/excel', ['titulo' => 'X', 'columnas' => ['A'], 'filas' => array_fill(0, 5001, ['x'])])
            ->assertStatus(422)->assertJsonValidationErrors('filas');
    }
}
