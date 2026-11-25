<?php

namespace App\Services;

use App\Models\Laboratorio;
use App\Models\Semestre;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * CU8 - Reportes y estadísticas (REPOCU, REPRES, REPINC, REPASI, ESTSEM del proyecto 1).
 *
 * Todos devuelven la MISMA forma, que usan la pantalla, el Excel y el PDF:
 *   [ 'titulo', 'subtitulo',
 *     'resumen'   => [ ['etiqueta' => 'Ocupación promedio', 'valor' => '42.5 %'], ... ],   ← tarjetas
 *     'secciones' => [ ['titulo', 'columnas' => [...], 'filas' => [[...], ...], 'total' => [...]?], ... ],  ← tablas
 *     'grafico'   => [ 'tipo' => 'barras'|'pastel', 'datos' => [...], 'series' => [[clave, etiqueta], ...] ] ]
 */
class Reportes
{
    /** Horas hábiles por día (07:00 a 22:00), de lunes a sábado: base del % de ocupación (igual que el proyecto 1) */
    const HORAS_POR_DIA = 15;

    // ------------------------------------------------------------------ REPOCU
    public static function ocupacion(string $desde, string $hasta): array
    {
        $labs = Laboratorio::orderBy('codigo')->get();

        // Feriados y bloqueos de día completo: ese día el laboratorio no cuenta como hábil
        $bloqueados = DB::table('fechas_bloqueadas')->whereBetween('fecha', [$desde, $hasta])->whereNull('hora_inicio')->get()
            ->map(fn ($b) => substr($b->fecha, 0, 10).'|'.($b->laboratorio_id ?? '*'))->flip();

        // Horas de clase por semestre / laboratorio / día de la semana
        $semestres = DB::table('semestres')->where('fecha_inicio', '<=', $hasta)->where('fecha_fin', '>=', $desde)->get();
        $clases = [];
        foreach (DB::table('horarios')->whereIn('semestre_id', $semestres->pluck('id'))
            ->selectRaw('semestre_id, laboratorio_id, dia_semana, SUM(EXTRACT(EPOCH FROM (hora_fin - hora_inicio)) / 3600) AS horas')
            ->groupBy('semestre_id', 'laboratorio_id', 'dia_semana')->get() as $h) {
            $clases[$h->semestre_id][$h->laboratorio_id][$h->dia_semana] = (float) $h->horas;
        }

        // Horas de reservas aprobadas por laboratorio y fecha
        $reservas = [];
        foreach (DB::table('reservas')->where('estado', 'APROBADA')->whereBetween('fecha', [$desde, $hasta])
            ->selectRaw("laboratorio_id, TO_CHAR(fecha, 'YYYY-MM-DD') AS dia, SUM(EXTRACT(EPOCH FROM (hora_fin - hora_inicio)) / 3600) AS horas")
            ->groupBy('laboratorio_id', 'dia')->get() as $r) {
            $reservas[$r->laboratorio_id][$r->dia] = (float) $r->horas;
        }

        $acum = [];
        foreach (CarbonPeriod::create($desde, $hasta) as $dia) {
            if ($dia->dayOfWeekIso === 7) {
                continue; // domingo
            }
            $f = $dia->toDateString();
            $sem = $semestres->first(fn ($s) => $f >= substr($s->fecha_inicio, 0, 10) && $f <= substr($s->fecha_fin, 0, 10));
            foreach ($labs as $lab) {
                if (isset($bloqueados["$f|*"]) || isset($bloqueados["$f|{$lab->id}"])) {
                    continue;
                }
                $a = &$acum[$lab->id];
                $a['dias'] = ($a['dias'] ?? 0) + 1;
                $a['clase'] = ($a['clase'] ?? 0) + ($sem ? ($clases[$sem->id][$lab->id][$dia->dayOfWeekIso] ?? 0) : 0);
                $a['reserva'] = ($a['reserva'] ?? 0) + ($reservas[$lab->id][$f] ?? 0);
                unset($a);
            }
        }

        $filas = [];
        $t = ['dias' => 0, 'disp' => 0, 'clase' => 0, 'reserva' => 0];
        foreach ($labs as $lab) {
            $a = $acum[$lab->id] ?? ['dias' => 0, 'clase' => 0, 'reserva' => 0];
            $disp = $a['dias'] * self::HORAS_POR_DIA;
            $ocup = $a['clase'] + $a['reserva'];
            $filas[] = [$lab->codigo, $lab->nombre, str_replace('_', ' ', $lab->estado), $a['dias'], $disp, round($a['clase'], 1), round($a['reserva'], 1),
                round($ocup, 1), self::pct($ocup, $disp)];
            $t['dias'] += $a['dias'];
            $t['disp'] += $disp;
            $t['clase'] += $a['clase'];
            $t['reserva'] += $a['reserva'];
        }
        $ocupTotal = $t['clase'] + $t['reserva'];
        $orden = collect($filas)->filter(fn ($f) => $f[4] > 0)->sortByDesc(8);

        return [
            'titulo' => 'Reporte de ocupación de laboratorios',
            'subtitulo' => self::rango($desde, $hasta).' · Lunes a sábado, '.self::HORAS_POR_DIA.' h hábiles por día (07:00–22:00), sin feriados ni bloqueos',
            'resumen' => [
                ['etiqueta' => 'Ocupación promedio', 'valor' => self::pct($ocupTotal, $t['disp']).' %'],
                ['etiqueta' => 'Más ocupado', 'valor' => $orden->first() ? $orden->first()[0].' ('.$orden->first()[8].' %)' : '—'],
                ['etiqueta' => 'Menos ocupado', 'valor' => $orden->last() ? $orden->last()[0].' ('.$orden->last()[8].' %)' : '—'],
                ['etiqueta' => 'Horas de clase', 'valor' => round($t['clase'], 1)],
                ['etiqueta' => 'Horas reservadas', 'valor' => round($t['reserva'], 1)],
            ],
            'secciones' => [[
                'titulo' => 'Ocupación por laboratorio',
                'columnas' => ['Laboratorio', 'Nombre', 'Estado', 'Días hábiles', 'Horas disponibles', 'Horas de clase', 'Horas reservadas', 'Horas ocupadas', '% Ocupación'],
                'filas' => $filas,
                'total' => ['TOTAL', '', '', $t['dias'], $t['disp'], round($t['clase'], 1), round($t['reserva'], 1), round($ocupTotal, 1), self::pct($ocupTotal, $t['disp'])],
            ]],
            'grafico' => ['tipo' => 'barras', 'unidad' => '%',
                'datos' => array_map(fn ($f) => ['nombre' => $f[0], 'clase' => self::pct($f[5], $f[4]), 'reserva' => self::pct($f[6], $f[4])], $filas),
                'series' => [['clase', 'Clases (%)'], ['reserva', 'Reservas (%)']], 'apilado' => true],
        ];
    }

