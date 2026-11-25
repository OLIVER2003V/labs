<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controlador base para los CRUD (crear, listar, ver, actualizar, eliminar).
 * Cada módulo hereda de aquí y solo define: el modelo, las reglas de validación,
 * las columnas de búsqueda y (si hace falta) los "ganchos" antesDeGuardar / consulta.
 *
 * Endpoints REST que genera (ver routes/api.php):
 *   GET    /api/recurso          index   → lista paginada (?q=texto&page=1&per_page=10&estado=...)
 *   GET    /api/recurso/{id}     show
 *   POST   /api/recurso          store
 *   PUT    /api/recurso/{id}     update
 *   DELETE /api/recurso/{id}     destroy
 */
abstract class CrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $modelo;

    /** Columnas donde busca el parámetro ?q= */
    protected array $buscarEn = [];

    /** Relaciones que se cargan junto al registro */
    protected array $con = [];

    /** Filtros exactos permitidos por query string, ej: ?estado=ACTIVO */
    protected array $filtros = [];

    protected string $ordenarPor = 'id';

    protected string $direccion = 'desc';

    /** Mensaje de respuesta personalizado: un gancho puede cambiarlo (ej: "Reserva aprobada…") */
    protected ?string $mensaje = null;

    /** Nombres legibles de los campos para los mensajes de error (ej: ['red_ip' => 'IP']) */
    protected function atributos(): array
    {
        return [];
    }

    /** Reglas de validación. $registro es null al crear. */
    abstract protected function reglas(Request $request, ?Model $registro): array;

    /** Permite a cada módulo restringir la consulta (ej: "solo mis reservas") */
    protected function consulta(Builder $query, Request $request): Builder
    {
        return $query;
    }

    /** Permite completar/ajustar los datos antes de guardar */
    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        return $datos;
    }

    /** Reglas de negocio antes de eliminar (lanzar abort(4xx, 'mensaje') si no se puede) */
    protected function antesDeEliminar(Model $registro, Request $request): void {}

    /** Acciones después de guardar (ej: enviar notificaciones). $nuevo = true al crear */
    protected function despuesDeGuardar(Model $registro, Request $request, bool $nuevo): void {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->consulta(
            ($this->modelo)::query()->with($this->con)->orderBy($this->ordenarPor, $this->direccion),
            $request
        );

        if ($texto = trim((string) $request->query('q', ''))) {
            $query->where(function (Builder $q) use ($texto) {
                foreach ($this->buscarEn as $columna) {
                    if (str_contains($columna, '.')) {           // relación.columna
                        [$rel, $col] = explode('.', $columna, 2);
                        $q->orWhereHas($rel, fn ($r) => $r->whereRaw("CAST($col AS TEXT) ILIKE ?", ["%$texto%"]));
                    } else {
                        $q->orWhereRaw("CAST($columna AS TEXT) ILIKE ?", ["%$texto%"]);
                    }
                }
            });
        }

        foreach ($this->filtros as $filtro) {
            if ($request->filled($filtro)) {
                $query->where($filtro, $request->query($filtro));
            }
        }

        $porPagina = $this->porPagina($request);

        return response()->json(
            $query->orderBy('id', 'desc')->paginate($porPagina)
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $registro = $this->consulta(($this->modelo)::query()->with($this->con), $request)->findOrFail($id);

        return response()->json($registro);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate($this->reglas($request, null), [], $this->atributos());
        // Todo en una transacción: si algo falla (ej: un trigger), se deshace también lo que hicieron los ganchos
        $registro = DB::transaction(function () use ($datos, $request) {
            $datos = $this->antesDeGuardar($datos, $request, null);
            $registro = ($this->modelo)::create($datos);
            $this->despuesDeGuardar($registro, $request, true);

            return $registro;
        });

        return response()->json([
            'message' => $this->mensaje ?? 'Registro creado correctamente.',
            'data' => $registro->load($this->con),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $registro = $this->consulta(($this->modelo)::query(), $request)->findOrFail($id);
        $datos = $request->validate($this->reglas($request, $registro), [], $this->atributos());
        DB::transaction(function () use ($datos, $request, $registro) {
            $datos = $this->antesDeGuardar($datos, $request, $registro);
            $registro->update($datos);
            $this->despuesDeGuardar($registro, $request, false);
        });

        return response()->json([
            'message' => $this->mensaje ?? 'Registro actualizado correctamente.',
            'data' => $registro->fresh($this->con),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $registro = $this->consulta(($this->modelo)::query(), $request)->findOrFail($id);
        $this->antesDeEliminar($registro, $request);
        $registro->delete();

        return response()->json(['message' => 'Registro eliminado correctamente.']);
    }
}
