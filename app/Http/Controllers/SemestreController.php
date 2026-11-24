<?php

namespace App\Http\Controllers;

use App\Models\Semestre;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** CU2 - Configuración semestral */
class SemestreController extends CrudController
{
    protected string $modelo = Semestre::class;
    protected array $buscarEn = ['gestion', 'estado'];
    protected array $filtros = ['estado'];
    protected string $ordenarPor = 'fecha_inicio';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'gestion' => ['required', 'regex:/^[1-4]-20\d{2}$/', Rule::unique('semestres', 'gestion')->ignore($registro?->id)],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after:fecha_inicio'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $choca = Semestre::where('id', '<>', $registro?->id ?? 0)
            ->where('fecha_inicio', '<=', $datos['fecha_fin'])
            ->where('fecha_fin', '>=', $datos['fecha_inicio'])
            ->exists();
        abort_if($choca, 422, 'Las fechas se superponen con otro semestre.');

        return $datos;
    }

    protected function antesDeEliminar(Model $registro, Request $request): void
    {
        abort_if($registro->estado === 'ACTIVO', 422, 'No puede eliminar el semestre activo. Ciérrelo primero.');
    }

    public function activar(int $id): JsonResponse
    {
        $semestre = Semestre::findOrFail($id);
        abort_if($semestre->estado === 'CERRADO', 422, 'Un semestre cerrado no puede volver a activarse.');
        DB::transaction(function () use ($semestre) {
            Semestre::where('estado', 'ACTIVO')->update(['estado' => 'CERRADO']);
            $semestre->update(['estado' => 'ACTIVO']);
        });

        return response()->json(['message' => "Semestre {$semestre->gestion} activado."]);
    }

    public function cerrar(int $id): JsonResponse
    {
        $semestre = Semestre::findOrFail($id);
        $semestre->update(['estado' => 'CERRADO']);

        return response()->json(['message' => "Semestre {$semestre->gestion} cerrado."]);
    }
}