    // ------------------------------------------------------------------ REPRES
    public static function reservas(string $desde, string $hasta): array
    {
        $base = fn () => DB::table('reservas')->whereBetween('reservas.fecha', [$desde, $hasta]);
        $total = $base()->count();
        $porEstado = self::contar($base(), 'estado', ['PENDIENTE', 'APROBADA', 'RECHAZADA', 'CANCELADA']);
        $porTipo = self::contar($base(), 'tipo', ['EXAMEN', 'RECUPERACION', 'PRACTICA', 'EVENTO', 'DEFENSA']);
        $horas = "SUM(CASE WHEN reservas.estado = 'APROBADA' THEN EXTRACT(EPOCH FROM (hora_fin - hora_inicio)) / 3600 ELSE 0 END)";

        $porLab = DB::table('laboratorios')->leftJoin('reservas', fn ($j) => $j->on('reservas.laboratorio_id', '=', 'laboratorios.id')->whereBetween('reservas.fecha', [$desde, $hasta]))
            ->groupBy('laboratorios.id', 'laboratorios.codigo')->orderBy('laboratorios.codigo')
            ->selectRaw("laboratorios.codigo, COUNT(reservas.id) AS total,
                COUNT(*) FILTER (WHERE reservas.estado = 'APROBADA') AS aprobadas, COUNT(*) FILTER (WHERE reservas.estado = 'PENDIENTE') AS pendientes,
                COUNT(*) FILTER (WHERE reservas.estado = 'RECHAZADA') AS rechazadas, COUNT(*) FILTER (WHERE reservas.estado = 'CANCELADA') AS canceladas,
                COALESCE($horas, 0) AS horas")->get()
            ->map(fn ($r) => [$r->codigo, (int) $r->total, (int) $r->aprobadas, (int) $r->pendientes, (int) $r->rechazadas, (int) $r->canceladas, round((float) $r->horas, 1)])->all();

        $solicitantes = $base()->join('usuarios', 'usuarios.id', '=', 'reservas.solicitante_id')->join('roles', 'roles.id', '=', 'usuarios.rol_id')
            ->groupBy('usuarios.id', 'usuarios.nombre', 'usuarios.apellido', 'roles.nombre')
            ->selectRaw("usuarios.nombre || ' ' || usuarios.apellido AS nombre, roles.nombre AS rol, COUNT(*) AS total, COUNT(*) FILTER (WHERE reservas.estado = 'APROBADA') AS aprobadas")
            ->orderByDesc('total')->limit(10)->get()->map(fn ($s) => [$s->nombre, $s->rol, (int) $s->total, (int) $s->aprobadas])->all();

        $aprob = $porEstado['APROBADA'] ?? 0;
        $resueltas = $aprob + ($porEstado['RECHAZADA'] ?? 0);

        return [
            'titulo' => 'Reporte de reservas',
            'subtitulo' => self::rango($desde, $hasta),
            'resumen' => [
                ['etiqueta' => 'Reservas', 'valor' => $total],
                ['etiqueta' => 'Aprobadas', 'valor' => $aprob],
                ['etiqueta' => 'Tasa de aprobación', 'valor' => self::pct($aprob, $resueltas).' %'],
                ['etiqueta' => 'Pendientes', 'valor' => $porEstado['PENDIENTE'] ?? 0],
                ['etiqueta' => 'Horas reservadas', 'valor' => round(array_sum(array_column($porLab, 6)), 1)],
            ],
            'secciones' => [
                self::tablaConteo('Por estado', 'Estado', $porEstado, $total),
                self::tablaConteo('Por tipo', 'Tipo', $porTipo, $total),
                ['titulo' => 'Por laboratorio', 'columnas' => ['Laboratorio', 'Total', 'Aprobadas', 'Pendientes', 'Rechazadas', 'Canceladas', 'Horas aprobadas'],
                    'filas' => $porLab, 'total' => array_merge(['TOTAL'], self::sumas($porLab, 1, 6))],
                ['titulo' => 'Principales solicitantes', 'columnas' => ['Solicitante', 'Rol', 'Reservas', 'Aprobadas'], 'filas' => $solicitantes],
            ],
            'grafico' => ['tipo' => 'barras', 'datos' => array_map(fn ($f) => ['nombre' => $f[0], 'aprobadas' => $f[2], 'otras' => $f[1] - $f[2]], $porLab),
                'series' => [['aprobadas', 'Aprobadas'], ['otras', 'Pendientes / rechazadas / canceladas']], 'apilado' => true],
        ];
    }

    // ------------------------------------------------------------------ REPINC
    public static function incidencias(string $desde, string $hasta): array
    {
        $enRango = fn () => DB::table('incidencias')->whereBetween(DB::raw('incidencias.created_at::date'), [$desde, $hasta]);
        $total = $enRango()->count();
        $porEstado = self::contar($enRango(), 'estado', ['PENDIENTE', 'EN_ATENCION', 'RESUELTA']);
        $promedio = (float) $enRango()->where('estado', 'RESUELTA')->whereNotNull('fecha_solucion')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (fecha_solucion - created_at)) / 3600) AS h')->value('h');

