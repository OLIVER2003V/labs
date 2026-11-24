<?php

namespace App\Http\Controllers;

use App\Models\Semestre;
use App\Models\Turno;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** CU7 - Turnos de los auxiliares */
class TurnoController extends CrudController
{
    protected string $modelo = Turno::class;
    protected array $buscarEn = ['auxiliar.nombre', 'auxiliar.apellido', 'laboratorio.codigo'];
    protected array $con = ['auxiliar', 'laboratorio', 'semestre'];
    protected array $filtros = ['auxiliar_id', 'dia_semana', 'semestre_id'];
    protected string $ordenarPor = 'dia_semana';
    protected string $direccion = 'asc';

    protected function consulta(Builder $query, Request $request): Builder
    {
        // Un auxiliar sin permiso de gestión solo ve sus propios turnos
        if (! $request->user()->tienePermiso('turnos.crear')) {
            $query->where('auxiliar_id', $request->user()->id);
        }

        return $query;
    }

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'auxiliar_id' => ['required', 'integer', 'exists:usuarios,id'],
            'laboratorio_id' => ['nullable', 'integer', 'exists:laboratorios,id'],
            'dia_semana' => ['required', 'integer', 'between:1,6'],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $semestre = Semestre::activo();
        abort_unless($semestre, 422, 'No hay un semestre activo.');
        $datos['semestre_id'] = $semestre->id;

        $aux = User::with('rol')->findOrFail($datos['auxiliar_id']);
        abort_unless($aux->rol->codigo === 'AUX', 422, 'El usuario seleccionado no es auxiliar.');

        $choca = Turno::where('semestre_id', $semestre->id)
            ->where('auxiliar_id', $datos['auxiliar_id'])
            ->where('dia_semana', $datos['dia_semana'])
            ->where('id', '<>', $registro?->id ?? 0)
            ->where('hora_inicio', '<', $datos['hora_fin'])
            ->where('hora_fin', '>', $datos['hora_inicio'])
            ->exists();
        abort_if($choca, 422, 'El auxiliar ya tiene un turno que se superpone en ese día y horario.');

        return $datos;
    }
}
