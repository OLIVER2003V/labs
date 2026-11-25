<?php

namespace App\Http\Controllers;

use App\Models\Semestre;
use App\Services\Reportes;
use App\Support\Excel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CU8 - Reportes y estadísticas.
 *   GET  /api/reportes/{tipo}?desde=&hasta=        tipo: ocupacion | reservas | incidencias | asistencia
 *   GET  /api/reportes/semestre?semestre_id=        (por defecto el semestre activo)
 *   GET  /api/reportes/{tipo}/excel?...             el mismo reporte en Excel con formato (una hoja por tabla)
 *   POST /api/exportar/excel                        cualquier tabla de la pantalla → Excel con formato (botón Exportar de las listas)
 */
class ReporteController extends Controller
{
    const TIPOS = ['ocupacion', 'reservas', 'incidencias', 'asistencia', 'semestre'];
    const MAX_DIAS = 366;

    public function mostrar(Request $request, string $tipo): JsonResponse
    {
        return response()->json($this->generar($request, $tipo));
    }

    public function excel(Request $request, string $tipo): BinaryFileResponse
    {
        $r = $this->generar($request, $tipo);
        $hojas = array_map(fn ($s) => ['nombre' => $s['titulo'], 'titulo_tabla' => $s['titulo'], 'columnas' => $s['columnas'], 'filas' => $s['filas'], 'total' => $s['total'] ?? null],
            $r['secciones']);
        // Primera hoja: el resumen (las tarjetas de la pantalla)
        array_unshift($hojas, ['nombre' => 'Resumen', 'titulo_tabla' => 'Resumen', 'columnas' => ['Indicador', 'Valor'],
            'filas' => array_map(fn ($x) => [$x['etiqueta'], $x['valor']], $r['resumen'])]);

        return $this->descargar(Excel::generar($r['titulo'], $r['subtitulo'], $hojas), $r['titulo']);
    }

    /** Botón "Exportar" de cualquier lista: la pantalla manda lo que muestra y el servidor arma el Excel con formato */
    public function exportarExcel(Request $request): BinaryFileResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:120'],
            'subtitulo' => ['nullable', 'string', 'max:300'],
            'columnas' => ['required', 'array', 'min:1', 'max:30'],
            'columnas.*' => ['nullable', 'string', 'max:80'],
            'filas' => ['present', 'array', 'max:5000'],
            'filas.*' => ['array'],
        ], ['filas.max' => 'Se pueden exportar hasta 5000 filas. Use los filtros para reducir la lista.']);

        $filas = array_map(fn ($f) => array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), array_values($f)), $d['filas']);

        return $this->descargar(Excel::generar($d['titulo'], $d['subtitulo'] ?? null,
            [['nombre' => $d['titulo'], 'columnas' => $d['columnas'], 'filas' => $filas]]), $d['titulo']);
    }

    private function generar(Request $request, string $tipo): array
    {
        abort_unless(in_array($tipo, self::TIPOS, true), 404, 'Reporte no disponible.');

        if ($tipo === 'semestre') {
            $request->validate(['semestre_id' => ['nullable', 'integer', 'exists:semestres,id']]);
            $s = $request->filled('semestre_id') ? Semestre::findOrFail($request->integer('semestre_id')) : Semestre::activo();
            abort_unless($s, 422, 'No hay un semestre activo. Elija un semestre.');

            return Reportes::semestre($s);
        }

        $d = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);
        // Por defecto: el semestre activo hasta hoy (o los últimos 30 días)
        $activo = Semestre::activo();
        $desde = $d['desde'] ?? ($activo?->fecha_inicio->toDateString() ?? now()->subDays(29)->toDateString());
        $hasta = $d['hasta'] ?? now()->toDateString();
        abort_if(Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) > self::MAX_DIAS, 422, 'El rango no puede pasar de un año ('.self::MAX_DIAS.' días).');

        return [Reportes::class, $tipo]($desde, $hasta); // ej: Reportes::ocupacion($desde, $hasta)
    }

    private function descargar(string $ruta, string $titulo): BinaryFileResponse
    {
        $nombre = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(\Illuminate\Support\Str::ascii($titulo))), '_').'_'.now()->format('Y-m-d').'.xlsx';

        return response()->download($ruta, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
