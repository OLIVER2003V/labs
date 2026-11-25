<?php

namespace App\Http\Controllers;

use App\Services\Reportes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Panel analítico: métricas del negocio (reservas, ocupación, incidencias, pagos)
 * y métricas de acceso al sitio (logins, tráfico diario, recursos más visitados).
 */
class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);
        $desde = $d['desde'] ?? now()->subDays(29)->toDateString();
        $hasta = $d['hasta'] ?? now()->toDateString();
        $usuario = $request->user();

        $datos = [
            'rango' => ['desde' => $desde, 'hasta' => $hasta],
            'tarjetas' => [
                'laboratorios' => DB::table('laboratorios')->where('estado', 'DISPONIBLE')->count(),
                'reservas_pendientes' => DB::table('reservas')->where('estado', 'PENDIENTE')->count(),
                'reservas_hoy' => DB::table('reservas')->where('estado', 'APROBADA')->whereDate('fecha', now())->count(),
                'incidencias_abiertas' => DB::table('incidencias')->where('estado', '<>', 'RESUELTA')->count(),
                'equipos_reparacion' => DB::table('equipos')->where('estado', 'EN_REPARACION')->count(),
                'objetos_custodia' => DB::table('objetos_encontrados')->where('estado', 'EN_CUSTODIA')->count(),
                'usuarios_activos' => DB::table('usuarios')->where('estado', 'ACTIVO')->whereNull('deleted_at')->count(),
                'ingresos' => (float) DB::table('transacciones')->where('estado', 'APROBADA')
                    ->whereBetween(DB::raw('created_at::date'), [$desde, $hasta])->sum('monto'),
            ],
            'reservas_por_estado' => DB::table('reservas')->selectRaw('estado, COUNT(*) AS total')
                ->whereBetween('fecha', [$desde, $hasta])->groupBy('estado')->get(),
            'reservas_por_tipo' => DB::table('reservas')->selectRaw('tipo, COUNT(*) AS total')
                ->whereBetween('fecha', [$desde, $hasta])->groupBy('tipo')->get(),
            // % de ocupación por laboratorio en el periodo: el mismo cálculo del reporte de ocupación (CU8)
            'ocupacion_laboratorios' => Reportes::ocupacion($desde, $hasta)['grafico']['datos'],
            'incidencias_por_estado' => DB::table('incidencias')->selectRaw('estado, COUNT(*) AS total')
                ->whereBetween(DB::raw('created_at::date'), [$desde, $hasta])->groupBy('estado')->get(),
            'ingresos_por_dia' => DB::table('transacciones')
                ->selectRaw("TO_CHAR(created_at::date, 'YYYY-MM-DD') AS dia, SUM(monto) AS total, COUNT(*) AS cantidad")
                ->where('estado', 'APROBADA')->whereBetween(DB::raw('created_at::date'), [$desde, $hasta])
                ->groupBy('dia')->orderBy('dia')->get(),
            'asistencia_por_estado' => DB::table('asistencias')->selectRaw('estado, COUNT(*) AS total')
                ->whereBetween('fecha', [$desde, $hasta])->groupBy('estado')->get(),
        ];

        // Las métricas de acceso solo las ve quien puede ver la bitácora
        if ($usuario->tienePermiso('bitacora.ver')) {
            $datos['accesos_por_dia'] = DB::select("
                SELECT TO_CHAR(d::date, 'YYYY-MM-DD') AS dia,
                       COUNT(b.id) FILTER (WHERE b.evento = 'ACCESO') AS accesos,
                       COUNT(b.id) FILTER (WHERE b.evento = 'LOGIN_OK') AS logins,
                       COUNT(b.id) FILTER (WHERE b.evento = 'LOGIN_FALLIDO') AS fallidos
                  FROM generate_series(?::date, ?::date, '1 day') d
                  LEFT JOIN bitacora b ON b.created_at::date = d::date
                 GROUP BY d ORDER BY d", [$desde, $hasta]);
            $datos['recursos_mas_accedidos'] = DB::table('bitacora')->selectRaw('recurso, COUNT(*) AS total')
                ->where('evento', 'ACCESO')->whereBetween(DB::raw('created_at::date'), [$desde, $hasta])
                ->groupBy('recurso')->orderByDesc('total')->limit(8)->get();
            $datos['paginas_mas_visitadas'] = DB::table('visitas_pagina')->orderByDesc('contador')->limit(8)->get(['ruta', 'contador']);
        }

        return response()->json($datos);
    }
}
