<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** CU1 - Gestión de usuarios */
class UsuarioController extends CrudController
{
    protected string $modelo = User::class;
    protected array $buscarEn = ['codigo', 'nombre', 'apellido', 'email', 'rol.nombre'];
    protected array $con = ['rol'];
    protected array $filtros = ['rol_id', 'estado'];

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

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $datos['email'] = strtolower($datos['email']);
        if (empty($datos['password'])) {
            unset($datos['password']);
        }
        if ($registro && $registro->id === $request->user()->id && $datos['estado'] !== 'ACTIVO') {
            abort(422, 'No puede desactivar su propia cuenta.');
        }

        return $datos;
    }

    protected function antesDeEliminar(Model $registro, Request $request): void
    {
        if ($registro->id === $request->user()->id) {
            abort(422, 'No puede eliminar su propia cuenta.');
        }
        $registro->tokens()->delete();
    }
}
