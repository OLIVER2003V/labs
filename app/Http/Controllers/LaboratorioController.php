<?php

namespace App\Http\Controllers;

use App\Models\Laboratorio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU2 - Laboratorios */
class LaboratorioController extends CrudController
{
    protected string $modelo = Laboratorio::class;
    protected array $buscarEn = ['codigo', 'nombre', 'ubicacion', 'estado'];
    protected array $filtros = ['estado'];
    protected string $ordenarPor = 'codigo';
    protected string $direccion = 'asc';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'codigo' => ['required', 'regex:/^[A-Za-z0-9-]{2,10}$/', Rule::unique('laboratorios', 'codigo')->ignore($registro?->id)],
            'nombre' => ['required', 'string', 'min:3', 'max:60'],
            'capacidad' => ['required', 'integer', 'between:1,200'],
            'ubicacion' => ['nullable', 'string', 'max:100'],
            'estado' => ['required', Rule::in(['DISPONIBLE', 'MANTENIMIENTO', 'INHABILITADO'])],
            'tarifa_hora' => ['required', 'numeric', 'between:0,10000'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $datos['codigo'] = strtoupper($datos['codigo']);

        return $datos;
    }
}