        $porLab = DB::table('laboratorios')
            ->leftJoin('equipos', 'equipos.laboratorio_id', '=', 'laboratorios.id')
            ->leftJoin('incidencias', fn ($j) => $j->on('incidencias.equipo_id', '=', 'equipos.id')->whereBetween(DB::raw('incidencias.created_at::date'), [$desde, $hasta]))
            ->groupBy('laboratorios.id', 'laboratorios.codigo')->orderBy('laboratorios.codigo')
            ->selectRaw("laboratorios.codigo, COUNT(incidencias.id) AS total, COUNT(*) FILTER (WHERE incidencias.estado = 'RESUELTA') AS resueltas,
                COUNT(*) FILTER (WHERE incidencias.estado IN ('PENDIENTE','EN_ATENCION')) AS abiertas,
                AVG(EXTRACT(EPOCH FROM (incidencias.fecha_solucion - incidencias.created_at)) / 3600) FILTER (WHERE incidencias.estado = 'RESUELTA') AS horas")->get()
            ->map(fn ($r) => [$r->codigo, (int) $r->total, (int) $r->resueltas, (int) $r->abiertas, $r->horas === null ? '—' : round((float) $r->horas, 1)])->all();

        $equipos = $enRango()->join('equipos', 'equipos.id', '=', 'incidencias.equipo_id')->join('laboratorios', 'laboratorios.id', '=', 'equipos.laboratorio_id')
            ->groupBy('equipos.id', 'equipos.codigo', 'equipos.descripcion', 'equipos.estado', 'laboratorios.codigo')
            ->selectRaw('equipos.codigo, equipos.descripcion, laboratorios.codigo AS lab, equipos.estado, COUNT(*) AS total')
            ->orderByDesc('total')->limit(10)->get()
            ->map(fn ($e) => [$e->codigo, $e->descripcion, $e->lab, str_replace('_', ' ', $e->estado), (int) $e->total])->all();

        $objetos = DB::table('laboratorios')->leftJoin('objetos_encontrados as o', fn ($j) => $j->on('o.laboratorio_id', '=', 'laboratorios.id')->whereBetween('o.fecha_hallazgo', [$desde, $hasta]))
            ->groupBy('laboratorios.id', 'laboratorios.codigo')->orderBy('laboratorios.codigo')
            ->selectRaw("laboratorios.codigo, COUNT(o.id) AS total, COUNT(*) FILTER (WHERE o.estado = 'ENTREGADO') AS entregados, COUNT(*) FILTER (WHERE o.estado = 'EN_CUSTODIA') AS custodia")->get()
            ->map(fn ($o) => [$o->codigo, (int) $o->total, (int) $o->entregados, (int) $o->custodia])->all();
        $objTotal = array_sum(array_column($objetos, 1));

        return [
            'titulo' => 'Reporte de incidencias y objetos encontrados',
            'subtitulo' => self::rango($desde, $hasta),
            'resumen' => [
                ['etiqueta' => 'Incidencias reportadas', 'valor' => $total],
                ['etiqueta' => 'Resueltas', 'valor' => ($porEstado['RESUELTA'] ?? 0).' ('.self::pct($porEstado['RESUELTA'] ?? 0, $total).' %)'],
                ['etiqueta' => 'Tiempo promedio de solución', 'valor' => $promedio ? self::duracion($promedio) : '—'],
                ['etiqueta' => 'Objetos encontrados', 'valor' => $objTotal],
                ['etiqueta' => 'Objetos entregados', 'valor' => array_sum(array_column($objetos, 2))],
            ],
            'secciones' => [
                self::tablaConteo('Incidencias por estado', 'Estado', $porEstado, $total),
                ['titulo' => 'Incidencias por laboratorio', 'columnas' => ['Laboratorio', 'Reportadas', 'Resueltas', 'Abiertas', 'Horas promedio de solución'],
                    'filas' => $porLab, 'total' => array_merge(['TOTAL'], self::sumas($porLab, 1, 3), [$promedio ? round($promedio, 1) : '—'])],
                ['titulo' => 'Equipos con más fallas', 'columnas' => ['Equipo', 'Descripción', 'Laboratorio', 'Estado actual', 'Incidencias'], 'filas' => $equipos],
                ['titulo' => 'Objetos encontrados por laboratorio', 'columnas' => ['Laboratorio', 'Encontrados', 'Entregados', 'En custodia'],
                    'filas' => $objetos, 'total' => array_merge(['TOTAL'], self::sumas($objetos, 1, 3))],
            ],
            'grafico' => ['tipo' => 'barras', 'datos' => array_map(fn ($f) => ['nombre' => $f[0], 'resueltas' => $f[2], 'abiertas' => $f[3]], $porLab),
                'series' => [['resueltas', 'Resueltas'], ['abiertas', 'Abiertas']], 'apilado' => true],
        ];
    }

