<?php

namespace App\Http\Controllers;

use App\Models\ConceptoPago;
use App\Models\Equipo;
use App\Models\Incidencia;
use App\Models\Laboratorio;
use App\Models\Materia;
use App\Models\Menu;
use App\Models\Multa;
use App\Models\ObjetoEncontrado;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Funciones transversales: búsqueda global, contador de visitas y listas para los formularios.
 */
class GeneralController extends Controller
{
    /** Máximo de resultados por cada tipo (laboratorios, equipos, ...) */
    private const POR_TIPO = 5;

    /**
     * Búsqueda global (barra del encabezado). Solo busca en los recursos que el usuario puede ver.
     * GET /api/buscar?q=lab 41
     *
     * - Varias palabras: cada palabra debe aparecer en alguna de las columnas ("lab-41 pc-05", "pc-05 41").
     * - No distingue mayúsculas ni tildes ("informatica" encuentra "Informática").
     * - Los caracteres % y _ se buscan literalmente (no son comodines).
     * - Primero salen los que EMPIEZAN con lo buscado.
     * - Si una sección falla, las demás igual responden.
     */
    public function buscar(Request $request): JsonResponse
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $request->query('q', '')));
        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }
        $texto = mb_substr($texto, 0, 50);
        $palabras = array_slice(array_unique(explode(' ', self::sinTildes($texto))), 0, 5); // máximo 5 palabras
        $u = $request->user();
        $res = [];

        // Ejecuta una sección; si algo falla se registra en el log y se sigue con las demás
        $seccion = function (string $permiso, callable $buscar) use ($u, &$res) {
            if (! $u->tienePermiso($permiso)) {
                return;
            }
            try {
                array_push($res, ...$buscar());
            } catch (\Throwable $e) {
                report($e);
            }
        };

        // Páginas del menú: escribir "asis" lleva a Asistencia
        // (cada página tiene su propio permiso, por eso no usa $seccion)
        try {
            $paginas = Menu::where('activo', true)->orderBy('orden')->get()
                ->filter(fn ($m) => ! $m->permiso_codigo || $u->tienePermiso($m->permiso_codigo))
                ->filter(fn ($m) => collect($palabras)->every(fn ($p) => str_contains(self::sinTildes("{$m->titulo} {$m->grupo}"), $p)))
                ->take(self::POR_TIPO);
            foreach ($paginas as $m) {
                $res[] = ['tipo' => 'Página', 'titulo' => $m->titulo, 'detalle' => $m->grupo, 'ruta' => $m->ruta];
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $seccion('laboratorios.ver', fn () => $this->filtrar(Laboratorio::query(), ['codigo', 'nombre', 'ubicacion'], $palabras)
            ->get()->map(fn ($l) => [
                'tipo' => 'Laboratorio', 'titulo' => "{$l->codigo} - {$l->nombre}",
                'detalle' => "{$l->estado} · {$l->capacidad} equipos", 'ruta' => '/laboratorios?q='.urlencode($l->codigo),
            ])->all());

        $seccion('equipos.ver', fn () => $this->filtrar(Equipo::with('laboratorio'),
            ['codigo', 'activo_fijo', 'descripcion', 'tipo', 'laboratorio.codigo', 'red.ip'], $palabras)
            ->get()->map(fn ($e) => [
                'tipo' => 'Equipo', 'titulo' => "{$e->codigo} - {$e->descripcion}",
                'detalle' => "{$e->laboratorio?->codigo} · {$e->estado}", 'ruta' => '/equipos?q='.urlencode($e->codigo),
            ])->all());

        $seccion('horarios.ver', fn () => $this->filtrar(Materia::query(), ['sigla', 'nombre'], $palabras)
            ->get()->map(fn ($m) => [
                'tipo' => 'Materia', 'titulo' => "{$m->sigla} - {$m->nombre}",
                'detalle' => 'Ver horario', 'ruta' => '/horarios?q='.urlencode($m->sigla),
            ])->all());

        $seccion('reservas.ver', fn () => $this->filtrar(Reserva::with('laboratorio')
            ->when(! $u->tienePermiso('reservas.ver_todas'), fn ($x) => $x->where('solicitante_id', $u->id)),
            ['motivo', 'laboratorio.codigo', 'solicitante.nombre', 'solicitante.apellido'], $palabras, 'fecha')
            ->get()->map(fn ($r) => [
                'tipo' => 'Reserva', 'titulo' => $r->motivo,
                'detalle' => "{$r->laboratorio?->codigo} · {$r->fecha?->format('d/m/Y')} · {$r->estado}",
                'ruta' => '/reservas?q='.urlencode($r->motivo),
            ])->all());

        $seccion('incidencias.ver', fn () => $this->filtrar(Incidencia::with('equipo')
            ->when(! $u->tienePermiso('incidencias.gestionar'), fn ($x) => $x->where('reportado_por', $u->id)),
            ['descripcion', 'equipo.codigo', 'equipo.laboratorio.codigo'], $palabras, 'created_at')
            ->get()->map(fn ($i) => [
                'tipo' => 'Incidencia', 'titulo' => "#{$i->id} - ".mb_strimwidth($i->descripcion, 0, 70, '…'),
                'detalle' => "{$i->equipo?->codigo} · {$i->estado}",
                'ruta' => '/incidencias?q='.urlencode(mb_substr($i->descripcion, 0, 40)),
            ])->all());

        $seccion('objetos.ver', fn () => $this->filtrar(ObjetoEncontrado::with('laboratorio'),
            ['descripcion', 'entregado_a', 'laboratorio.codigo'], $palabras, 'fecha_hallazgo')
            ->get()->map(fn ($o) => [
                'tipo' => 'Objeto', 'titulo' => mb_strimwidth($o->descripcion, 0, 70, '…'),
                'detalle' => "{$o->laboratorio?->codigo} · {$o->fecha_hallazgo?->format('d/m/Y')} · {$o->estado}",
                'ruta' => '/objetos?q='.urlencode(mb_substr($o->descripcion, 0, 40)),
            ])->all());

        $seccion('multas.ver', fn () => $this->filtrar(Multa::with('usuario')
            ->when(! $u->tienePermiso('multas.gestionar') && ! $u->tienePermiso('multas.ver_todas'), fn ($x) => $x->where('usuario_id', $u->id)),
            ['motivo', 'usuario.nombre', 'usuario.apellido'], $palabras, 'fecha')
            ->get()->map(fn ($m) => [
                'tipo' => 'Multa', 'titulo' => mb_strimwidth((string) $m->motivo, 0, 70, '…'),
                'detalle' => "{$m->usuario?->nombre_completo} · Bs {$m->monto} · {$m->estado}",
                'ruta' => '/multas?q='.urlencode(mb_substr((string) $m->motivo, 0, 40)),
            ])->all());

        $seccion('usuarios.ver', fn () => $this->filtrar(User::query(), ['codigo', 'nombre', 'apellido', 'email'], $palabras)
            ->get()->map(fn ($x) => [
                'tipo' => 'Usuario', 'titulo' => $x->nombre_completo,
                'detalle' => $x->email, 'ruta' => '/usuarios?q='.urlencode($x->email),
            ])->all());

        return response()->json($res);
    }

    /**
     * Aplica la búsqueda a una consulta: cada palabra debe estar en alguna columna (Y entre palabras, O entre columnas).
     * Columnas con punto buscan en una relación: 'equipo.laboratorio.codigo'.
     * Ordena primero los que empiezan con la primera palabra (en la primera columna) y luego por $recientes (desc).
     * Las columnas las escribe el programador (nunca vienen del usuario), por eso pueden ir dentro del SQL.
     */
    private function filtrar(Builder $query, array $columnas, array $palabras, ?string $recientes = null): Builder
    {
        $sql = fn (string $col) => "translate(CAST($col AS TEXT), 'ÁÉÍÓÚÜáéíóúü', 'AEIOUUaeiouu') ILIKE ?";
        foreach ($palabras as $p) {
            $patron = '%'.self::escaparLike($p).'%';
            $query->where(function (Builder $q) use ($query, $columnas, $patron, $sql) {
                foreach ($columnas as $c) {
                    if (str_contains($c, '.')) {
                        $rel = substr($c, 0, strrpos($c, '.'));
                        $col = substr($c, strrpos($c, '.') + 1);
                        $q->orWhereHas($rel, fn ($r) => $r->whereRaw($sql($col), [$patron]));
                    } else {
                        $q->orWhereRaw($sql($query->qualifyColumn($c)), [$patron]);
                    }
                }
            });
        }
        $primera = $query->qualifyColumn($columnas[0]);
        $query->orderByRaw("CASE WHEN {$sql($primera)} THEN 0 ELSE 1 END", [self::escaparLike($palabras[0]).'%']);
        if ($recientes) {
            $query->orderByDesc($query->qualifyColumn($recientes));
        }

        return $query->orderBy($query->qualifyColumn($columnas[0]))->limit(self::POR_TIPO);
    }

    /** "Informática" → "informatica" (para comparar sin tildes ni mayúsculas) */
    private static function sinTildes(string $t): string
    {
        return mb_strtolower(strtr($t, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']));
    }

    /** Para LIKE/ILIKE: % y _ se buscan como texto, no como comodines */
    private static function escaparLike(string $t): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $t);
    }

    /**
     * Contador de visitas persistente (pie de página). Público: también cuenta la página de login.
     * POST /api/visitas  { "ruta": "/reservas" }
     */
    public function visita(Request $request): JsonResponse
    {
        // Solo se cuentan páginas reales del sistema (las del menú + login/perfil), para que no se llene la tabla de basura
        $rutas = cache()->remember('rutas_validas', 300, fn () => DB::table('menus')->pluck('ruta')->merge(['/', '/login', '/registro', '/perfil'])->unique()->all());
        $d = $request->validate(['ruta' => ['required', 'string', 'max:150', Rule::in($rutas)]], ['ruta.in' => 'Página no registrada.']);
        $total = DB::selectOne('SELECT registrar_visita(?) AS total', [$d['ruta']])->total;

        return response()->json(['ruta' => $d['ruta'], 'visitas' => (int) $total]);
    }

    /** Listas cortas (id + etiqueta) para los <select> de los formularios */
    public function opciones(Request $request, string $tipo): JsonResponse
    {
        // Algunas listas exponen datos personales: solo para quien las necesita en sus formularios
        $requiere = [
            'usuarios' => ['planes.gestionar', 'usuarios.ver'],
            'roles' => ['usuarios.ver'],
            'auxiliares' => ['turnos.crear', 'asistencia.gestionar', 'permutas.solicitar'],
            'docentes' => ['horarios.ver'],
            'turnos' => ['asistencia.gestionar'],
        ];
        if (isset($requiere[$tipo]) && ! collect($requiere[$tipo])->contains(fn ($p) => $request->user()->tienePermiso($p))) {
            abort(403, 'No tiene permiso para consultar esta lista.');
        }

        $lista = match ($tipo) {
            'laboratorios' => Laboratorio::orderBy('codigo')->get()->map(fn ($l) => ['id' => $l->id, 'label' => "{$l->codigo} - {$l->nombre}", 'estado' => $l->estado]),
            'materias' => Materia::orderBy('sigla')->get()->map(fn ($m) => ['id' => $m->id, 'label' => "{$m->sigla} - {$m->nombre}"]),
            'docentes' => $this->usuariosConRol('DOC'),
            'auxiliares' => $this->usuariosConRol('AUX'),
            'usuarios' => User::where('estado', 'ACTIVO')->orderBy('nombre')->get()->map(fn ($u) => ['id' => $u->id, 'label' => "{$u->nombre_completo} ({$u->email})"]),
            'roles' => DB::table('roles')->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'label' => $r->nombre]),
            'semestres' => Semestre::orderByDesc('fecha_inicio')->get()->map(fn ($s) => ['id' => $s->id, 'label' => "{$s->gestion} ({$s->estado})"]),
            'equipos' => Equipo::with('laboratorio')->where('estado', '<>', 'BAJA')->orderBy('codigo')->get()
                ->map(fn ($e) => ['id' => $e->id, 'label' => "{$e->codigo} - {$e->descripcion} ({$e->laboratorio?->codigo})"]),
            // Las multas (MULTA-*) no se eligen a mano: las genera el sistema (CU9). Aquí solo los cobros voluntarios.
            'conceptos' => ConceptoPago::where('activo', true)->where('codigo', 'not like', 'MULTA-%')->orderBy('nombre')->get()->map(fn ($c) => ['id' => $c->id, 'label' => "{$c->nombre} - Bs {$c->monto}", 'monto' => $c->monto]),
            'turnos' => Turno::with('auxiliar')->whereHas('semestre', fn ($s) => $s->where('estado', 'ACTIVO'))->orderBy('dia_semana')->get()
                ->map(fn ($t) => ['id' => $t->id, 'label' => $t->auxiliar->nombre_completo.' · '.['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][$t->dia_semana].' '.substr($t->hora_inicio, 0, 5).'-'.substr($t->hora_fin, 0, 5)]),
            // CU5: los turnos del propio usuario en el semestre activo (para pedir una permuta)
            'mis_turnos' => Turno::with('laboratorio')->where('auxiliar_id', $request->user()->id)
                ->whereHas('semestre', fn ($s) => $s->where('estado', 'ACTIVO'))->orderBy('dia_semana')->orderBy('hora_inicio')->get()
                ->map(fn ($t) => ['id' => $t->id, 'dia_semana' => $t->dia_semana,
                    'label' => ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'][$t->dia_semana].' '.substr($t->hora_inicio, 0, 5).'-'.substr($t->hora_fin, 0, 5)
                        .' · '.($t->laboratorio?->codigo ?? 'Módulo')]),
            default => abort(404, 'Lista no disponible.'),
        };

        return response()->json($lista);
    }

    private function usuariosConRol(string $codigo)
    {
        return User::whereHas('rol', fn ($r) => $r->where('codigo', $codigo))->where('estado', 'ACTIVO')
            ->orderBy('apellido')->get()->map(fn ($u) => ['id' => $u->id, 'label' => "{$u->apellido} {$u->nombre}"]);
    }
}
