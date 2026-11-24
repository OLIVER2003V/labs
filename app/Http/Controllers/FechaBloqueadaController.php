<?php

namespace App\Http\Controllers;

use App\Models\FechaBloqueada;
use App\Models\Semestre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU2 - Fechas bloqueadas (feriados, PSA, mantenimiento) */
class FechaBloqueadaController extends CrudController
{
    protected string $modelo = FechaBloqueada::class;
    protected array $buscarEn = ['tipo', 'motivo', 'laboratorio.codigo'];
    protected array $con = ['semestre', 'laboratorio'];
    protected array $filtros = ['semestre_id', 'tipo'];
    protected string $ordenarPor = 'fecha';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'semestre_id' => ['required', 'integer', 'exists:semestres,id'],
            'fecha' => ['required', 'date'],
            'tipo' => ['required', Rule::in(['FERIADO', 'PSA', 'MANTENIMIENTO', 'OTRO'])],
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
            'laboratorio_id' => ['nullable', 'integer', 'exists:laboratorios,id'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $sem = Semestre::findOrFail($datos['semestre_id']);
        $fuera = $datos['fecha'] < $sem->fecha_inicio->format('Y-m-d') || $datos['fecha'] > $sem->fecha_fin->format('Y-m-d');
        abort_if($fuera, 422, 'La fecha debe estar dentro del semestre seleccionado.');

        return $datos;
    }
}
