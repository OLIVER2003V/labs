<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Support\Respaldo;
use Illuminate\Database\QueryException;
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
        $request->validate([
            'operacion' => ['nullable', Rule::in(['INSERT', 'UPDATE', 'DELETE'])],
            'origen' => ['nullable', Rule::in(['directo', 'sistema'])],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);
        $q = DB::table('auditoria')
            ->leftJoin('usuarios', 'usuarios.id', '=', 'auditoria.usuario_app_id')
            ->select('auditoria.*', DB::raw("COALESCE(usuarios.nombre || ' ' || usuarios.apellido, 'Directo en BD') AS usuario_app"))
            ->orderByDesc('auditoria.id')
            ->when($request->filled('tabla'), fn ($x) => $x->where('tabla', $request->tabla))
            ->when($request->filled('operacion'), fn ($x) => $x->where('operacion', $request->operacion))
            // CU10: "directo" = cambios hechos por SQL fuera del sistema (sin usuario de la aplicación): posible sabotaje
            ->when($request->origen === 'directo', fn ($x) => $x->whereNull('auditoria.usuario_app_id'))
            ->when($request->origen === 'sistema', fn ($x) => $x->whereNotNull('auditoria.usuario_app_id'))
            ->when($request->filled('desde'), fn ($x) => $x->whereDate('auditoria.fecha', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($x) => $x->whereDate('auditoria.fecha', '<=', $request->hasta));

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

        abort_if($a->tabla === 'usuarios', 422, 'Los usuarios eliminados se restauran desde Usuarios (borrado lógico).');
        try {
            $r = self::reinsertar($a);
        } catch (QueryException $e) {
            abort(422, 'No se pudo restaurar: '.self::motivo($e));
        }
        abort_if($r === 'existe', 422, 'El registro ya existe; no es necesario restaurarlo.');

        return response()->json(['message' => "Registro restaurado en la tabla {$a->tabla}."]);
    }

    /**
     * CU10 - Restaura EN LOTE todo lo eliminado en un rango de tiempo (ej: alguien borró 30 reservas por SQL).
     * POST /api/auditoria/restaurar-eliminados  { desde, hasta?, tabla?, solo_directos? }
     * Se restaura primero lo "padre" y después lo "hijo" (orden de Respaldo::TABLAS), cada registro por separado:
     * si uno falla, los demás igual se restauran.
     */
    public function restaurarEliminados(Request $request): JsonResponse
    {
        $d = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'tabla' => ['nullable', 'regex:/^[a-z_]+$/'],
            'solo_directos' => ['nullable', 'boolean'],
        ]);
        $borrados = DB::table('auditoria')->where('operacion', 'DELETE')->whereNotNull('datos_antes')
            ->where('tabla', '<>', 'usuarios')
            ->where('fecha', '>=', $d['desde'])->when($d['hasta'] ?? null, fn ($q, $h) => $q->where('fecha', '<=', $h))
            ->when($d['tabla'] ?? null, fn ($q, $t) => $q->where('tabla', $t))
            ->when($request->boolean('solo_directos'), fn ($q) => $q->whereNull('usuario_app_id'))
            ->orderBy('id')->limit(2000)->get();

        $orden = array_flip(Respaldo::TABLAS);
        $borrados = $borrados->sortBy(fn ($a) => [($orden[$a->tabla] ?? 999), $a->id])->values();
        $r = ['restaurados' => 0, 'ya_existian' => 0, 'fallidos' => 0, 'errores' => [], 'por_tabla' => []];
        foreach ($borrados as $a) {
            try {
                $estado = DB::transaction(fn () => self::reinsertar($a)); // savepoint: si uno falla, no arrastra a los demás
            } catch (QueryException $e) {
                $estado = 'error';
                if (count($r['errores']) < 5) {
                    $r['errores'][] = "{$a->tabla} #{$a->registro_id}: ".self::motivo($e);
                }
            }
            match ($estado) {
                'ok' => [$r['restaurados']++, $r['por_tabla'][$a->tabla] = ($r['por_tabla'][$a->tabla] ?? 0) + 1],
                'existe' => $r['ya_existian']++,
                default => $r['fallidos']++,
            };
        }

        return response()->json(['message' => $borrados->isEmpty() ? 'No hay registros eliminados en ese rango.'
            : "Se restauraron {$r['restaurados']} registro(s)."
                .($r['ya_existian'] ? " {$r['ya_existian']} ya existían." : '')
                .($r['fallidos'] ? " {$r['fallidos']} no se pudieron restaurar." : '')] + $r);
    }

    /**
     * CU10 - Deshace una MODIFICACIÓN: el registro vuelve a los valores que tenía antes de ese UPDATE.
     * Solo si es el último cambio de ese registro (si cambió otra vez después, hay que deshacer primero el más reciente).
     */
    public function deshacer(int $id): JsonResponse
    {
        $a = DB::table('auditoria')->find($id);
        abort_unless($a && $a->operacion === 'UPDATE' && $a->datos_antes && $a->datos_despues, 422, 'Solo se pueden deshacer modificaciones (UPDATE).');
        abort_unless(preg_match('/^[a-z_]+$/', $a->tabla), 422, 'Tabla inválida.');
        $antes = json_decode($a->datos_antes, true);
        $despues = json_decode($a->datos_despues, true);
        abort_unless(isset($antes['id']), 422, 'Este cambio no se puede deshacer automáticamente.');
        abort_unless(DB::table($a->tabla)->where('id', $antes['id'])->exists(), 422, 'El registro ya no existe (fue eliminado). Restaure primero el borrado.');

        $posterior = DB::table('auditoria')->where('tabla', $a->tabla)->where('registro_id', $a->registro_id)
            ->where('id', '>', $a->id)->whereIn('operacion', ['UPDATE', 'DELETE'])->orderBy('id')->value('id');
        abort_if($posterior, 422, "El registro volvió a cambiar después (cambio #$posterior). Deshaga primero el cambio más reciente.");

        // Solo las columnas que cambiaron en ese UPDATE (y que todavía existen en la tabla)
        $columnas = array_flip(DB::getSchemaBuilder()->getColumnListing($a->tabla));
        $cambios = [];
        foreach ($antes as $k => $v) {
            if (isset($columnas[$k]) && $k !== 'id' && $k !== 'updated_at' && ($despues[$k] ?? null) !== $v) {
                $cambios[$k] = is_array($v) ? json_encode($v) : $v;
            }
        }
        abort_if(! $cambios, 422, 'Ese cambio no modificó ningún dato que se pueda deshacer.');
        if (isset($columnas['updated_at'])) {
            $cambios['updated_at'] = now();
        }
        try {
            DB::table($a->tabla)->where('id', $antes['id'])->update($cambios);
        } catch (QueryException $e) {
            abort(422, 'No se pudo deshacer: '.self::motivo($e));
        }

        return response()->json(['message' => "Cambio deshecho en {$a->tabla} #{$antes['id']}: ".implode(', ', array_keys(array_diff_key($cambios, ['updated_at' => 1]))).' volvieron a su valor anterior.']);
    }

    /** Vuelve a insertar un registro eliminado. @return 'ok' | 'existe' */
    private static function reinsertar(object $a): string
    {
        abort_unless(preg_match('/^[a-z_]+$/', $a->tabla), 422, 'Tabla inválida.');
        $datos = json_decode($a->datos_antes, true);
        $existe = isset($datos['id'])
            ? DB::table($a->tabla)->where('id', $datos['id'])->exists()
            : ($a->tabla === 'rol_permiso' && DB::table('rol_permiso')->where('rol_id', $datos['rol_id'] ?? 0)->where('permiso_id', $datos['permiso_id'] ?? 0)->exists());
        if ($existe) {
            return 'existe';
        }
        // Columnas reales de la tabla (por si la auditoría trae campos que ya no existen)
        $datos = array_intersect_key($datos, array_flip(DB::getSchemaBuilder()->getColumnListing($a->tabla)));
        foreach ($datos as $k => $v) {
            if (is_array($v)) {
                $datos[$k] = json_encode($v);
            }
        }
        DB::table($a->tabla)->insert($datos);

        return 'ok';
    }

    /** Mensaje legible de un error de PostgreSQL (ej: el registro padre no existe, choque de horario del trigger) */
    private static function motivo(QueryException $e): string
    {
        $m = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        if (str_contains($m, 'CHOQUE:')) {
            return trim(explode("\n", substr($m, strpos($m, 'CHOQUE:')))[0]);
        }

        return match ($e->getCode()) {
            '23503' => 'depende de un registro que ya no existe (restaure primero ese registro).',
            '23505' => 'ya existe otro registro con el mismo valor único.',
            '23514' => 'no cumple una regla de la base de datos.',
            default => 'error de la base de datos ('.$e->getCode().').',
        };
    }
}