    // ------------------------------------------------------------------ REPASI
    public static function asistencia(string $desde, string $hasta): array
    {
        $filas = DB::table('asistencias')->join('usuarios', 'usuarios.id', '=', 'asistencias.auxiliar_id')
            ->whereBetween('asistencias.fecha', [$desde, $hasta])
            ->groupBy('usuarios.id', 'usuarios.codigo', 'usuarios.nombre', 'usuarios.apellido')
            ->orderBy('usuarios.apellido')->orderBy('usuarios.nombre')
            ->selectRaw("usuarios.id, usuarios.codigo, usuarios.nombre || ' ' || usuarios.apellido AS nombre, COUNT(*) AS total,
                COUNT(*) FILTER (WHERE asistencias.estado = 'PUNTUAL') AS puntual, COUNT(*) FILTER (WHERE asistencias.estado = 'ATRASO') AS atraso,
                COUNT(*) FILTER (WHERE asistencias.estado = 'FALTA') AS falta, COUNT(*) FILTER (WHERE asistencias.estado = 'PERMISO') AS permiso")->get();

        $permutas = DB::table('permutas')->where('estado', 'APROBADA')->whereBetween('fecha', [$desde, $hasta]);
        $cubrio = (clone $permutas)->groupBy('reemplazante_id')->selectRaw('reemplazante_id AS id, COUNT(*) AS n')->pluck('n', 'id');
        $cedio = (clone $permutas)->groupBy('solicitante_id')->selectRaw('solicitante_id AS id, COUNT(*) AS n')->pluck('n', 'id');

        $tabla = $filas->map(fn ($a) => [
            $a->codigo, $a->nombre, (int) $a->total, (int) $a->puntual, (int) $a->atraso, (int) $a->falta, (int) $a->permiso,
            self::pct($a->puntual + $a->atraso, $a->puntual + $a->atraso + $a->falta), self::pct($a->puntual, $a->puntual + $a->atraso),
            (int) ($cubrio[$a->id] ?? 0), (int) ($cedio[$a->id] ?? 0),
        ])->all();
        $s = fn ($i) => array_sum(array_column($tabla, $i));

        $permisos = DB::table('permisos_auxiliar as p')->join('usuarios as u', 'u.id', '=', 'p.auxiliar_id')->leftJoin('usuarios as r', 'r.id', '=', 'p.registrado_por')
            ->whereBetween('p.fecha', [$desde, $hasta])->orderBy('p.fecha')
            ->selectRaw("TO_CHAR(p.fecha, 'DD/MM/YYYY') AS fecha, u.nombre || ' ' || u.apellido AS auxiliar, p.motivo, r.nombre || ' ' || r.apellido AS registro")->get()
            ->map(fn ($p) => [$p->fecha, $p->auxiliar, $p->motivo, $p->registro])->all();

        return [
            'titulo' => 'Reporte de asistencia de auxiliares',
            'subtitulo' => self::rango($desde, $hasta),
            'resumen' => [
                ['etiqueta' => 'Registros', 'valor' => $s(2)],
                ['etiqueta' => 'Asistencia', 'valor' => self::pct($s(3) + $s(4), $s(3) + $s(4) + $s(5)).' %'],
                ['etiqueta' => 'Puntualidad', 'valor' => self::pct($s(3), $s(3) + $s(4)).' %'],
                ['etiqueta' => 'Faltas', 'valor' => $s(5)],
                ['etiqueta' => 'Permisos', 'valor' => $s(6)],
            ],
            'secciones' => [
                ['titulo' => 'Asistencia por auxiliar', 'columnas' => ['Código', 'Auxiliar', 'Registros', 'Puntual', 'Atraso', 'Falta', 'Permiso', '% Asistencia', '% Puntualidad', 'Turnos cubiertos', 'Turnos cedidos'],
                    'filas' => $tabla,
                    'total' => ['TOTAL', '', $s(2), $s(3), $s(4), $s(5), $s(6), self::pct($s(3) + $s(4), $s(3) + $s(4) + $s(5)), self::pct($s(3), $s(3) + $s(4)), $s(9), $s(10)]],
                ['titulo' => 'Permisos otorgados', 'columnas' => ['Fecha', 'Auxiliar', 'Motivo', 'Registrado por'], 'filas' => $permisos],
            ],
            'grafico' => ['tipo' => 'barras', 'apilado' => true,
                'datos' => array_map(fn ($f) => ['nombre' => explode(' ', $f[1])[0].' '.mb_substr(explode(' ', $f[1])[1] ?? '', 0, 1).'.', 'puntual' => $f[3], 'atraso' => $f[4], 'falta' => $f[5], 'permiso' => $f[6]], $tabla),
                'series' => [['puntual', 'Puntual'], ['atraso', 'Atraso'], ['falta', 'Falta'], ['permiso', 'Permiso']]],
        ];
    }

    // ------------------------------------------------------------------ CU9 multas
    public static function multas(string $desde, string $hasta): array
    {
        $base = fn () => DB::table('multas')->whereBetween('multas.fecha', [$desde, $hasta]);
        $suma = fn ($q) => round((float) $q->sum('monto'), 2);
        $pagado = $suma($base()->where('estado', 'PAGADA'));
        $pendiente = $suma($base()->where('estado', 'PENDIENTE'));
        $vencidas = $base()->where('estado', 'PENDIENTE')->whereDate('vence_en', '<', today())->count();
        $total = $base()->count();

        $porTipo = collect(['ATRASO' => 'Atraso', 'FALTA' => 'Falta', 'RESERVA' => 'Reserva no utilizada'])->map(function ($nombre, $tipo) use ($base) {
            $q = fn () => $base()->where('tipo', $tipo);

            return [$nombre, $q()->count(), $q()->where('estado', 'PAGADA')->count(), $q()->where('estado', 'PENDIENTE')->count(),
                $q()->where('estado', 'ANULADA')->count(), round((float) $q()->where('estado', 'PAGADA')->sum('monto'), 2), round((float) $q()->where('estado', 'PENDIENTE')->sum('monto'), 2)];
        })->values()->all();

        $porUsuario = $base()->join('usuarios', 'usuarios.id', '=', 'multas.usuario_id')->join('roles', 'roles.id', '=', 'usuarios.rol_id')
            ->groupBy('usuarios.id', 'usuarios.nombre', 'usuarios.apellido', 'roles.nombre')
            ->selectRaw("usuarios.nombre || ' ' || usuarios.apellido AS nombre, roles.nombre AS rol, COUNT(*) AS total,
                COUNT(*) FILTER (WHERE multas.tipo = 'ATRASO') AS atrasos, COUNT(*) FILTER (WHERE multas.tipo = 'FALTA') AS faltas,
                COUNT(*) FILTER (WHERE multas.tipo = 'RESERVA') AS reservas,
                COALESCE(SUM(multas.monto) FILTER (WHERE multas.estado = 'PAGADA'), 0) AS pagado,
                COALESCE(SUM(multas.monto) FILTER (WHERE multas.estado = 'PENDIENTE'), 0) AS pendiente,
                COUNT(*) FILTER (WHERE multas.estado = 'PENDIENTE' AND multas.vence_en < CURRENT_DATE) AS vencidas")
            ->orderByDesc('pendiente')->orderByDesc('total')->get()
            ->map(fn ($u) => [$u->nombre, $u->rol, (int) $u->total, (int) $u->atrasos, (int) $u->faltas, (int) $u->reservas,
                round((float) $u->pagado, 2), round((float) $u->pendiente, 2), (int) $u->vencidas > 0 ? 'Sí' : 'No'])->all();

        return [
            'titulo' => 'Reporte de multas',
            'subtitulo' => self::rango($desde, $hasta).' · Atrasos y faltas de auxiliares, reservas no utilizadas',
            'resumen' => [
                ['etiqueta' => 'Multas', 'valor' => $total],
                ['etiqueta' => 'Cobrado', 'valor' => 'Bs '.number_format($pagado, 2)],
                ['etiqueta' => 'Por cobrar', 'valor' => 'Bs '.number_format($pendiente, 2)],
                ['etiqueta' => 'Vencidas', 'valor' => $vencidas],
                ['etiqueta' => 'Usuarios bloqueados', 'valor' => count(array_filter($porUsuario, fn ($u) => $u[8] === 'Sí'))],
            ],
            'secciones' => [
                ['titulo' => 'Por tipo', 'columnas' => ['Tipo', 'Multas', 'Pagadas', 'Pendientes', 'Anuladas', 'Cobrado (Bs)', 'Por cobrar (Bs)'],
                    'filas' => $porTipo, 'total' => array_merge(['TOTAL'], self::sumas($porTipo, 1, 6))],
                ['titulo' => 'Por usuario', 'columnas' => ['Usuario', 'Rol', 'Multas', 'Atrasos', 'Faltas', 'Reservas', 'Pagado (Bs)', 'Pendiente (Bs)', 'Bloqueado'],
                    'filas' => $porUsuario],
            ],
            'grafico' => ['tipo' => 'barras', 'apilado' => true, 'unidad' => 'Bs',
                'datos' => array_map(fn ($f) => ['nombre' => $f[0], 'pagado' => $f[5], 'pendiente' => $f[6]], $porTipo),
                'series' => [['pagado', 'Cobrado'], ['pendiente', 'Por cobrar']]],
        ];
    }

    // ------------------------------------------------------------------ ESTSEM
    public static function semestre(Semestre $s): array
    {
        $desde = $s->fecha_inicio->toDateString();
        $hasta = $s->fecha_fin->toDateString();
        $h = DB::table('horarios')->where('semestre_id', $s->id);
        $horasSemana = (float) (clone $h)->selectRaw('SUM(EXTRACT(EPOCH FROM (hora_fin - hora_inicio)) / 3600) AS x')->value('x');

        $indicadores = [
            ['Clases en el horario base', (clone $h)->count()],
            ['Horas de clase por semana', round($horasSemana, 1)],
            ['Docentes con clase', (clone $h)->distinct()->count('docente_id')],
            ['Materias', (clone $h)->distinct()->count('materia_id')],
            ['Laboratorios con clase', (clone $h)->distinct()->count('laboratorio_id')],
            ['Turnos de auxiliares', DB::table('turnos')->where('semestre_id', $s->id)->count()],
            ['Fechas bloqueadas', DB::table('fechas_bloqueadas')->where('semestre_id', $s->id)->count()],
            ['Reservas', DB::table('reservas')->whereBetween('fecha', [$desde, $hasta])->count()],
            ['Reservas aprobadas', DB::table('reservas')->whereBetween('fecha', [$desde, $hasta])->where('estado', 'APROBADA')->count()],
            ['Incidencias reportadas', DB::table('incidencias')->whereBetween(DB::raw('created_at::date'), [$desde, $hasta])->count()],
            ['Objetos encontrados', DB::table('objetos_encontrados')->whereBetween('fecha_hallazgo', [$desde, $hasta])->count()],
            ['Permutas de turno', DB::table('permutas')->whereBetween('fecha', [$desde, $hasta])->count()],
            ['Permutas aprobadas', DB::table('permutas')->whereBetween('fecha', [$desde, $hasta])->where('estado', 'APROBADA')->count()],
            ['Permisos de auxiliares', DB::table('permisos_auxiliar')->whereBetween('fecha', [$desde, $hasta])->count()],
            ['Mensajes internos', DB::table('mensajes')->whereBetween(DB::raw('created_at::date'), [$desde, $hasta])->count()],
        ];

        $porLab = DB::table('laboratorios')->leftJoin('horarios as h', fn ($j) => $j->on('h.laboratorio_id', '=', 'laboratorios.id')->where('h.semestre_id', $s->id))
            ->groupBy('laboratorios.id', 'laboratorios.codigo', 'laboratorios.nombre')->orderBy('laboratorios.codigo')
            ->selectRaw('laboratorios.codigo, laboratorios.nombre, COUNT(h.id) AS clases, COALESCE(SUM(EXTRACT(EPOCH FROM (h.hora_fin - h.hora_inicio)) / 3600), 0) AS horas,
                COUNT(DISTINCT h.materia_id) AS materias, COUNT(DISTINCT h.docente_id) AS docentes')->get()
            ->map(fn ($l) => [$l->codigo, $l->nombre, (int) $l->clases, round((float) $l->horas, 1), self::pct((float) $l->horas, self::HORAS_POR_DIA * 6), (int) $l->materias, (int) $l->docentes])->all();

        $bloqueos = self::contar(DB::table('fechas_bloqueadas')->where('semestre_id', $s->id), 'tipo', ['FERIADO', 'PSA', 'CUP', 'MANTENIMIENTO', 'OTRO']);
        $porDia = [];
        foreach ([1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'] as $d => $nombre) {
            $porDia[] = ['nombre' => $nombre, 'horas' => round((float) (clone $h)->where('dia_semana', $d)->selectRaw('SUM(EXTRACT(EPOCH FROM (hora_fin - hora_inicio)) / 3600) AS x')->value('x'), 1)];
        }

        return [
            'titulo' => "Estadísticas del semestre {$s->gestion}",
            'subtitulo' => self::rango($desde, $hasta).' · Estado: '.$s->estado,
            'resumen' => [
                ['etiqueta' => 'Clases', 'valor' => $indicadores[0][1]],
                ['etiqueta' => 'Horas de clase / semana', 'valor' => $indicadores[1][1]],
                ['etiqueta' => 'Docentes', 'valor' => $indicadores[2][1]],
                ['etiqueta' => 'Materias', 'valor' => $indicadores[3][1]],
                ['etiqueta' => 'Reservas', 'valor' => $indicadores[7][1]],
            ],
            'secciones' => [
                ['titulo' => 'Indicadores del semestre', 'columnas' => ['Indicador', 'Valor'], 'filas' => $indicadores],
                ['titulo' => 'Horario base por laboratorio', 'columnas' => ['Laboratorio', 'Nombre', 'Clases', 'Horas por semana', '% de la semana hábil', 'Materias', 'Docentes'],
                    'filas' => $porLab, 'total' => array_merge(['TOTAL', ''], self::sumas($porLab, 2, 3), [self::pct($horasSemana, self::HORAS_POR_DIA * 6 * max(1, count($porLab))), '', ''])],
                self::tablaConteo('Fechas bloqueadas por tipo', 'Tipo', $bloqueos, array_sum($bloqueos)),
            ],
            'grafico' => ['tipo' => 'barras', 'datos' => $porDia, 'series' => [['horas', 'Horas de clase']]],
        ];
    }

    // ------------------------------------------------------------------ ayudas

    /** Cuenta por una columna, mostrando también los valores con 0 (como el proyecto 1) */
    private static function contar($consulta, string $columna, array $valores): array
    {
        $n = $consulta->selectRaw("$columna AS v, COUNT(*) AS n")->groupBy($columna)->pluck('n', 'v');

        return collect($valores)->mapWithKeys(fn ($v) => [$v => (int) ($n[$v] ?? 0)])->all();
    }

    private static function tablaConteo(string $titulo, string $etiqueta, array $conteo, int $total): array
    {
        return [
            'titulo' => $titulo, 'columnas' => [$etiqueta, 'Cantidad', '%'],
            'filas' => collect($conteo)->map(fn ($n, $v) => [str_replace('_', ' ', $v), $n, self::pct($n, $total)])->values()->all(),
            'total' => ['TOTAL', $total, $total ? 100 : 0],
        ];
    }

    /** Suma de las columnas $desde..$hasta de una tabla (para la fila TOTAL) */
    private static function sumas(array $filas, int $desde, int $hasta): array
    {
        $r = [];
        for ($i = $desde; $i <= $hasta; $i++) {
            $sum = array_sum(array_map(fn ($f) => is_numeric($f[$i]) ? $f[$i] : 0, $filas));
            $r[] = is_float($sum) ? round($sum, 1) : $sum;
        }

        return $r;
    }

    private static function pct(float $parte, float $total): float
    {
        return $total > 0 ? round($parte * 100 / $total, 1) : 0.0;
    }

    private static function rango(string $desde, string $hasta): string
    {
        return 'Del '.Carbon::parse($desde)->format('d/m/Y').' al '.Carbon::parse($hasta)->format('d/m/Y');
    }

    private static function duracion(float $horas): string
    {
        return $horas < 48 ? round($horas, 1).' h' : round($horas / 24, 1).' días';
    }
}
