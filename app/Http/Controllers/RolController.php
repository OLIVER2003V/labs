<?php

namespace App\Http\Controllers;

use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Roles y MATRIZ DE ACCESO (qué permisos tiene cada rol).
 * El rol de sistema (Administrador) no se puede modificar ni eliminar, para no quedar sin acceso.
 */
class RolController extends Controller
{
    /** Roles con sus permisos + catálogo de permisos agrupado por recurso */
    public function matriz(): JsonResponse
    {
        $roles = Rol::withCount('usuarios')->with('permisos:id')->orderBy('id')->get()
            ->map(fn (Rol $r) => $r->only(['id', 'codigo', 'nombre', 'descripcion', 'es_sistema', 'usuarios_count'])
                + ['permisos' => $r->permisos->pluck('id')]);

        $permisos = Permiso::orderBy('recurso')->orderBy('id')->get()->groupBy('recurso');

        return response()->json(['roles' => $roles, 'permisos' => $permisos]);
    }

    public function guardarPermisos(Request $request, int $id): JsonResponse
    {
        $rol = Rol::findOrFail($id);
        abort_if($rol->es_sistema, 422, 'Los permisos del rol de sistema no se pueden modificar.');
        $d = $request->validate([
            'permisos' => ['present', 'array'],
            'permisos.*' => ['integer', 'exists:permisos,id'],
        ]);
        $rol->permisos()->sync($d['permisos']);

        return response()->json(['message' => "Permisos del rol {$rol->nombre} actualizados (".count($d['permisos']).').']);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->reglas(null));
        $d['codigo'] = strtoupper($d['codigo']);

        return response()->json(['message' => 'Rol creado.', 'data' => Rol::create($d)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rol = Rol::findOrFail($id);
        abort_if($rol->es_sistema, 422, 'El rol de sistema no se puede modificar.');
        $d = $request->validate($this->reglas($rol->id));
        $d['codigo'] = strtoupper($d['codigo']);
        $rol->update($d);

        return response()->json(['message' => 'Rol actualizado.', 'data' => $rol]);
    }

    public function destroy(int $id): JsonResponse
    {
        $rol = Rol::withCount('usuarios')->findOrFail($id);
        abort_if($rol->es_sistema, 422, 'El rol de sistema no se puede eliminar.');
        abort_if($rol->usuarios_count > 0, 422, "No se puede eliminar: hay {$rol->usuarios_count} usuario(s) con este rol.");
        $rol->delete();

        return response()->json(['message' => 'Rol eliminado.']);
    }

    private function reglas(?int $id): array
    {
        return [
            'codigo' => ['required', 'regex:/^[A-Za-z]{2,10}$/', Rule::unique('roles', 'codigo')->ignore($id)],
            'nombre' => ['required', 'string', 'min:3', 'max:60'],
            'descripcion' => ['nullable', 'string', 'max:200'],
        ];
    }
}
