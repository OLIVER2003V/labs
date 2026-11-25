<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Bitácora de accesos (logins, fallos, IP, recursos) y auditoría de cambios (triggers).
 */
class BitacoraController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'evento' => ['nullable', Rule::in(['LOGIN_OK', 'LOGIN_FALLIDO', 'LOGOUT', 'ACCESO', 'DENEGADO', 'REGISTRO'])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        $q = Bitacora::with('usuario:id,nombre,apellido')->orderByDesc('id')
            ->when($request->filled('evento'), fn ($x) => $x->where('evento', $request->evento))
            ->when($request->filled('desde'), fn ($x) => $x->whereDate('created_at', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($x) => $x->whereDate('created_at', '<=', $request->hasta));
        if ($texto = trim((string) $request->query('q'))) {
            $q->where(fn ($x) => $x->where('email', 'ILIKE', "%$texto%")->orWhere('ip', 'ILIKE', "%$texto%")
                ->orWhere('recurso', 'ILIKE', "%$texto%")->orWhere('ruta', 'ILIKE', "%$texto%"));
        }

        return response()->json($q->paginate($this->porPagina($request, 15)));
    }

    /** Resumen: top IPs, top recursos y logins fallidos recientes */
    public function resumen(): JsonResponse
    {
        $desde = now()->subDays(30);

        return response()->json([
            'recursos' => Bitacora::selectRaw('recurso, COUNT(*) AS total')->where('evento', 'ACCESO')
                ->where('created_at', '>=', $desde)->groupBy('recurso')->orderByDesc('total')->limit(10)->get(),
            'ips' => Bitacora::selectRaw('ip, COUNT(*) AS total, SUM(CASE WHEN evento = \'LOGIN_FALLIDO\' THEN 1 ELSE 0 END) AS fallidos')
                ->where('created_at', '>=', $desde)->groupBy('ip')->orderByDesc('total')->limit(10)->get(),
            'fallidos' => Bitacora::where('evento', 'LOGIN_FALLIDO')->orderByDesc('id')->limit(10)
                ->get(['email', 'ip', 'detalle', 'created_at']),
            'paginas' => DB::table('visitas_pagina')->orderByDesc('contador')->limit(10)->get(['ruta', 'contador']),
        ]);
    }

    /** Cambios registrados por los triggers (incluye los hechos directamente por SQL) */
    public function auditoria(Request $request): JsonResponse
    {
        $request->validate(['operacion' => ['nullable', Rule::in(['INSERT', 'UPDATE', 'DELETE'])]]);
        $q = DB::table('auditoria')
            ->leftJoin('usuarios', 'usuarios.id', '=', 'auditoria.usuario_app_id')
            ->select('auditoria.*', DB::raw("COALESCE(usuarios.nombre || ' ' || usuarios.apellido, 'Directo en BD') AS usuario_app"))
            ->orderByDesc('auditoria.id')
            ->when($request->filled('tabla'), fn ($x) => $x->where('tabla', $request->tabla))
            ->when($request->filled('operacion'), fn ($x) => $x->where('operacion', $request->operacion));

        $pagina = $q->paginate($this->porPagina($request, 15));
        $pagina->getCollection()->transform(function ($fila) {
            $fila->datos_antes = json_decode($fila->datos_antes ?? 'null');
            $fila->datos_despues = json_decode($fila->datos_despues ?? 'null');

            return $fila;
        });

        return response()->json($pagina);
    }

    /**
     * Restaura un registro BORRADO a partir de la auditoría (deshacer un DELETE).
     * Útil si alguien elimina datos directamente en la base de datos.
     */
    public function restaurar(int $id): JsonResponse
    {
        $a = DB::table('auditoria')->find($id);
        abort_unless($a && $a->operacion === 'DELETE' && $a->datos_antes, 422, 'Solo se pueden restaurar registros eliminados (DELETE).');

        $datos = json_decode($a->datos_antes, true);
        $tabla = $a->tabla;
        abort_unless(preg_match('/^[a-z_]+$/', $tabla), 422, 'Tabla inválida.');
        if (isset($datos['id']) && DB::table($tabla)->where('id', $datos['id'])->exists()) {
            abort(422, 'El registro ya existe; no es necesario restaurarlo.');
        }
        // Columnas reales de la tabla (por si la auditoría trae campos extra)
        $columnas = DB::getSchemaBuilder()->getColumnListing($tabla);
        $datos = array_intersect_key($datos, array_flip($columnas));
        if ($tabla === 'usuarios') {
            abort(422, 'Los usuarios eliminados se restauran desde Usuarios (borrado lógico).');
        }
        foreach ($datos as $k => $v) {
            if (is_array($v)) {
                $datos[$k] = json_encode($v);
            }
        }
        DB::table($tabla)->insert($datos);

        return response()->json(['message' => "Registro restaurado en la tabla $tabla."]);
    }
}
