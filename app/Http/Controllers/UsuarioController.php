<?php

namespace App\Http\Controllers;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * CU1 - Gestión de usuarios.
 * Seguridad: solo quien puede administrar roles (roles.editar = Administrador) puede
 * crear, modificar o eliminar usuarios con un rol de sistema. Así el Encargado no puede
 * crearse una cuenta de Administrador ni desactivar al Administrador (escalada de privilegios).
 */
class UsuarioController extends CrudController
{
    protected string $modelo = User::class;
    protected array $buscarEn = ['codigo', 'nombre', 'apellido', 'email', 'rol.nombre'];
    protected array $con = ['rol'];
    protected array $filtros = ['rol_id', 'estado'];

    protected function consulta(Builder $query, Request $request): Builder
    {
        // ?eliminados=1 → usuarios dados de baja con borrado lógico (para restaurarlos)
        return $request->boolean('eliminados') ? $query->onlyTrashed() : $query;
    }

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'codigo' => ['required', 'regex:/^[A-Za-z0-9-]{3,20}$/', Rule::unique('usuarios', 'codigo')->ignore($registro?->id)],
            'nombre' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'apellido' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[\pL\s]+$/u'],
            'email' => ['required', 'email', 'max:100', Rule::unique('usuarios', 'email')->ignore($registro?->id)],
            'telefono' => ['nullable', 'regex:/^[67]\d{7}$/'],
            'rol_id' => ['required', 'integer', 'exists:roles,id'],
            'estado' => ['required', Rule::in(['ACTIVO', 'INACTIVO'])],
            // obligatoria al crear, opcional al editar (si viene vacía no se cambia)
            'password' => [$registro ? 'nullable' : 'required', Password::min(8)->letters()->numbers()],
        ];
    }

    /** Bloquea tocar cuentas o roles de sistema sin el permiso roles.editar */
    private function protegerSistema(Request $request, ?Model $registro, ?int $rolNuevo = null): void
    {
        if ($request->user()->tienePermiso('roles.editar')) {
            return;
        }
        $sistema = Rol::where('es_sistema', true)->pluck('id')->all();
        abort_if($registro && in_array($registro->rol_id, $sistema), 403, 'Solo el Administrador puede modificar cuentas de Administrador.');
        abort_if($rolNuevo && in_array($rolNuevo, $sistema), 403, 'Solo el Administrador puede asignar el rol de Administrador.');
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $this->protegerSistema($request, $registro, (int) $datos['rol_id']);
        $datos['email'] = strtolower($datos['email']);
        if (empty($datos['password'])) {
            unset($datos['password']);
        }
        if ($registro && $registro->id === $request->user()->id && ($datos['estado'] !== 'ACTIVO' || $datos['rol_id'] != $registro->rol_id)) {
            abort(422, 'No puede desactivar ni cambiar el rol de su propia cuenta.');
        }

        return $datos;
    }

    protected function antesDeEliminar(Model $registro, Request $request): void
    {
        $this->protegerSistema($request, $registro);
        abort_if($registro->id === $request->user()->id, 422, 'No puede eliminar su propia cuenta.');
        $registro->tokens()->delete(); // cierra sus sesiones abiertas
    }

    /** PUT /api/usuarios/{id}/restaurar  → deshace el borrado lógico */
    public function restaurar(Request $request, int $id): JsonResponse
    {
        $usuario = User::onlyTrashed()->findOrFail($id);
        $this->protegerSistema($request, $usuario);
        $usuario->restore();

        return response()->json(['message' => "Usuario {$usuario->nombre_completo} restaurado.", 'data' => $usuario->load('rol')]);
    }
}
