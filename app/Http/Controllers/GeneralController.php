<?php

namespace App\Http\Controllers;

use App\Models\ConceptoPago;
use App\Models\Equipo;
use App\Models\Laboratorio;
use App\Models\Materia;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Funciones transversales: búsqueda global, contador de visitas y listas para los formularios.
 */
class GeneralController extends Controller
{
    /**
     * Búsqueda global (barra del encabezado). Solo busca en los recursos que el usuario puede ver.
     * GET /api/buscar?q=lab
     */
    public function buscar(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:50']]);
        $q = '%'.trim($request->q).'%';
        $u = $request->user();
        $res = [];

        if ($u->tienePermiso('laboratorios.ver')) {
            foreach (Laboratorio::where('codigo', 'ILIKE', $q)->orWhere('nombre', 'ILIKE', $q)->limit(5)->get() as $l) {
                $res[] = ['tipo' => 'Laboratorio', 'titulo' => "{$l->codigo} - {$l->nombre}", 'detalle' => "{$l->estado} · {$l->capacidad} equipos", 'ruta' => '/laboratorios?q='.urlencode($l->codigo)];
            }
        }
        if ($u->tienePermiso('equipos.ver')) {
            foreach (Equipo::with('laboratorio')->where('codigo', 'ILIKE', $q)->orWhere('descripcion', 'ILIKE', $q)->limit(5)->get() as $e) {
                $res[] = ['tipo' => 'Equipo', 'titulo' => "{$e->codigo} - {$e->descripcion}", 'detalle' => "{$e->laboratorio?->codigo} · {$e->estado}", 'ruta' => '/equipos?q='.urlencode($e->codigo)];
            }
        }
        if ($u->tienePermiso('horarios.ver')) {
            foreach (Materia::where('sigla', 'ILIKE', $q)->orWhere('nombre', 'ILIKE', $q)->limit(5)->get() as $m) {
                $res[] = ['tipo' => 'Materia', 'titulo' => "{$m->sigla} - {$m->nombre}", 'detalle' => 'Ver horario', 'ruta' => '/horarios?q='.urlencode($m->sigla)];
            }
        }
        if ($u->tienePermiso('reservas.ver')) {
            $reservas = Reserva::with('laboratorio')->where('motivo', 'ILIKE', $q)
                ->when(! $u->tienePermiso('reservas.ver_todas'), fn ($x) => $x->where('solicitante_id', $u->id))
                ->orderByDesc('fecha')->limit(5)->get();
            foreach ($reservas as $r) {
                $res[] = ['tipo' => 'Reserva', 'titulo' => $r->motivo, 'detalle' => "{$r->laboratorio->codigo} · {$r->fecha->format('d/m/Y')} · {$r->estado}", 'ruta' => '/reservas?q='.urlencode($r->motivo)];
            }
        }
        if ($u->tienePermiso('usuarios.ver')) {
            $usuarios = User::where(fn ($x) => $x->where('nombre', 'ILIKE', $q)->orWhere('apellido', 'ILIKE', $q)
                ->orWhere('email', 'ILIKE', $q)->orWhere('codigo', 'ILIKE', $q))->limit(5)->get();
            foreach ($usuarios as $x) {
                $res[] = ['tipo' => 'Usuario', 'titulo' => $x->nombre_completo, 'detalle' => $x->email, 'ruta' => '/usuarios?q='.urlencode($x->email)];
            }
        }

        return response()->json($res);
    }

    /**
     * Contador de visitas persistente (pie de página). Público: también cuenta la página de login.
     * POST /api/visitas  { "ruta": "/reservas" }
     */
    public function visita(Request $request): JsonResponse
    {
        $d = $request->validate(['ruta' => ['required', 'string', 'max:150', 'regex:/^\/[A-Za-z0-9\/_-]*$/']]);
        $total = DB::selectOne('SELECT registrar_visita(?) AS total', [$d['ruta']])->total;

        return response()->json(['ruta' => $d['ruta'], 'visitas' => (int) $total]);
    }

    /** Listas cortas (id + etiqueta) para los <select> de los formularios */
    public function opciones(Request $request, string $tipo): JsonResponse
    {
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
            'conceptos' => ConceptoPago::where('activo', true)->orderBy('nombre')->get()->map(fn ($c) => ['id' => $c->id, 'label' => "{$c->nombre} - Bs {$c->monto}", 'monto' => $c->monto]),
            'turnos' => Turno::with('auxiliar')->whereHas('semestre', fn ($s) => $s->where('estado', 'ACTIVO'))->orderBy('dia_semana')->get()
                ->map(fn ($t) => ['id' => $t->id, 'label' => $t->auxiliar->nombre_completo.' · '.['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'][$t->dia_semana].' '.substr($t->hora_inicio, 0, 5).'-'.substr($t->hora_fin, 0, 5)]),
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
