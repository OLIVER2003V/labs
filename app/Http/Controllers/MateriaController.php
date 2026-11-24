<?php

namespace App\Http\Controllers;

use App\Models\Materia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU3 - Materias */
class MateriaController extends CrudController
{
    protected string $modelo = Materia::class;
    protected array $buscarEn = ['sigla', 'nombre'];
    protected string $ordenarPor = 'sigla';
    protected string $direccion = 'asc';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'sigla' => ['required', 'regex:/^[A-Za-z]{3}[0-9]{3}$/', Rule::unique('materias', 'sigla')->ignore($registro?->id)],
            'nombre' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $datos['sigla'] = strtoupper($datos['sigla']);

        return $datos;
    }
}
