<?php

namespace App\Http\Controllers;

use App\Models\Horario;
use App\Models\Semestre;
use App\Models\User;
use App\Services\Disponibilidad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** CU3 - Carga horaria base (clases semanales en laboratorio) */
class HorarioController extends CrudController
{
    protected string $modelo = Horario::class;
    protected array $buscarEn = ['grupo', 'materia.sigla', 'materia.nombre', 'laboratorio.codigo', 'docente.nombre', 'docente.apellido'];
    protected array $con = ['materia', 'laboratorio', 'docente', 'semestre'];
    protected array $filtros = ['laboratorio_id', 'dia_semana', 'docente_id', 'semestre_id'];
    protected string $ordenarPor = 'dia_semana';
    protected string $direccion = 'asc';

    protected function consulta(Builder $query, Request $request): Builder
    {
        // Por defecto se muestra el horario del semestre activo
        if (! $request->filled('semestre_id') && ($activo = Semestre::activo())) {
            $query->where('semestre_id', $activo->id);
        }

        return $query->orderBy('hora_inicio');
    }

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'laboratorio_id' => ['required', 'integer', 'exists:laboratorios,id'],
            'materia_id' => ['required', 'integer', 'exists:materias,id'],
            'docente_id' => ['required', 'integer', 'exists:usuarios,id'],
            'grupo' => ['required', 'regex:/^[A-Za-z0-9]{1,5}$/'],
            'dia_semana' => ['required', 'integer', 'between:1,6'],
            'hora_inicio' => ['required', 'date_format:H:i', 'after_or_equal:07:00'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio', 'before_or_equal:22:00'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $semestre = Semestre::activo();
        abort_unless($semestre, 422, 'No hay un semestre activo. Active uno en Configuración > Semestres.');
        $datos['semestre_id'] = $registro?->semestre_id ?? $semestre->id;
        $datos['grupo'] = strtoupper($datos['grupo']);

        $docente = User::with('rol')->findOrFail($datos['docente_id']);
        abort_unless($docente->rol->codigo === 'DOC', 422, 'El usuario seleccionado no tiene el rol Docente.');

        $choque = Horario::with('materia')
            ->where('semestre_id', $datos['semestre_id'])
            ->where('dia_semana', $datos['dia_semana'])
            ->where('id', '<>', $registro?->id ?? 0)
            ->where('hora_inicio', '<', Disponibilidad::hms($datos['hora_fin']))
            ->where('hora_fin', '>', Disponibilidad::hms($datos['hora_inicio']))
            ->where(fn ($q) => $q->where('laboratorio_id', $datos['laboratorio_id'])->orWhere('docente_id', $datos['docente_id']))
            ->first();

        if ($choque) {
            $quien = $choque->laboratorio_id == $datos['laboratorio_id'] ? 'El laboratorio' : 'El docente';
            abort(422, "$quien ya tiene la clase {$choque->materia->sigla}-{$choque->grupo} de "
                .substr($choque->hora_inicio, 0, 5).' a '.substr($choque->hora_fin, 0, 5).' ese día.');
        }

        return $datos;
    }
}
