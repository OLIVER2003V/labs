<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU6 - Inventario de equipos y software */
class EquipoController extends CrudController
{
    protected string $modelo = Equipo::class;
    protected array $buscarEn = ['codigo', 'activo_fijo', 'descripcion', 'tipo', 'estado', 'laboratorio.codigo'];
    protected array $con = ['laboratorio'];
    protected array $filtros = ['laboratorio_id', 'tipo', 'estado'];
    protected string $ordenarPor = 'codigo';
    protected string $direccion = 'asc';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'codigo' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ,.\/-]{1,19}$/', Rule::unique('equipos', 'codigo')->ignore($registro?->id)],
            'activo_fijo' => ['nullable', 'regex:/^[A-Za-z0-9-]{3,30}$/'],
            'laboratorio_id' => ['required', 'integer', 'exists:laboratorios,id'],
            'tipo' => ['required', Rule::in(['COMPUTADORA', 'PROYECTOR', 'COMPONENTE', 'SOFTWARE', 'OTRO'])],
            'descripcion' => ['required', 'string', 'min:3', 'max:200'],
            'estado' => ['required', Rule::in(['OPERATIVO', 'EN_REPARACION', 'BAJA'])],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $datos['codigo'] = strtoupper($datos['codigo']);

        return $datos;
    }
}
